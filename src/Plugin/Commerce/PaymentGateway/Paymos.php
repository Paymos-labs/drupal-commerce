<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_paymos\Service\{ConnectionStore, PaymentProcessor};
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * @CommercePaymentGateway(
 *   id = "paymos",
 *   label = @Translation("Paymos"),
 *   display_label = @Translation("Pay with stablecoins"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paymos\PluginForm\OffsiteForm",
 *     "refund-payment" = "Drupal\commerce_paymos\PluginForm\RefundForm"
 *   },
 *   modes = {"test" = @Translation("Sandbox"), "live" = @Translation("Live")},
 *   requires_billing_information = FALSE
 * )
 */
final class Paymos extends OffsitePaymentGatewayBase implements SupportsRefundsInterface {
  protected $connection;
  protected $processor;
  protected $refunds;
  public static function create(ContainerInterface $container,array $configuration,$plugin_id,$plugin_definition) {
    $instance = parent::create($container,$configuration,$plugin_id,$plugin_definition);
    $instance->connection = $container->get('commerce_paymos.connection');
    $instance->processor = $container->get('commerce_paymos.processor');
    $instance->refunds = $container->get('commerce_paymos.refunds');
    return $instance;
  }
  public function getEnvironment(): string { return $this->getMode()==='live'?'live':'sandbox'; }
  public function canRefundPayment(PaymentInterface $payment) {
    return $this->refunds->canRefund((string)$payment->id());
  }
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // A direct call has no durable Form API token and cannot start cash.
    throw new PaymentGatewayException('TODO-COPY:refund-protected-action-required');
  }
  public function requestRefund(PaymentInterface $payment, string $mode, string $token, $account) {
    if (!$payment->access('update',$account) || !$account->hasPermission('administer commerce_payment')
      || $payment->getPaymentGateway()->getPluginId() !== 'paymos'
      || $payment->getPaymentGatewayMode() !== $this->getMode()
      || $payment->getPaymentGateway()->getPlugin() !== $this) {
      throw new PaymentGatewayException('TODO-COPY:refund-access-denied');
    }
    return $this->refunds->request((string)$payment->id(),$mode,$token,$mode === 'full' ? $payment->getAmount() : NULL);
  }
  public function isConnected(): bool {
    try { return isset($this->connection->credentials()[$this->getEnvironment()]); }
    catch (\Throwable $e) { return FALSE; }
  }
  public function getNotifyUrl() { return Url::fromRoute('commerce_paymos.webhook',[],['absolute'=>TRUE]); }
  public function onReturn(OrderInterface $order,Request $request) {
    // Query parameters and the browser redirect carry no payment evidence.
    if (!$order->isPaid()) { throw new PaymentGatewayException((string)$this->t('Payment verification is pending. Wait a moment, then check again.')); }
  }
  public function onNotify(Request $request) {
    if (strlen($request->getContent()) > 1048576) {
      return new Response('Payload too large', 413, ['Content-Type'=>'text/plain']);
    }
    $status = $this->processor->handle($request->getContent(),(string)$request->headers->get('X-Webhook-Signature'));
    return new Response($status===200?'OK':'Retry',$status,['Content-Type'=>'text/plain']);
  }
  public function buildConfigurationForm(array $form,FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form,$form_state);
    $form['paymos_connect'] = ['#type'=>'link','#title'=>$this->t('Connect Paymos'),
      '#url'=>Url::fromRoute('commerce_paymos.connect')];
    return $form;
  }
}
