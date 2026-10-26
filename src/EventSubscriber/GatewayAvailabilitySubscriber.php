<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\EventSubscriber;

use Drupal\commerce_payment\Event\{FilterPaymentGatewaysEvent, PaymentEvents};
use Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Filters through Commerce's actual checkout availability extension point. */
final class GatewayAvailabilitySubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents(): array {
    return [PaymentEvents::FILTER_PAYMENT_GATEWAYS => 'filter'];
  }

  public function filter(FilterPaymentGatewaysEvent $event): void {
    $gateways = $event->getPaymentGateways();
    foreach ($gateways as $id => $gateway) {
      $plugin = $gateway->getPlugin();
      if ($plugin instanceof Paymos && !$plugin->isConnected()) {
        unset($gateways[$id]);
      }
    }
    $event->setPaymentGateways($gateways);
  }
}
