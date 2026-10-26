<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Paymos\Exception\{DuplicateEventException, EventInProgressException, SignatureMismatchException, TimestampSkewException};
use Paymos\Plugin\{AmountGuard, InvoiceReverseVerifier, StatusMapper};
use Paymos\Webhook\{MultiEnvironmentWebhookVerifier, WebhookEvent};

/** No native payment mutation outside the order transaction and SDK guards. */
final class PaymentProcessor {
  public function __construct(private PaymentStoreInterface $store, private ClientFactoryInterface $clients,
    private EventReservationInterface $events) {}

  public function handle(string $body, string $signature, ?int $now = NULL): int {
    try {
      $secrets = [];
      foreach (['sandbox', 'live'] as $environment) {
        $secrets[$environment] = $this->clients->configuration($environment)['webhook_secret'];
      }
      $match = (new MultiEnvironmentWebhookVerifier($secrets, $this->events))->process($signature, $body, $now);
      $event = $match->event();
      if (!$event->isInvoiceEvent()) { return 200; }
      $record = $this->store->byInvoice($event->invoiceId());
      if ($record === NULL) { return 409; } // A callback may beat the create response.
      return $this->apply($record, $match->environment(), $event);
    }
    catch (DuplicateEventException $e) { return 200; }
    catch (SignatureMismatchException|TimestampSkewException $e) { return 401; }
    catch (EventInProgressException|PaymentBusyException $e) { return 409; }
    catch (\InvalidArgumentException|\JsonException $e) { return 400; }
    catch (\Throwable $e) { return 503; }
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
    return $this->apply($record,$record['environment'],$event) === 200;
  }

  private function apply(array $known, string $environment, WebhookEvent $event): int {
    return $this->store->synchronized($known['order_id'], function () use ($known, $environment, $event): int {
      return $this->store->transaction($known['order_id'], function () use ($known, $environment, $event): int {
        if ($this->store->isCommitted($event->id())) { return 200; }
        $record = $this->store->load($known['order_id']);
        if ($record === NULL || ($record['invoice_id'] ?? '') !== $event->invoiceId()
          || $record['environment'] !== $environment || $record['project_id'] !== $event->projectId()
          || $record['external_order_id'] !== $event->externalOrderId()) { return 409; }
        if (!empty($record['credited'])) {
          $this->store->commitEvent($event->id());
          return 200;
        }
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        $late = $action === StatusMapper::ACTION_NOTE_PAID_LATE;
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
          }
        }
        elseif ($late && empty($record['late_noted'])) {
          $this->store->note($record['order_id'], 'paymos_paid_late');
          $record['late_noted'] = TRUE;
        }
        if ($action !== StatusMapper::ACTION_IGNORE) { $record['status'] = $event->status(); }
        $this->store->save($record);
        // Native payment + record + permanent dedup commit share one SQL transaction.
        $this->store->commitEvent($event->id());
        return 200;
      });
    });
  }
}
