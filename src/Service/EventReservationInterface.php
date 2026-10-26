<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;
use Paymos\Webhook\CommitAwareEventStoreInterface;
interface EventReservationInterface extends CommitAwareEventStoreInterface {
  public function releaseAll(): void;
}
