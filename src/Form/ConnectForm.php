<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\{FormBase, FormStateInterface};
use Drupal\Core\Url;
use Drupal\commerce_paymos\Service\{ConnectionStore, Reconciler};
use Paymos\Connect\DeviceConnectClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ConnectForm extends FormBase {
  protected $connection;
  protected $client;
  protected $time;
  protected $reconciler;
  public function __construct(ConnectionStore $connection, DeviceConnectClient $client,
    TimeInterface $time, Reconciler $reconciler) {
    $this->connection = $connection; $this->client = $client; $this->time = $time; $this->reconciler = $reconciler;
  }
  public static function create(ContainerInterface $container) {
    return new static($container->get('commerce_paymos.connection'),$container->get('commerce_paymos.device_client'),
      $container->get('datetime.time'),$container->get('commerce_paymos.reconciler'));
  }
  public function getFormId(): string { return 'commerce_paymos_connect'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    try { $connected = $this->connection->credentials() !== []; $device = $this->connection->device(); }
    catch (\Throwable $e) { $connected = FALSE; $device = NULL; }
    $form['status'] = ['#type'=>'item', '#markup'=>$connected ? $this->t('Paymos connection saved.') : $this->t('Connect Paymos before accepting payments.')];
    $form['success_return'] = ['#type'=>'item', '#title'=>$this->t('Success return URL'),
      '#plain_text'=>Url::fromRoute('commerce_paymos.return', [], ['absolute'=>TRUE])->toString()];
    $form['failure_return'] = ['#type'=>'item', '#title'=>$this->t('Failure return URL'),
      '#plain_text'=>Url::fromRoute('commerce_paymos.return', [], ['absolute'=>TRUE, 'query'=>['cancel'=>1]])->toString(),
      '#description'=>$this->t('Copy these addresses once into Success URL and Fail URL in your existing Paymos project. They let the checkout’s Back to store link return the buyer to Drupal Commerce.')];
    $form['actions'] = ['#type'=>'actions'];
    $form['actions']['connect'] = ['#type'=>'submit', '#value'=>$this->t('Connect Paymos')];
    if ($device && ($device['owner_uid'] ?? '') === (string)$this->currentUser()->id()) {
      if (!empty($device['user_code'])) {
        $form['code'] = ['#type'=>'item', '#plain_text'=>(string)$device['user_code']];
      }
      $form['approval'] = ['#type'=>'link', '#title'=>$this->t('Connect Paymos'),
        '#url'=>Url::fromUri($device['verification_url']), '#attributes'=>['target'=>'_blank','rel'=>'noopener noreferrer']];
      $form['pending'] = ['#type'=>'item','#markup'=>$this->t('Approve the connection in Paymos, then check approval here.')];
      $form['actions']['check'] = ['#type'=>'submit','#value'=>$this->t('Check approval'),'#submit'=>['::checkConnection']];
    }
    if ($connected) {
      $form['actions']['disconnect'] = ['#type'=>'submit','#value'=>$this->t('Disconnect Paymos'),'#submit'=>['::disconnect']];
      $form['actions']['reconcile'] = ['#type'=>'submit','#value'=>$this->t('Check pending payments'),'#submit'=>['::reconcile']];
      if ($this->currentUser()->hasPermission('administer commerce_payment')) {
        $this->refundPanel($form,$form_state);
      }
    }
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $device = $this->client->start(ConnectionStore::CMS,$this->connection->sourceUrl(),
        Url::fromRoute('commerce_paymos.connect',[],['absolute'=>TRUE])->toString());
      $device['owner_uid'] = (string)$this->currentUser()->id();
      $device['next_poll'] = $this->time->getCurrentTime()+(int)$device['interval'];
      $this->connection->saveDevice($device);
    }
    catch (\Throwable $e) { $this->messenger()->addError($this->t('Could not connect to Paymos. Try again.')); }
    $form_state->setRebuild();
  }
  public function checkConnection(array &$form, FormStateInterface $form_state): void {
    try {
      $device = $this->connection->device(); $now = $this->time->getCurrentTime();
      if (!$device || ($device['owner_uid'] ?? '') !== (string)$this->currentUser()->id()
        || $now >= (int)$device['started_at']+(int)$device['expires_in']) { throw new \RuntimeException('paymos_device_expired'); }
      if ($now < (int)($device['next_poll']??0)) {
        $this->messenger()->addStatus($this->t('Approve the connection in Paymos, then check approval here.')); $form_state->setRebuild(); return;
      }
      $device['next_poll'] = $now+max(1,(int)$device['interval']); $this->connection->saveDevice($device);
      $result = $this->client->poll($device['device_code']);
      if ($result['status']==='connected') {
        $this->connection->accept($result,$device['source_url']);
        $this->messenger()->addStatus($this->t('Paymos connection saved.'));
      }
      elseif (in_array($result['status'],['authorization_pending','slow_down'],TRUE)) {
        if ($result['status']==='slow_down') { $device['interval']+=5; $device['next_poll']=$now+$device['interval']; }
        $this->connection->saveDevice($device);
        $this->messenger()->addStatus($this->t('Approve the connection in Paymos, then check approval here.'));
      }
      else { $this->connection->clearDevice(); $this->messenger()->addError($this->t('Could not connect to Paymos. Try again.')); }
    }
    catch (\Throwable $e) { $this->messenger()->addError($this->t('Could not connect to Paymos. Try again.')); }
    $form_state->setRebuild();
  }
  public function disconnect(array &$form, FormStateInterface $form_state): void {
    if (\Drupal::service('commerce_paymos.refund_store')->hasHistory()) {
      $this->messenger()->addError($this->t('TODO-COPY:refund-history-prevents-disconnect')); $form_state->setRebuild(); return;
    }
    $this->connection->disconnect();
    $this->messenger()->addStatus($this->t('Paymos connection removed.')); $form_state->setRebuild();
  }
  public function reconcile(array &$form, FormStateInterface $form_state): void {
    $count = $this->reconciler->run();
    $this->messenger()->addStatus($this->t('Checked @count pending payments.',['@count'=>$count])); $form_state->setRebuild();
  }
  private function refundPanel(array &$form, FormStateInterface $form_state): void {
    $options = [];
    foreach (\Drupal::service('commerce_paymos.refunds')->candidates() as $payment) {
      $options[(string)$payment->id()] = $this->t('TODO-COPY:refund-payment-option @id',['@id'=>$payment->id()]);
    }
    if (!$form_state->get('paymos_refund_token')) { $form_state->set('paymos_refund_token',\Drupal::service('uuid')->generate()); }
    $form['refunds'] = ['#type'=>'details','#title'=>$this->t('TODO-COPY:refund-panel'),'#open'=>TRUE];
    $form['refunds']['refund_payment'] = ['#type'=>'select','#title'=>$this->t('TODO-COPY:refund-payment'),
      '#options'=>$options,'#empty_option'=>$this->t('TODO-COPY:refund-select-payment')];
    $labels = ['full'=>$this->t('TODO-COPY:refund-full'),'overpayment'=>$this->t('TODO-COPY:refund-overpayment')];
    foreach (['full'=>'refundFull','overpayment'=>'refundOverpayment'] as $mode => $callback) {
      $form['refunds'][$mode] = ['#type'=>'submit','#value'=>$labels[$mode],
        '#submit'=>['::' . $callback],'#disabled'=>!$options];
    }
    foreach (\Drupal::service('commerce_paymos.refund_store')->recent() as $intent) {
      $observation = $intent->observation();
      $form['refunds']['operation_' . $intent->operationId()] = ['#type'=>'item',
        '#attributes'=>['id'=>'paymos-refund-' . $intent->operationId()],
        '#title'=>$this->t('TODO-COPY:refund-operation'),
        '#plain_text'=>$intent->operationId() . ' / ' . $intent->mode() . ' / ' . $intent->cashState() . ' / ' . $intent->projectionState()
          . ($observation ? ' / ' . $observation->principalDecimal() . ' ' . $observation->currency() . '/' . $observation->network() : '')];
    }
    foreach (\Drupal::service('commerce_paymos.refund_store')->externalRecent() as $marker) {
      $observation = $marker['observation'];
      $form['refunds']['external_' . sha1($observation['withdrawal_id'])] = ['#type'=>'item',
        '#title'=>$this->t('TODO-COPY:refund-external-observation'),
        '#plain_text'=>$observation['withdrawal_id'] . ' / ' . $observation['mode'] . ' / ' . $observation['status']
          . ' / ' . $marker['projection_state'] . ' / ' . $observation['amount'] . ' ' . $observation['currency'] . '/' . $observation['network']
          . ($marker['review_reason'] ? ' / ' . $marker['review_reason'] : '')];
    }
  }
  public function refundFull(array &$form, FormStateInterface $form_state): void { $this->refundAction($form_state,'full'); }
  public function refundOverpayment(array &$form, FormStateInterface $form_state): void { $this->refundAction($form_state,'overpayment'); }
  private function refundAction(FormStateInterface $form_state, string $mode): void {
    try {
      $payment = \Drupal::service('entity_type.manager')->getStorage('commerce_payment')->load($form_state->getValue('refund_payment'));
      if (!$payment || !$payment->getPaymentGateway() || $payment->getPaymentGateway()->getPluginId() !== 'paymos') { throw new \RuntimeException('refund_payment_missing'); }
      $payment->getPaymentGateway()->getPlugin()->requestRefund($payment,$mode,$form_state->get('paymos_refund_token'),$this->currentUser());
      $this->messenger()->addStatus($this->t('TODO-COPY:refund-operation-status'));
    }
    catch (\Throwable $e) { $this->messenger()->addError($this->t('TODO-COPY:refund-action-review')); }
    $form_state->setRebuild();
  }
}
