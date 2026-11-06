<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce_payment\PluginForm\PaymentFormBase;
use Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos;

/** The native payment operation supplies permissions and Form API CSRF protection. */
final class RefundForm extends PaymentFormBase {
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    if (!$form_state->get('paymos_refund_token')) {
      $form_state->set('paymos_refund_token',\Drupal::service('uuid')->generate());
    }
    $form['#cache']['max-age'] = 0;
    $form['mode'] = ['#type'=>'radios','#title'=>$this->t('Refund type'),
      '#options'=>['full'=>$this->t('Full refund'),'overpayment'=>$this->t('Refund overpayment')],
      '#default_value'=>'full','#required'=>TRUE];
    $operationId = $form_state->get('paymos_refund_operation');
    if ($operationId) {
      $intent = \Drupal::service('commerce_paymos.refund_store')->getIntent($operationId);
      $form['operation'] = ['#type'=>'item','#title'=>$this->t('Refund operation'),
        '#plain_text'=>$operationId . ' / ' . $intent->cashState() . ' / ' . $intent->projectionState()];
      $form['operation_link'] = ['#type'=>'link','#title'=>$this->t('View on Paymos settings page'),
        '#url'=>Url::fromRoute('commerce_paymos.connect',[],['fragment'=>'paymos-refund-' . $operationId])];
      $form['mode']['#disabled'] = TRUE;
    }
    return $form;
  }
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $mode = $form_state->getValue('mode');
    if (!in_array($mode,['full','overpayment'],TRUE)) { $form_state->setErrorByName('mode',$this->t('Select either a full refund or an overpayment refund.')); }
    if ($form_state->get('paymos_refund_mode') && $form_state->get('paymos_refund_mode') !== $mode) {
      $form_state->setErrorByName('mode',$this->t('A refund request with a different mode is already in progress for this form.'));
    }
  }
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $plugin = $this->entity->getPaymentGateway()->getPlugin();
    if (!$plugin instanceof Paymos) { throw new \LogicException('paymos_wrong_gateway'); }
    $mode = $form_state->get('paymos_refund_mode') ?: $form_state->getValue('mode');
    $form_state->set('paymos_refund_mode',$mode);
    try {
      $intent = $plugin->requestRefund($this->entity,$mode,$form_state->get('paymos_refund_token'),\Drupal::currentUser());
      $form_state->set('paymos_refund_operation',$intent->operationId());
      $this->messenger()->addStatus($this->t('Paymos refund request recorded. Check the operation status below.'));
    }
    catch (\Throwable $e) { $this->messenger()->addError($this->t('Could not complete the Paymos refund request. Review the payment and operation status before retrying.')); }
    $form_state->setRebuild();
  }
}
