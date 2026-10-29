<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/refund.php';
spl_autoload_register(static function ($class) {
  foreach (['Paymos\\'=>dirname(__DIR__,2) . '/php-sdk/src/','Drupal\\commerce_paymos\\'=>dirname(__DIR__) . '/src/'] as $prefix => $directory) {
    if (strpos($class,$prefix) === 0) {
      $file = $directory . str_replace('\\','/',substr($class,strlen($prefix))) . '.php';
      if (is_file($file)) { require_once $file; }
    }
  }
});

use Drupal\commerce_paymos\Service\{ClientFactoryInterface, PaymentStoreInterface, RefundAdapter, RefundIntentStore, RefundNativeGateway};
use Paymos\{Client, ClientConfig};
use Paymos\Http\{HttpResponse, TransportInterface};
use Paymos\Plugin\{EffectClaim, RefundIntent, RefundStates, VerifiedRefund};
use PaymosDrupalRefundTest\{Clock, Config, Database, Entities};

function refundExpect($value, $message): void { if (!$value) { throw new RuntimeException($message); } }
function refundThrows(callable $action, string $reason = ''): void {
  try { $action(); } catch (RuntimeException $e) {
    refundExpect($reason === '' || strpos($e->getMessage(),$reason) !== FALSE,'Unexpected refusal: ' . $e->getMessage()); return;
  }
  throw new LogicException('Expected refusal: ' . $reason);
}
final class RefundInvoices implements PaymentStoreInterface {
  public $record = ['order_id'=>'10','gateway_id'=>'paymos','invoice_id'=>'inv_fixture','project_id'=>'prj_fixture',
    'environment'=>'sandbox','amount'=>'100.00','currency'=>'USD','credited'=>TRUE];
  public function byInvoice(string $id): ?array { return $id === 'inv_fixture' ? $this->record : NULL; }
  public function synchronized(string $id, callable $action) { return $action(); }
  public function transaction(string $id, callable $action, bool $durable = FALSE) { return $action(); }
  public function snapshot(string $id): array { return []; }
  public function load(string $id): ?array { return $this->record; }
  public function save(array $record): void { $this->record = $record; }
  public function isCommitted(string $id): bool { return FALSE; }
  public function commitEvent(string $id): void {}
  public function complete(array $record,array $invoice): void { throw new LogicException('Payment completion must not run'); }
  public function note(string $id,string $code): void {}
  public function pending(int $since,int $limit): array { return []; }
  public function markChecked(string $id): void {}
}
final class RefundProvider implements TransportInterface {
  public $requests = [];
  public $refunds = [];
  public $timeout = FALSE;
  public $status = 'created';
  public $corrupt = [];
  private $db;
  public function __construct($db) { $this->db = $db; }
  public function request($method,$url,array $headers,$body,$timeoutSeconds) {
    refundExpect(!$this->db->inTransaction(),'HTTP inside native transaction');
    $this->requests[] = [$method,$url,$body];
    if ($method === 'POST') {
      $input = json_decode($body,TRUE); $key = $input['external_order_id'];
      refundExpect(array_keys($input) === ['invoice_id','mode','external_order_id'],'Only SDK mode-only wire may be sent');
      $rows = $this->db->tables['commerce_paymos_refund'] ?? [];
      $saved = array_values(array_filter($rows,static function ($row) use ($key) { return $row['external_id'] === $key; }));
      refundExpect(count($saved) === 1 && json_decode($saved[0]['data'],TRUE)['cash_state'] === 'unknown','Committed intent/unknown before HTTP');
      if (!isset($this->refunds[$key])) {
        $completedOver = FALSE;
        foreach ($this->refunds as $refund) { if ($refund['mode'] === 'overpayment' && $refund['status'] === 'completed') { $completedOver = TRUE; } }
        $this->refunds[$key] = ['withdrawal_id'=>'wd_' . (count($this->refunds) + 1),'invoice_id'=>'inv_fixture',
          'project_id'=>'prj_fixture','is_test'=>TRUE,'mode'=>$input['mode'],'external_order_id'=>$key,
          'status'=>$this->status,'amount'=>$input['mode'] === 'overpayment' ? '20' : ($completedOver ? '100' : '120'),
          'currency'=>'USDT','network'=>'TRC20','first_sender'=>'sender_A'];
      }
      if ($this->timeout) { $this->timeout = FALSE; throw new RuntimeException('fixture_timeout_after_cash_acceptance'); }
      return new HttpResponse(201,json_encode($this->refunds[$key]),[]);
    }
    if (strpos($url,'/v1/invoices/') !== FALSE) {
      return new HttpResponse(200,json_encode(['invoice_id'=>'inv_fixture','project_id'=>'prj_fixture','is_test'=>TRUE,'status'=>'paid_over']),[]);
    }
    foreach ($this->refunds as $refund) {
      if (substr($url,-strlen($refund['withdrawal_id'])) === $refund['withdrawal_id']) {
        return new HttpResponse(200,json_encode($this->corrupt + $refund),[]);
      }
    }
    throw new RuntimeException('fixture_refund_not_found');
  }
  public function setStatus($id,$status): void { foreach ($this->refunds as &$refund) { if ($refund['withdrawal_id'] === $id) { $refund['status'] = $status; } } }
  public function postCount(): int { return count(array_filter($this->requests,static function ($r) { return $r[0] === 'POST'; })); }
}
final class RefundClients implements ClientFactoryInterface {
  public $values = ['project_id'=>'prj_fixture','capabilities'=>['invoice_refunds_v1'],'refunds_enabled'=>TRUE,'webhook_secret'=>'whsec_fixture'];
  private $provider;
  public function __construct($provider) { $this->provider = $provider; }
  public function configuration(string $environment): array { return array_replace($this->values,['webhook_secret'=>$environment === 'sandbox' ? 'whsec_fixture' : 'whsec_live']); }
  public function create(string $environment): Client { return new Client(new ClientConfig('pk_test_fixture','sk_test_fixture','https://api.paymos.invalid'),$this->provider); }
}
function refundFixture(): array {
  $db = new Database(); $entities = new Entities(); $db->entities = $entities; $clock = new Clock();
  $db->tables['commerce_payment'] = [['payment_id'=>'1','order_id'=>'10']]; $db->tables['commerce_order'] = [['order_id'=>'10']];
  $store = new RefundIntentStore($db,$clock); $provider = new RefundProvider($db); $clients = new RefundClients($provider);
  $invoices = new RefundInvoices(); $native = new RefundNativeGateway($entities,$invoices,$db,$store);
  $adapter = new RefundAdapter($store,$native,$clients,new Config());
  return [$adapter,$store,$provider,$entities,$clients,$db,$clock,$native,$invoices];
}
function refundToken($digit): string { return 'token-fixture-000000000' . $digit; }

