<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;
interface PaymentStoreInterface {
  public function synchronized(string $orderId, callable $action);
  public function transaction(string $orderId, callable $action, bool $durable = FALSE);
  public function snapshot(string $orderId): array;
  public function load(string $orderId): ?array;
  public function byInvoice(string $invoiceId): ?array;
  public function save(array $record): void;
  public function isCommitted(string $eventId): bool;
  public function commitEvent(string $eventId): void;
  public function complete(array $record, array $invoice): void;
  public function note(string $orderId, string $code): void;
  public function pending(int $since, int $limit): array;
  public function markChecked(string $orderId): void;
}
