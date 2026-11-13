<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Paymos\Exception\{DuplicateEventException, EventInProgressException, SignatureMismatchException, TimestampSkewException};
use Paymos\Plugin\{AmountGuard, InvoiceReverseVerifier, StatusMapper};
use Paymos\Webhook\{MultiEnvironmentWebhookVerifier, WebhookEvent};

/** No native payment mutation outside the order transaction and SDK guards. */
final class PaymentProcessor {
  private $store;
  private $clients;
  private $events;
  private $refunds;
  public function __construct(PaymentStoreInterface $store, ClientFactoryInterface $clients,
    EventReservationInterface $events, ?RefundAdapter $refunds = NULL) {
    $this->store = $store; $this->clients = $clients; $this->events = $events; $this->refunds = $refunds;
  }

  public function handle(string $body, string $signature, ?int $now = NULL): int {
    try {
      $secrets = [];
      foreach (['sandbox', 'live'] as $environment) {
        $secrets[$environment] = $this->clients->configuration($environment)['webhook_secret'];
      }
      $match = (new MultiEnvironmentWebhookVerifier($secrets, $this->events))->process($signature, $body, $now);
      $event = $match->event();
      if (strpos($event->type(),'withdrawal.') === 0 && $this->refunds !== NULL) {
        $data = $event->toArray()['data'];
        if (!empty($data['invoice_id'])) { $this->refunds->callback($data,$match->environment()); }
        return 200;
      }
      if (!$event->isInvoiceEvent()) { return 200; }
      $record = $this->store->byInvoice($event->invoiceId()) ?? $this->store->byPreviousInvoice($event->invoiceId());
      if ($record === NULL) { return 409; } // A callback may beat the create response.
      return $this->apply($record, $match->environment(), $event);
    }
    catch (DuplicateEventException $e) { return 200; }
    catch (SignatureMismatchException|TimestampSkewException $e) { return 401; }
    catch (EventInProgressException|PaymentBusyException $e) { return 409; }
    catch (\InvalidArgumentException|\JsonException $e) { return 400; }
    catch (\Throwable $e) { return 500; }
    finally { $this->events->releaseAll(); }
  }

  /** Reconciliation starts with trusted API evidence, never a forged webhook signature. */
  public function reconcile(array $record): bool {
    $config = $this->clients->configuration($record['environment']);
    if ($config['project_id'] !== $record['project_id'] || empty($record['invoice_id'])) { return FALSE; }
    $results = (new \Paymos\Plugin\InvoiceReconciler($this->clients->create($record['environment'])))
      ->reconcile([$record['invoice_id']]);
    if (!$results || $results[0]->invoiceId() !== $record['invoice_id']) { return FALSE; }
    $invoice = $results[0]->invoice();
    $status = $results[0]->status();
    if ($status === '' || $status === 'awaiting_client') { return FALSE; }
    $type = 'invoice.' . $status;
    if (!empty($invoice['paid_late_at']) && empty($record['late_noted'])) { $type = 'invoice.paid_late'; }
    $event = new WebhookEvent(['event_id'=>'reconcile_' . hash('sha256',$record['invoice_id'] . ':' . $type),
      'event_type'=>$type, 'version'=>1, 'data'=>$invoice]);
    // apply() performs the same order lock, reverse verification and amount checks.
    return $this->apply($record, $record['environment'], $event, TRUE) === 200;
  }