$refundTests = [];
$refundTests['ton_raw_pin_accepts_friendly_same_account_and_rejects_another'] = static function (): void {
  require_once __DIR__ . '/CmsTonPinContract.php';
  list($adapter, $store) = refundFixture();
  paymos_cms_ton_pin_contract($store, 'drupal', 'fixture-installation', '1');
};
$refundTests['capability_and_enabled_reject_before_all_http'] = static function () {
  list($adapter,$store,$provider,$entities,$clients) = refundFixture();
  $clients->values['capabilities'] = [];
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'reconnect');
  refundExpect(!$provider->requests && !$adapter->canRefund('1'),'Capability fails closed');
  $clients->values['capabilities'] = ['invoice_refunds_v1']; $clients->values['refunds_enabled'] = FALSE;
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(1)); },'not_enabled');
  refundExpect(!$provider->requests,'Disabled new refunds perform no HTTP');
};
$refundTests['partial_manual_mapping_and_ambiguous_payments_fail_before_http'] = static function () {
  list($adapter,$store,$provider,$entities) = refundFixture();
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1),new \Drupal\commerce_price\Price('50','USD')); },'partial');
  $entities->payments['1']->refunded = '1';
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'mapping');
  $entities->payments['1']->refunded = '0'; $entities->payments['2'] = new \PaymosDrupalRefundTest\Payment();
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(1)); },'mapping');
  refundExpect(!$provider->requests,'No HTTP for unsafe native drafts');
};
$refundTests['unknown_reuses_committed_key_and_blocks_mode_switch'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db,$clock,$native,$invoices) = refundFixture(); $provider->timeout = TRUE;
  $first = $adapter->request('1','full',refundToken(1));
  refundExpect($first->cashState() === 'unknown' && $provider->postCount() === 1,'Unknown preserved');
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(1)); },'conflict');
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(2)); },'unresolved');
  $restartedStore = new RefundIntentStore($db,$clock);
  $restarted = new RefundAdapter($restartedStore,new RefundNativeGateway($entities,$invoices,$db,$restartedStore),$clients,new Config());
  $second = $restarted->request('1','full',refundToken(1));
  refundExpect($second->operationId() === $first->operationId() && count($provider->refunds) === 1,'Replay never creates another withdrawal');
};
$refundTests['120_100_over20_then_full100_and_duplicates'] = static function () {
  list($adapter,$store,$provider,$entities) = refundFixture();
  $over = $adapter->request('1','overpayment',refundToken(1));
  refundExpect($entities->payments['1']->saves === 0,'Accepted has no native money effect');
  $provider->setStatus($over->withdrawalId(),'completed'); $adapter->reconcile($over->operationId());
  refundExpect($entities->payments['1']->refunded === '0' && $entities->payments['1']->state === 'completed','Overpayment is cash-only');
  refundExpect(count($entities->orders['10']->data['paymos_refund_notes']) === 1 && $entities->orders['10']->stock === 7,'One note; stock unchanged');
  $adapter->reconcile($over->operationId());
  $full = $adapter->request('1','full',refundToken(2)); $provider->setStatus($full->withdrawalId(),'completed'); $adapter->reconcile($full->operationId());
  refundExpect($over->observation()->principalDecimal() === '20' && $full->observation()->principalDecimal() === '100','Server over20 then remaining full100');
  refundExpect($entities->payments['1']->refunded === '100.00' && $entities->payments['1']->state === 'refunded','Full original native total');
  $adapter->reconcile($full->operationId());
  refundExpect($entities->payments['1']->saves === 1 && $provider->postCount() === 2,'One full write, two modes only');
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(3)); });
  $provider->setStatus($over->withdrawalId(),'failed'); $adapter->reconcile($over->operationId());
  refundExpect($entities->payments['1']->refunded === '100.00','Old over failure cannot overwrite full');
};
$refundTests['native_commit_crash_rolls_back_then_recovers_without_second_cash'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db) = refundFixture();
  $intent = $adapter->request('1','full',refundToken(1)); $provider->setStatus($intent->withdrawalId(),'completed'); $db->failProjection = TRUE;
  refundExpect($adapter->reconcile($intent->operationId()) === RefundStates::SHOW_REVIEW,'Crash requires recovery');
  refundExpect($entities->payments['1']->refunded === '0','Native write rolled back with marker');
  $adapter->reconcile($intent->operationId());
  refundExpect($entities->payments['1']->saves === 1 && $provider->postCount() === 1,'Recovery applies once without cash POST');
};
$refundTests['full_reorg_recompletion_reuses_marker_and_preserves_manual_changes'] = static function () {
  list($adapter,$store,$provider,$entities) = refundFixture(); $provider->status = 'completed';
  $intent = $adapter->request('1','full',refundToken(1));
  refundExpect($intent->observation()->principalDecimal() === '120' && $entities->payments['1']->refunded === '100.00','Full crypto120 projects original native100 without allocation');
  $provider->setStatus($intent->withdrawalId(),'signed'); $adapter->reconcile($intent->operationId());
  refundExpect($entities->payments['1']->refunded === '0' && $entities->payments['1']->state === 'completed','Owned mutable full rewound');
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(2)); },'unresolved');
  $provider->setStatus($intent->withdrawalId(),'completed'); $adapter->reconcile($intent->operationId());
  refundExpect($entities->payments['1']->refunded === '100.00' && $provider->postCount() === 1,'Recompletion no cash retry');
  $entities->payments['1']->refunded = '33'; $provider->setStatus($intent->withdrawalId(),'signed'); $adapter->reconcile($intent->operationId());
  refundExpect($entities->payments['1']->refunded === '33' && $store->getIntent($intent->operationId())->projectionState() === 'native_review','Manual changes never overwritten');
};
$refundTests['fresh_get_pins_money_and_write_once_binding'] = static function () {
  list($adapter,$store,$provider,$entities) = refundFixture(); $intent = $adapter->request('1','full',refundToken(1));
  $store->withPaymentLock('1',static function () use ($store,$intent) {
    refundExpect($store->bindWithdrawal($intent->operationId(),'wd_other') === 'conflict','Write-once binding');
  });
  $provider->corrupt = ['amount'=>'101','status'=>'completed']; $adapter->reconcile($intent->operationId());
  refundExpect($store->getIntent($intent->operationId())->projectionState() === 'native_review' && $entities->payments['1']->refunded === '0','Changed GET evidence refuses projection');
};
$refundTests['scoped_callback_ignores_event_money_and_disable_preserves_recovery'] = static function () {
  list($adapter,$store,$provider,$entities,$clients) = refundFixture(); $intent = $adapter->request('1','full',refundToken(1));
  $data = reset($provider->refunds); $data['status'] = 'completed'; $data['amount'] = '999';
  $adapter->callback($data,'sandbox'); refundExpect($entities->payments['1']->refunded === '0','Event is only a GET signal');
  $before = count($provider->requests); $bad = $data; $bad['project_id'] = 'prj_other';
  refundThrows(static function () use ($adapter,$bad) { $adapter->callback($bad,'sandbox'); },'scope');
  refundExpect(count($provider->requests) === $before,'Foreign callbacks rejected before HTTP');
  $clients->values['refunds_enabled'] = FALSE; $provider->setStatus($intent->withdrawalId(),'completed'); $adapter->callback($data,'sandbox');
  refundExpect($entities->payments['1']->refunded === '100.00','New create gate does not disable recovery');
};
$refundTests['effects_lease_fencing_and_backoff_history_sweep'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db,$clock) = refundFixture(); $intent = $adapter->request('1','full',refundToken(1));
  $store->withPaymentLock('1',static function () use ($store,$intent) {
    refundExpect($store->claimEffect($intent->operationId(),'mail',0)->kind() === EffectClaim::CLAIMED,'Committed started');
    refundExpect($store->claimEffect($intent->operationId(),'mail',0)->kind() === EffectClaim::ALREADY_STARTED,'No resend after crash');
  });
  $clock->now += 86401;
  $store->withPaymentLock('1',static function () use ($store,$intent) { $store->schedule($intent->operationId()); });
  refundExpect($store->getIntent($intent->operationId())->projectionState() === 'native_review','Unresolved >24h goes to review');
  $due = $store->listDue((new DateTimeImmutable())->setTimestamp($clock->now + 86401),100,NULL);
  refundExpect(count($due->items()) === 1,'Review stays in historical sweep');
  refundThrows(static function () use ($store,$clock) {
    $store->withPaymentLock('1',static function () use ($clock) { $clock->now += 301; });
  },'lease_lost');
};
$refundTests['external_full_is_review_external_over_is_cash_only_without_post'] = static function () {
  foreach (['full','overpayment'] as $mode) {
    list($adapter,$store,$provider,$entities) = refundFixture();
    $data = ['withdrawal_id'=>'wd_external','invoice_id'=>'inv_fixture','project_id'=>'prj_fixture','is_test'=>TRUE,
      'mode'=>$mode,'external_order_id'=>'dashboard-action','status'=>'completed','amount'=>$mode === 'full' ? '120' : '20',
      'currency'=>'USDT','network'=>'TRC20','first_sender'=>'sender_A'];
    $provider->refunds['external'] = $data; $adapter->callback($data,'sandbox'); $adapter->callback($data,'sandbox');
    refundExpect($provider->postCount() === 0 && $entities->payments['1']->refunded === '0','External signals never create cash or native full');
    if ($mode === 'full') { refundExpect(!$adapter->canRefund('1'),'External full without draft blocks new actions'); }
    else { refundExpect(count($entities->orders['10']->data['paymos_refund_notes']) === 1,'External overpayment note idempotent'); }
  }
};
$refundTests['protected_gateway_rejects_direct_refund'] = static function () {
  $gateway = new \Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos();
  refundThrows(static function () use ($gateway) { $gateway->refundPayment(new \PaymosDrupalRefundTest\Payment()); },'protected-action');
};
$refundTests['native_minor_units_never_round_or_convert_crypto'] = static function () {
  refundExpect(RefundNativeGateway::minor('9007199254740993.01',2) === '900719925474099301','String minor conversion');
  refundThrows(static function () { RefundNativeGateway::minor('1.001',2); },'precision');
};
$refundTests['connect_accept_preserves_sdk_capability_in_encrypted_credentials'] = static function () {
  $state = new class implements \Drupal\Core\State\StateInterface {
    public $data = [];
    public function get($key) { return $this->data[$key] ?? NULL; }
    public function set($key,$value) { $this->data[$key] = $value; }
    public function delete($key) { unset($this->data[$key]); }
  };
  $connection = new \Drupal\commerce_paymos\Service\ConnectionStore($state,new \Drupal\Core\PrivateKey(),new \Symfony\Component\HttpFoundation\RequestStack(),new Clock());
  $credentials = [];
  foreach (['sandbox'=>'test','live'=>'live'] as $environment => $prefix) {
    $credentials[$environment] = ['api_key'=>'pk_' . $prefix . '_fixture','api_secret'=>'sk_' . $prefix . '_fixture',
      'project_id'=>'prj_fixture','webhook_secret'=>'whsec_fixture','capabilities'=>['invoice_refunds_v1'],
      'refunds_enabled'=>TRUE,'refund_contract_version'=>1];
  }
  $connection->accept(['status'=>'connected','plugin'=>'drupal-commerce','source_url'=>'https://shop.example.invalid','credentials'=>$credentials],'https://shop.example.invalid');
  $saved = $connection->credentials();
  foreach ($saved as $values) {
    refundExpect(\Paymos\Plugin\CredentialSet::hasCapability($values,'invoice_refunds_v1') && \Paymos\Plugin\CredentialSet::refundsEnabled($values),'SDK handshake survives encrypted accept/load');
  }
  refundExpect(strpos($state->data['commerce_paymos.connection'],'invoice_refunds_v1') === FALSE,'No plaintext credential envelope');
};
$refundTests['signed_webhook_routes_to_refund_and_invalid_signature_never_calls_http'] = static function () {
  list($adapter,$store,$provider,$entities,$clients) = refundFixture(); $intent = $adapter->request('1','full',refundToken(1));
  $events = new class implements \Drupal\commerce_paymos\Service\EventReservationInterface {
    public function remember($id,$ttl) { return TRUE; }
    public function isCommitted($id) { return FALSE; }
    public function releaseAll(): void {}
  };
  $processor = new \Drupal\commerce_paymos\Service\PaymentProcessor(new RefundInvoices(),$clients,$events,$adapter);
  $provider->setStatus($intent->withdrawalId(),'completed'); $data = reset($provider->refunds); $now = time();
  $body = json_encode(['event_id'=>'evt_refund','event_type'=>'withdrawal.completed','version'=>1,'occurred_at'=>$now,'data'=>$data]);
  $signature = 't=' . $now . ',v1=' . hash_hmac('sha256',$now . '.' . $body,'whsec_fixture');
  $before = count($provider->requests);
  refundExpect($processor->handle($body,'t=' . $now . ',v1=' . str_repeat('0',64),$now) === 401 && count($provider->requests) === $before,'Signature before HTTP');
  refundExpect($processor->handle($body,$signature,$now) === 200 && $entities->payments['1']->state === 'refunded','Existing processor dispatches verified withdrawals');
};
$refundTests['caller_transaction_blocks_new_and_unknown_replay_http'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db) = refundFixture(); $provider->timeout = TRUE;
  $adapter->request('1','full',refundToken(1)); $before = count($provider->requests); $transaction = $db->startTransaction();
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'committed');
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(2)); },'committed');
  refundExpect(count($provider->requests) === $before,'No HTTP inside caller transaction'); unset($transaction);
};
$refundTests['failed_cancelled_require_new_explicit_token_and_unknown_replays_when_disabled'] = static function () {
  foreach (['failed','cancelled'] as $status) {
    list($adapter,$store,$provider,$entities,$clients) = refundFixture(); $provider->status = $status;
    $first = $adapter->request('1','full',refundToken(1)); $adapter->request('1','full',refundToken(1));
    refundExpect($provider->postCount() === 1,'Terminal same token never POSTs again');
    $second = $adapter->request('1','overpayment',refundToken(2));
    refundExpect($second->operationId() !== $first->operationId() && $provider->postCount() === 2,'Definitive outcome permits a new explicit action');
  }
  list($adapter,$store,$provider,$entities,$clients) = refundFixture(); $provider->timeout = TRUE;
  $first = $adapter->request('1','full',refundToken(1)); $clients->values['refunds_enabled'] = FALSE;
  $second = $adapter->request('1','full',refundToken(1));
  refundExpect($first->operationId() === $second->operationId() && count($provider->refunds) === 1,'Disabled create gate preserves unknown same-key replay');
};
$refundTests['over_reorg_during_pending_full_cannot_unlock_or_change_full'] = static function () {
  list($adapter,$store,$provider,$entities) = refundFixture(); $provider->status = 'completed';
  $over = $adapter->request('1','overpayment',refundToken(1)); $provider->status = 'created';
  $full = $adapter->request('1','full',refundToken(2)); $provider->setStatus($over->withdrawalId(),'signed'); $adapter->reconcile($over->operationId());
  refundExpect($entities->payments['1']->refunded === '0' && $store->getIntent($full->operationId())->cashState() === 'created','Old over observes only its own cash');
  refundThrows(static function () use ($adapter) { $adapter->request('1','overpayment',refundToken(3)); },'unresolved');
  $provider->setStatus($full->withdrawalId(),'completed'); $adapter->reconcile($full->operationId());
  $provider->setStatus($over->withdrawalId(),'completed'); $adapter->reconcile($over->operationId());
  refundExpect($entities->payments['1']->saves === 1 && $entities->payments['1']->refunded === '100.00' && $provider->postCount() === 2,'Recompletion preserves full owner');
};
$refundTests['historical_pages_and_immutable_store_conflicts'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db,$clock) = refundFixture(); $provider->status = 'failed';
  $first = $adapter->request('1','full',refundToken(1));
  $adapter->request('1','full',refundToken(2)); $adapter->request('1','full',refundToken(3));
  $now = (new DateTimeImmutable())->setTimestamp($clock->now + 86401);
  $page = $store->listDue($now,2,NULL); $next = $store->listDue($now,2,$page->nextCursor());
  refundExpect(count($page->items()) === 2 && count($next->items()) === 1,'Stable historical keyset pagination');
  $changed = $first->toArray(); $changed['full_draft']['amount_minor'] = '9999'; unset($changed['payload_hash']);
  refundThrows(static function () use ($store,$changed) { $store->createIntent(new RefundIntent($changed)); },'request_conflict');
};
$refundTests['missing_marker_manual_paid_change_and_nontransactional_driver_fail_closed'] = static function () {
  list($adapter,$store,$provider,$entities,$clients,$db) = refundFixture(); $provider->status = 'completed';
  $intent = $adapter->request('1','full',refundToken(1)); $db->tables['commerce_paymos_refund_projection'] = [];
  $adapter->reconcile($intent->operationId());
  refundExpect($entities->payments['1']->saves === 1 && $store->getIntent($intent->operationId())->projectionState() === 'native_review','Missing marker never creates second native write');
  list($adapter,$store,$provider,$entities) = refundFixture(); $entities->payments['1']->amount = '101';
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'mapping');
  refundExpect(!$provider->requests,'Manual original paid change fails before cash');
  list($adapter,$store,$provider,$entities,$clients,$db) = refundFixture(); $db->driverName = 'sqlite';
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'database_unsupported');
  refundExpect(!$provider->requests,'Unsupported row-lock driver fails before HTTP');
  $db->driverName = 'mysql'; $db->engine = 'MyISAM';
  refundThrows(static function () use ($adapter) { $adapter->request('1','full',refundToken(1)); },'transactional_storage');
  refundExpect(!$provider->requests,'Nontransactional native table fails before HTTP');
  $db->engine = 'InnoDB';
  refundExpect($adapter->canRefund('1'),'Prefixed InnoDB tables pass local storage preflight');
};
$refundTests['native_gateway_entry_checks_permissions_and_connection_capability'] = static function () {
  list($adapter,$store,$provider,$entities,$clients) = refundFixture();
  $gateway = new \Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos();
  $property = new ReflectionProperty($gateway,'refunds'); $property->setAccessible(TRUE); $property->setValue($gateway,$adapter);
  $payment = $entities->payments['1']; $payment->gateway = new \PaymosDrupalRefundTest\Gateway($gateway);
  $account = new class {
    public $allowed = FALSE;
    public function hasPermission($permission) { return $this->allowed; }
  };
  refundThrows(static function () use ($gateway,$payment,$account) { $gateway->requestRefund($payment,'full',refundToken(1),$account); },'access-denied');
  $account->allowed = TRUE; $clients->values['capabilities'] = [];
  refundExpect(!$gateway->canRefundPayment($payment),'Existing gateway capability preflight');
  refundThrows(static function () use ($gateway,$payment,$account) { $gateway->requestRefund($payment,'full',refundToken(1),$account); },'reconnect');
  refundExpect(!$provider->requests,'Native entry refuses missing permission/capability before any HTTP');
  $clients->values['capabilities'] = ['invoice_refunds_v1'];
  $gateway->requestRefund($payment,'full',refundToken(1),$account);
  refundExpect($provider->postCount() === 1,'Protected native entry is connected to coordinator');
};
$refundTests['schema_hook_is_additive_idempotent_and_retains_legacy_bindings'] = static function () {
  require_once dirname(__DIR__) . '/commerce_paymos.install';
  $db = new Database(); $db->tables = ['commerce_paymos_invoice'=>[['order_id'=>'10','invoice_id'=>'inv_fixture']]];
  \Drupal::$database = $db; $before = $db->tables['commerce_paymos_invoice'];
  commerce_paymos_update_10001(); commerce_paymos_update_10001();
  refundExpect($db->tables['commerce_paymos_invoice'] === $before,'Upgrade never rewrites invoice bindings');
  $schema = commerce_paymos_schema();
  foreach (['commerce_paymos_refund','commerce_paymos_refund_projection','commerce_paymos_refund_lock'] as $table) {
    refundExpect(isset($schema[$table]) && $db->tableExists($table),'Install and upgrade expose the same additive schema');
  }
};

$failed = 0;
foreach ($refundTests as $name => $test) {
  try { $test(); echo "PASS $name\n"; }
  catch (Throwable $e) { $failed++; echo 'FAIL ' . $name . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n"; }
}
echo count($refundTests) . ' refund service tests; native Drupal package NOT claimed' . "\n";
exit($failed ? 1 : 0);
