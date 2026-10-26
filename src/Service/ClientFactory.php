<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\Service;

use Paymos\Client;
use Paymos\ClientConfig;

final class ClientFactory implements ClientFactoryInterface {
  public function __construct(private ConnectionStore $connection) {}

  public function configuration(string $environment): array {
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) {
      throw new \InvalidArgumentException('paymos_invalid_environment');
    }
    $credentials = $this->connection->credentials();
    if (!isset($credentials[$environment])) {
      throw new \RuntimeException('paymos_not_connected');
    }
    return $credentials[$environment];
  }

  public function create(string $environment): Client {
    $config = $this->configuration($environment);
    return new Client(new ClientConfig($config['api_key'], $config['api_secret'], $config['base_url'], 15));
  }
}
