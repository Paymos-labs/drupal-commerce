<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Paymos\Plugin\AmountGuard;

final class RefundPaymentGuard {
  public static function assertSave(PaymentInterface $payment): void {
    // Read persisted values so changing the gateway or original cannot hide a refund.
    $original = $payment->isNew() ? NULL : \Drupal::entityTypeManager()
      ->getStorage('commerce_payment')->loadUnchanged($payment->id());
    if (!self::isPaymos($payment) && (!$original || !self::isPaymos($original))) { return; }
    if (RefundNativeGateway::isWritingPayment($payment)) { return; }

    $state = $payment->getState()->getId();
    $previousState = $original ? $original->getState()->getId() : NULL;
    if ((!$payment->isNew() && !$original)
      || (in_array($state,['refunded','partially_refunded'],TRUE) && $state !== $previousState)
      || !self::sameAmount($original ? $original->getRefundedAmount() : NULL,$payment->getRefundedAmount())) {
      throw new PaymentGatewayException('TODO-COPY:refund-protected-action-required');
    }
  }

  private static function isPaymos(PaymentInterface $payment): bool {
    $gateway = $payment->getPaymentGateway();
    return $gateway && $gateway->getPluginId() === 'paymos';
  }

  private static function sameAmount(?Price $previous, ?Price $current): bool {
    if ($previous === NULL) {
      return $current === NULL || AmountGuard::amountsEqual($current->getNumber(),'0');
    }
    return $current !== NULL && $previous->getCurrencyCode() === $current->getCurrencyCode()
      && AmountGuard::amountsEqual($previous->getNumber(),$current->getNumber());
  }
}
