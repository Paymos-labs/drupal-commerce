<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos;

use Drupal\Core\DependencyInjection\{ContainerBuilder, ServiceProviderBase};

/** Composer owns the dependency; manual-install ZIPs carry the same SDK. */
final class CommercePaymosServiceProvider extends ServiceProviderBase {
  public function register(ContainerBuilder $container) {
    SdkBootstrap::register();
  }
}
