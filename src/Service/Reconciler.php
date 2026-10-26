<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Psr\Log\LoggerInterface;

final class Reconciler {
  public function __construct(private PaymentStoreInterface $store, private PaymentProcessor $processor,
    private LoggerInterface $logger) {}
  public function run(int $limit=10): int {
    $checked = 0;
    foreach ($this->store->pending(0,min(50,max(1,$limit))) as $record) {
      try {
        // Rotate the queue even for awaiting_client, errors and missing bindings.
        // Older unresolved invoices must not disappear after a day of downtime.
        $this->store->markChecked($record['order_id']);
        $this->processor->reconcile($record);
        $checked++;
      }
      catch (\Throwable $e) {
        // Do not log credentials, request bodies, or exception text from a remote API.
        $this->logger->warning('Paymos reconciliation failed for an unresolved invoice.');
      }
    }
    return $checked;
  }
}
