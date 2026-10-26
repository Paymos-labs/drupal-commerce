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
  public function __construct(protected ConnectionStore $connection, protected DeviceConnectClient $client,
    protected TimeInterface $time, protected Reconciler $reconciler) {}
  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_paymos.connection'),$container->get('commerce_paymos.device_client'),
      $container->get('datetime.time'),$container->get('commerce_paymos.reconciler'));
  }
  public function getFormId(): string { return 'commerce_paymos_connect'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    try { $connected = $this->connection->credentials() !== []; $device = $this->connection->device(); }
    catch (\Throwable $e) { $connected = FALSE; $device = NULL; }
    $form['status'] = ['#type'=>'item', '#markup'=>$connected ? $this->t('Paymos connection saved.') : $this->t('Connect Paymos before accepting payments.')];
    $form['actions'] = ['#type'=>'actions'];
    $form['actions']['connect'] = ['#type'=>'submit', '#value'=>$this->t('Connect Paymos')];
    if ($device && ($device['owner_uid'] ?? '') === (string)$this->currentUser()->id()) {
      $form['approval'] = ['#type'=>'link', '#title'=>$this->t('Connect Paymos'),
        '#url'=>Url::fromUri($device['verification_url']), '#attributes'=>['target'=>'_blank','rel'=>'noopener noreferrer']];
      $form['pending'] = ['#type'=>'item','#markup'=>$this->t('Approve the connection in Paymos, then check approval here.')];
      $form['actions']['check'] = ['#type'=>'submit','#value'=>$this->t('Check approval'),'#submit'=>['::checkConnection']];
    }
    if ($connected) {
      $form['actions']['disconnect'] = ['#type'=>'submit','#value'=>$this->t('Disconnect Paymos'),'#submit'=>['::disconnect']];
      $form['actions']['reconcile'] = ['#type'=>'submit','#value'=>$this->t('Check pending payments'),'#submit'=>['::reconcile']];
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
    $this->connection->disconnect();
    $this->messenger()->addStatus($this->t('Paymos connection removed.')); $form_state->setRebuild();
  }
  public function reconcile(array &$form, FormStateInterface $form_state): void {
    $count = $this->reconciler->run();
    $this->messenger()->addStatus($this->t('Checked @count pending payments.',['@count'=>$count])); $form_state->setRebuild();
  }
}
