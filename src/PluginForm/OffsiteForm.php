<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos;

final class OffsiteForm extends PaymentOffsiteForm {
  public function buildConfigurationForm(array $form,FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form,$form_state);
    $payment = $this->entity;
    $gateway = $payment->getPaymentGateway();
    $plugin = $gateway->getPlugin();
    if (!$plugin instanceof Paymos) { throw new \LogicException('paymos_wrong_gateway'); }
    try {
      $url = \Drupal::service('commerce_paymos.checkout')->start((string)$payment->getOrderId(),
        (string)$gateway->id(),$plugin->getEnvironment());
    }
    catch (\Throwable $e) { throw new PaymentGatewayException((string)$this->t('Could not connect to Paymos. Try again.')); }
    return $this->buildRedirectForm($form,$form_state,$url,[],self::REDIRECT_GET);
  }
}
