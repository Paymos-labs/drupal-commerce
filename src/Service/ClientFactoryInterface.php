<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;
use Paymos\Client;
interface ClientFactoryInterface {
  public function configuration(string $environment): array;
  public function create(string $environment): Client;
}
