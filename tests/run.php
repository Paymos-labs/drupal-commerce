<?php

declare(strict_types=1);
$root = dirname(__DIR__);
$sdk = getenv('PAYMOS_SDK_SRC') ?: dirname($root) . '/php-sdk/src';
spl_autoload_register(static function (string $class) use ($root, $sdk): void {
  foreach (['Paymos\\' => $sdk . '/', 'Drupal\\commerce_paymos\\' => $root . '/src/'] as $prefix => $base) {
    if (str_starts_with($class, $prefix)) {
      $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (is_file($file)) { require_once $file; }
    }
  }
});

use Drupal\commerce_paymos\Service\{CheckoutService, PaymentProcessor, PaymentStoreInterface, ClientFactoryInterface, EventReservationInterface};
use Paymos\{Client, ClientConfig};
use Paymos\Http\{MockTransport, HttpResponse};

function expect($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
final class MemoryStore implements PaymentStoreInterface {
  public array $records = [], $events = [], $payments = [], $notes = [];
  public bool $failEvent = FALSE;
  public ?Closure $beforeSecondDurable = NULL;
  private int $durableCalls = 0;
  public array $order = ['order_id'=>'1','uuid'=>'order-one','gateway_id'=>'paymos','amount'=>'100.00','currency'=>'USD','paid'=>FALSE,'cancelled'=>FALSE];
  public function synchronized(string $orderId, callable $action): mixed { return $action(); }
  public function transaction(string $orderId, callable $action, bool $durable=FALSE): mixed {
    if ($durable && ++$this->durableCalls === 2 && $this->beforeSecondDurable) {
      ($this->beforeSecondDurable)($this);
    }
    $before=[$this->records,$this->events,$this->payments,$this->notes];
    try { return $action(); } catch(Throwable $e) { [$this->records,$this->events,$this->payments,$this->notes]=$before;throw $e; }
  }
  public function snapshot(string $orderId): array { return $this->order; }
  public function load(string $orderId): ?array { return $this->records[$orderId]??NULL; }
  public function byInvoice(string $invoiceId): ?array { foreach($this->records as $r) { if(($r['invoice_id']??'')===$invoiceId)return $r; } return NULL; }
  public function save(array $record): void { $this->records[$record['order_id']]=$record; }
  public function isCommitted(string $eventId): bool { return isset($this->events[$eventId]); }
  public function commitEvent(string $eventId): void { if($this->failEvent){$this->failEvent=FALSE;throw new RuntimeException("fixture_crash_before_event_commit");} $this->events[$eventId]=TRUE; }
  public function complete(array $record,array $invoice): void { $this->payments[]=$record['invoice_id']; }
  public function note(string $orderId,string $code): void { $this->notes[]=$code; }
  public function pending(int $since,int $limit): array { return array_slice(array_values($this->records),0,$limit); }
  public function markChecked(string $orderId): void {}
}
final class TestClients implements ClientFactoryInterface {
  public function __construct(public MockTransport $transport) {}
  public function configuration(string $environment): array { return ['project_id'=>'prj_fixture','webhook_secret'=>$environment==='sandbox'?'whsec_fixture':'whsec_live','api_key'=>'pk_test_fixture','api_secret'=>'sk_test_fixture']; }
  public function create(string $environment): Client { return new Client(new ClientConfig('pk_test_fixture','sk_test_fixture','https://api.paymos.invalid'),$this->transport); }
}
final class TestEvents implements EventReservationInterface {
  public function __construct(private MemoryStore $store) {}
  public function remember($eventId,$ttlSeconds) { return !$this->store->isCommitted($eventId); }
  public function isCommitted($eventId) { return $this->store->isCommitted($eventId); }
  public function releaseAll(): void {}
}
function response(array $data,int $status=200): HttpResponse { return new HttpResponse($status,json_encode($data,JSON_THROW_ON_ERROR),[]); }
function created(): array { return ['invoice_id'=>'inv_fixture','payment_url'=>'https://checkout.paymos.io/inv_fixture','status'=>'awaiting_client']; }
function fixture(string $status='paid',string $amount='100.00'): array {
 return ['invoice_id'=>'inv_fixture','project_id'=>'prj_fixture','is_test'=>TRUE,'status'=>$status,'order'=>['external_id'=>'intent_fixture','amount'=>$amount,'currency'=>'USD']];
}
function seeded(): MemoryStore {
 $s=new MemoryStore();$s->save(['order_id'=>'1','gateway_id'=>'paymos','environment'=>'sandbox','project_id'=>'prj_fixture','external_order_id'=>'intent_fixture','amount'=>'100.00','currency'=>'USD','invoice_id'=>'inv_fixture','payment_url'=>'https://checkout.paymos.io/inv_fixture','status'=>'awaiting_client','credited'=>FALSE,'late_noted'=>FALSE]);return $s;
}
function eventBody(array $invoice,string $event='invoice.paid',string $id='evt_fixture'): string { return json_encode(['event_id'=>$id,'event_type'=>$event,'version'=>1,'occurred_at'=>1700000000,'data'=>$invoice],JSON_THROW_ON_ERROR); }
function signature(string $body): string { return 't=1700000000,v1='.hash_hmac('sha256','1700000000.'.$body,'whsec_fixture'); }
$tests=[];
$tests['unknown_create_reuses_durable_intent']=static function():void {
 $s=new MemoryStore();$t=new MockTransport([response(['title'=>'temporary'],503),response(created())]);$c=new CheckoutService($s,new TestClients($t));
 try{$c->start('1','paymos','sandbox');throw new RuntimeException('Expected API failure');}catch(\Paymos\Exception\ApiException $e){}
 $intent=$s->records['1']['external_order_id'];expect($intent!=='','intent persisted before unknown outcome');
 $c->start('1','paymos','sandbox');$requests=$t->requests();
 $a=json_decode($requests[0]['body'],TRUE);$b=json_decode($requests[1]['body'],TRUE);
 expect($a['external_order_id']===$intent && $b['external_order_id']===$intent,'same intent across retry');
};
$tests['changed_order_during_unknown_create_cannot_reissue']=static function():void {
 $s=new MemoryStore();$t=new MockTransport([response(['title'=>'temporary'],503)]);$c=new CheckoutService($s,new TestClients($t));
 try{$c->start('1','paymos','sandbox');}catch(\Paymos\Exception\ApiException $e){}
 $s->order['amount']='101.00';try{$c->start('1','paymos','sandbox');throw new LogicException('unsafe reissue');}catch(RuntimeException $e){}
 expect(count($t->requests())===1,'no second request after snapshot change with unknown outcome');
};
$tests['paid_callback_creates_one_native_action_and_duplicate_skips_api']=static function():void {
 $s=seeded();$t=new MockTransport([response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000000)===200,'paid accepted');
 expect($s->payments===['inv_fixture'],'one payment action');
 expect($p->handle($b,signature($b),1700000000)===200 && count($t->requests())===1,'duplicate no API or second credit');
};
$tests['bad_signature_cannot_touch_api_or_credit']=static function():void {
 $s=seeded();$t=new MockTransport([]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,'t=1700000000,v1='.str_repeat('0',64),1700000000)===401,'bad signature401');expect(!$s->payments&&!$t->requests(),'no effects');
};
$tests['changed_amount_is_held_without_credit']=static function():void {
 $s=seeded();$s->order['amount']='101.00';$t=new MockTransport([response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000000)===200,'mismatch recorded');expect(!$s->payments,'mismatch not paid');expect($s->records['1']['review']??FALSE,'manual review');
};
$tests['underpaid_waiting_never_completes']=static function():void {
 $s=seeded();$t=new MockTransport([]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture('underpaid_waiting'),'invoice.underpaid_waiting');
 expect($p->handle($b,signature($b),1700000000)===200,'partial acknowledged');expect(!$s->payments,'partial not paid');
};
$tests['hostile_checkout_redirect_is_rejected']=static function():void {
 $s=new MemoryStore();$data=created();$data['payment_url']='https://checkout.paymos.io.evil.invalid/x';$t=new MockTransport([response($data)]);$c=new CheckoutService($s,new TestClients($t));
 try{$c->start('1','paymos','sandbox');throw new LogicException('hostile URL accepted');}catch(RuntimeException $e){}
 expect(!$s->payments,'no fulfilment');
};
$tests['paid_over_records_only_the_order_amount_once']=static function():void {
 $s=seeded();$i=fixture('paid_over');$t=new MockTransport([response($i)]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody($i,'invoice.paid_over');
 expect($p->handle($b,signature($b),1700000000)===200&&count($s->payments)===1,'verified overpayment completes order once');
};
$tests['late_payment_never_fulfils_and_is_noted_once']=static function():void {
 $s=seeded();$s->records['1']['status']='expired';$i=fixture('expired');$i['paid_late_at']=1700000000;$t=new MockTransport([response($i)]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody($i,'invoice.paid_late');
 expect($p->handle($b,signature($b),1700000000)===200,'late accepted as advisory');expect(!$s->payments&&count($s->notes)===1,'late cannot fulfil');
 expect($p->handle($b,signature($b),1700000000)===200&&count($s->notes)===1,'late duplicate silent');
};
$tests['reverse_mismatch_does_not_poison_retry']=static function():void {
 $s=seeded();$t=new MockTransport([response(fixture('paid','101.00')),response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000000)===409&&!$s->events&&!$s->payments,'API disagreement is retryable with no durable effects');
 expect($p->handle($b,signature($b),1700000000)===200&&count($s->payments)===1,'consistent retry can succeed');
};
$tests['signed_foreign_project_rejected_before_api']=static function():void {
 $s=seeded();$i=fixture();$i['project_id']='prj_other';$t=new MockTransport([]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody($i);
 expect($p->handle($b,signature($b),1700000000)===409&&!$s->payments&&!$t->requests(),'project binding enforced');
};
$tests['signed_foreign_external_order_rejected_before_api']=static function():void {
 $s=seeded();$i=fixture();$i['order']['external_id']='someone_else';$t=new MockTransport([]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody($i);
 expect($p->handle($b,signature($b),1700000000)===409&&!$s->payments&&!$t->requests(),'external order binding enforced');
};
$tests['environment_evidence_must_match_signing_environment']=static function():void {
 $s=seeded();$i=fixture();$i['is_test']=FALSE;$t=new MockTransport([response($i)]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody($i);
 expect($p->handle($b,signature($b),1700000000)===409&&!$s->payments,'live payload cannot fulfil sandbox intent');
};
$tests['expired_timestamp_rejected_without_api']=static function():void {
 $s=seeded();$t=new MockTransport([]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000301)===401&&!$t->requests()&&!$s->payments,'old signed replay rejected');
};
$tests['different_event_id_cannot_credit_same_invoice_again']=static function():void {
 $s=seeded();$t=new MockTransport([response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000000)===200,'first paid event');$b=eventBody(fixture(),'invoice.paid','evt_other');
 expect($p->handle($b,signature($b),1700000000)===200&&count($s->payments)===1&&count($t->requests())===1,'invoice guard independent of event dedup');
};
$tests['failure_after_payment_rolls_back_and_retry_recovers']=static function():void {
 $s=seeded();$s->failEvent=TRUE;$t=new MockTransport([response(fixture()),response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$b=eventBody(fixture());
 expect($p->handle($b,signature($b),1700000000)===503&&!$s->payments&&!$s->events&&!$s->records['1']['credited'],'transaction seam rolls all effects back');
 expect($p->handle($b,signature($b),1700000000)===200&&count($s->payments)===1,'recovery credits once');
};
$tests['missed_webhook_reconciliation_uses_the_same_guards']=static function():void {
 $s=seeded();$t=new MockTransport([response(fixture()),response(fixture())]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));
 expect($p->reconcile($s->records['1'])&&count($s->payments)===1,'API reconciliation completes verified invoice');
};
$tests['expired_lease_cannot_replace_a_concurrent_checkout_intent']=static function():void {
 $s=seeded();$s->beforeSecondDurable=static function(MemoryStore $store):void {
   $store->records['1']['external_order_id']='intent_concurrent_winner';
   $store->records['1']['invoice_id']='';$store->records['1']['payment_url']='';
 };
 $t=new MockTransport([response(fixture('expired')),response(created())]);
 $c=new CheckoutService($s,new TestClients($t));
 try{$c->start('1','paymos','sandbox');throw new LogicException('concurrent intent was overwritten');}
 catch(\Drupal\commerce_paymos\Service\PaymentBusyException $e){}
 expect($s->records['1']['external_order_id']==='intent_concurrent_winner','concurrent winner remains durable');
 expect(count($t->requests())===1,'no second create after another worker won renewal');
};
$failed=0;foreach($tests as $name=>$test){try{$test();echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".get_class($e).' '.$e->getMessage()."\n";}}
echo count($tests)." isolated service tests; native platform NOT claimed\n";exit($failed?1:0);