  private function apply(array $known, string $environment, WebhookEvent $event, bool $reconcile = FALSE): int {
    return $this->store->synchronized($known['order_id'], function () use ($known, $environment, $event, $reconcile): int {
      return $this->store->transaction($known['order_id'], function () use ($known, $environment, $event, $reconcile): int {
        $current = $this->store->load($known['order_id']);
        $historicalRecord = $this->store->byInvoice($event->invoiceId());
        if ($current !== NULL && ($current['invoice_id'] ?? '') !== $event->invoiceId()
          && (isset($current['history'][$event->invoiceId()])
            || ($historicalRecord !== NULL && ($historicalRecord['invoice_id'] ?? '') === $event->invoiceId()))) {
          return $this->applyPrevious($current, $historicalRecord, $environment, $event);
        }
        $record = $current;
        if ($record === NULL || ($record['invoice_id'] ?? '') !== $event->invoiceId()
          || $record['environment'] !== $environment || $record['project_id'] !== $event->projectId()
          || $record['external_order_id'] !== $event->externalOrderId()) { return 409; }
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        $late = $action === StatusMapper::ACTION_NOTE_PAID_LATE;
        $committed = $this->store->isCommitted($event->id());
        // Old versions committed synthetic paid events even when no native payment existed.
        if ($committed && !($reconcile && !empty($record['review']) && empty($record['credited'])
          && $action === StatusMapper::ACTION_PAYMENT_COMPLETE)) { return 200; }
        if (!empty($record['credited'])) {
          $this->store->commitEvent($event->id());
          return 200;
        }
        if (!empty($record['review']) && in_array($record['status'], ['paid','paid_over'], TRUE)
          && !$late && $action !== StatusMapper::ACTION_PAYMENT_COMPLETE) { return 200; }
        if (!$late && in_array($record['status'], ['underpaid','expired','cancelled'], TRUE)) {
          $this->store->commitEvent($event->id());
          return 200;
        }
        if (in_array($action, [StatusMapper::ACTION_PAYMENT_COMPLETE, StatusMapper::ACTION_FAIL_ORDER,
          StatusMapper::ACTION_CANCEL_ORDER, StatusMapper::ACTION_NOTE_PAID_LATE], TRUE)) {
          $config = $this->clients->configuration($environment);
          if ($config['project_id'] !== $record['project_id']) { return 409; }
          $result = (new InvoiceReverseVerifier($this->clients->create($environment)))->verify($event, $record);
          if (!$result->isVerified()) { return 409; }
          $invoice = $result->invoice();
        }
        else { $invoice = $event->toArray()['data']; }
        $order = $this->store->snapshot($record['order_id']);
        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
          $safe = !$order['paid'] && !$order['cancelled'] && $order['gateway_id'] === $record['gateway_id']
            && AmountGuard::isSafeToComplete($record['amount'], $record['currency'], $order['amount'],
              $order['currency'], $event->orderAmount(), $event->orderCurrency());
          if (!$safe) {
            if (empty($record['review'])) { $this->store->note($record['order_id'], 'paymos_manual_review'); }
            $record['review'] = TRUE;
          }
          else {
            $this->store->complete($record, $invoice);
            $record['credited'] = TRUE;
            $record['review'] = FALSE;
          }
        }
        elseif ($late && empty($record['late_noted'])) {
          $this->store->note($record['order_id'], 'paymos_paid_late');
          $record['late_noted'] = TRUE;
        }
        if ($action !== StatusMapper::ACTION_IGNORE) { $record['status'] = $event->status(); }
        $this->store->save($record);
        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE && empty($record['credited'])) { return 409; }
        // Native payment + record + permanent dedup commit share one SQL transaction.
        if (!$committed) { $this->store->commitEvent($event->id()); }
        return 200;
      });
    });
  }

  /**
   * A verified event for an invoice this order replaced (48-01 + history table).
   * The current binding and native payment are never touched: a late payment
   * becomes one order note, status events of the closed invoice are acknowledged
   * without effect, and a completion is refused (a closed invoice cannot be paid).
   * Runs inside apply()'s order lock and transaction.
   */
  private function applyPrevious(array $current, ?array $historicalRecord, string $environment, WebhookEvent $event): int {
    $hasTableRow = $historicalRecord !== NULL && ($historicalRecord['invoice_id'] ?? '') === $event->invoiceId();
    $previous = $hasTableRow ? $historicalRecord : $current['history'][$event->invoiceId()];
    if ($previous['environment'] !== $environment || $previous['project_id'] !== $event->projectId()
      || $previous['external_order_id'] !== $event->externalOrderId()) { return 409; }
    $action = StatusMapper::invoiceAction($event->type(), $event->status());
    if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) { return 409; }
    if ($this->store->isCommitted($event->id())) { return 200; }
    $alreadyNoted = !empty($previous['late_noted']) || !empty($current['history'][$event->invoiceId()]['late_noted']);
    if ($action !== StatusMapper::ACTION_NOTE_PAID_LATE || $alreadyNoted) {
      $this->store->commitEvent($event->id());
      return 200;
    }
    $config = $this->clients->configuration($environment);
    if ($config['project_id'] !== $previous['project_id']) { return 409; }
    $result = (new InvoiceReverseVerifier($this->clients->create($environment)))->verify($event, $previous);
    if (!$result->isVerified()) { return 409; }
    $this->store->note($current['order_id'], 'paymos_paid_late');
    if ($hasTableRow) {
      $historicalRecord['late_noted'] = TRUE;
      $historicalRecord['status'] = $event->status();
      $this->store->saveHistory($historicalRecord);
    }
    else {
      $current['history'][$event->invoiceId()]['late_noted'] = TRUE;
      $this->store->save($current);
    }
    $this->store->commitEvent($event->id());
    return 200;
  }
}
