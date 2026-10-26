<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Paymos\IdempotencyKey;
use Paymos\Plugin\{AmountGuard, InvoiceReplacement, InvoiceRenewal};

/** Native order glue; HTTP signing/retries/replacement rules belong to the SDK. */
final class CheckoutService {
  public function __construct(private PaymentStoreInterface $store, private ClientFactoryInterface $clients) {}

  public function start(string $orderId, string $gatewayId, string $environment): string {
    return $this->store->synchronized($orderId, function () use ($orderId, $gatewayId, $environment): string {
      $config = $this->clients->configuration($environment);
      [$record, $snapshot] = $this->store->transaction($orderId, function () use ($orderId, $gatewayId, $environment, $config): array {
        $snapshot = $this->store->snapshot($orderId);
        $this->assertOrder($snapshot, $gatewayId);
        $record = $this->store->load($orderId);
        if ($record === NULL) {
          $record = $this->newIntent($snapshot, $environment, $config['project_id']);
          $this->store->save($record);
        }
        if (!empty($record['credited'])) { throw new \RuntimeException('paymos_already_paid'); }
        return [$record, $snapshot];
      }, TRUE);
      // The durable transaction has committed BEFORE any request is sent.
      $same = $this->sameSnapshot($record, $snapshot, $environment, $config['project_id']);
      $invoiceId = $record['invoice_id'] ?? '';
      $renew = FALSE;
      if ($invoiceId !== '') {
        $oldConfig = $this->clients->configuration($record['environment']);
        if ($oldConfig['project_id'] !== $record['project_id']) {
          throw new \RuntimeException('paymos_original_project_unavailable');
        }
        $remote = $this->clients->create($record['environment'])->invoices()->get($invoiceId);
        if (($remote['invoice_id'] ?? '') !== $invoiceId) {
          throw new \RuntimeException('paymos_invoice_lookup_mismatch');
        }
        $renew = InvoiceRenewal::isRequired($remote);
        $record['status'] = $remote['status'] ?? $record['status'];
      }
      if (!$same || $renew) {
        // No ID after an unknown outcome is NOT permission to mint a new intent.
        if ($invoiceId === '' || !(new InvoiceReplacement($this->clients->create($record['environment'])))
          ->close($invoiceId, $record['status'])->isClosed()) {
          throw new \RuntimeException('paymos_previous_invoice_unresolved');
        }
        $previousIntent = $record['external_order_id'];
        $record = $this->store->transaction($orderId, function () use ($orderId, $gatewayId, $environment, $config, $previousIntent, $invoiceId): array {
          $current = $this->store->load($orderId);
          if ($current === NULL || $current['external_order_id'] !== $previousIntent
            || ($current['invoice_id'] ?? '') !== $invoiceId || !empty($current['credited'])) {
            // A slow API request may outlive the fast lease. The SQL-locked
            // compare-and-set must not overwrite the other worker's intent.
            throw new PaymentBusyException('paymos_renewal_already_claimed');
          }
          $snapshot = $this->store->snapshot($orderId);
          $this->assertOrder($snapshot, $gatewayId);
          $record = $this->newIntent($snapshot, $environment, $config['project_id']);
          $this->store->save($record);
          return $record;
        }, TRUE);
      }
      elseif ($invoiceId !== '' && self::trustedUrl($record['payment_url'] ?? '')) {
        return $record['payment_url'];
      }
      $payload = ['project_id'=>$record['project_id'], 'external_order_id'=>$record['external_order_id'],
        'amount'=>$record['amount'], 'currency'=>$record['currency']];
      $invoice = $this->clients->create($record['environment'])->invoices()->create($payload);
      if (!isset($invoice['invoice_id']) || !is_string($invoice['invoice_id']) || $invoice['invoice_id'] === ''
        || !self::trustedUrl($invoice['payment_url'] ?? NULL)) {
        throw new \RuntimeException('paymos_invalid_invoice_response');
      }
      $this->store->transaction($orderId, function () use ($orderId, $record, $invoice): void {
        $current = $this->store->load($orderId);
        if ($current === NULL || $current['external_order_id'] !== $record['external_order_id']
          || (!empty($current['invoice_id']) && $current['invoice_id'] !== $invoice['invoice_id'])) {
          throw new \RuntimeException('paymos_intent_changed');
        }
        $current['invoice_id'] = $invoice['invoice_id'];
        $current['payment_url'] = $invoice['payment_url'];
        $current['status'] = is_string($invoice['status'] ?? NULL) ? $invoice['status'] : '';
        $this->store->save($current);
      });
      return $invoice['payment_url'];
    });
  }

  private function assertOrder(array $order, string $gatewayId): void {
    $amount = AmountGuard::normalizeDecimal($order['amount'] ?? '');
    if ($amount === NULL || $amount === '0' || $amount[0] === '-' || empty($order['currency'])
      || ($order['gateway_id'] ?? '') !== $gatewayId || !empty($order['paid']) || !empty($order['cancelled'])) {
      throw new \RuntimeException('paymos_order_not_payable');
    }
  }

  private function newIntent(array $order, string $environment, string $project): array {
    return ['order_id'=>$order['order_id'], 'gateway_id'=>$order['gateway_id'], 'environment'=>$environment,
      'project_id'=>$project, 'external_order_id'=>IdempotencyKey::externalOrderId('dc'),
      'amount'=>$order['amount'], 'currency'=>$order['currency'], 'invoice_id'=>'', 'payment_url'=>'',
      'status'=>'', 'credited'=>FALSE, 'late_noted'=>FALSE];
  }

  private function sameSnapshot(array $record, array $order, string $environment, string $project): bool {
    return $record['environment'] === $environment && $record['project_id'] === $project
      && $record['gateway_id'] === $order['gateway_id'] && $record['currency'] === $order['currency']
      && AmountGuard::amountsEqual($record['amount'], $order['amount']);
  }

  public static function trustedUrl(mixed $url): bool {
    if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) { return FALSE; }
    $p = parse_url($url);
    return is_array($p) && strtolower($p['scheme'] ?? '') === 'https'
      && in_array(strtolower($p['host'] ?? ''), ['checkout.paymos.io', 'checkout-local.paymos.io'], TRUE)
      && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || $p['port'] === 443);
  }
}
