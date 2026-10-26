<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos;

/** Supports the same SDK in Composer and dependency-complete manual installs. */
final class SdkBootstrap {
  public static function register(): void {
    if (!class_exists(\Paymos\Client::class)) {
      $bundled = dirname(__DIR__) . '/vendor/autoload.php';
      if (!is_file($bundled)) {
        throw new \RuntimeException('Paymos PHP SDK is missing; install the Composer dependency or the complete release ZIP.');
      }
      require_once $bundled;
    }
  }

  public static function deviceClient(): \Paymos\Connect\DeviceConnectClient {
    self::register();
    return new \Paymos\Connect\DeviceConnectClient();
  }
}
