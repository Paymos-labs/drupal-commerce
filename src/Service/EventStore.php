<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Drupal\Core\Lock\LockBackendInterface;

/** Fast reservation; durable dedup is committed with the native payment in SQL. */
final class EventStore implements EventReservationInterface {
  private array $held = [];
  public function __construct(private PaymentStoreInterface $store, private LockBackendInterface $locks) {}
  public function remember($eventId, $ttlSeconds) {
    if (!is_string($eventId) || $eventId === '' || strlen($eventId)>128) {
      throw new \InvalidArgumentException('paymos_invalid_event_id');
    }
    if ($this->isCommitted($eventId) || isset($this->held[$eventId])) { return FALSE; }
    $name = 'commerce_paymos:event:' . hash('sha256',$eventId);
    if (!$this->locks->acquire($name,300.0)) { return FALSE; }
    $this->held[$eventId] = $name;
    return !$this->isCommitted($eventId);
  }
  public function isCommitted($eventId) { return $this->store->isCommitted((string)$eventId); }
  public function releaseAll(): void {
    foreach ($this->held as $name) { $this->locks->release($name); }
    $this->held = [];
  }
}
