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
  public array $history = [], $records = [], $events = [], $payments = [], $notes = [];
  public bool $failEvent = FALSE;
  public ?Closure $beforeSecondDurable = NULL;
  private int $durableCalls = 0;
  public array $order = ['order_id'=>'1','uuid'=>'order-one','gateway_id'=>'paymos','amount'=>'100.00','currency'=>'USD','paid'=>FALSE,'cancelled'=>FALSE];
  public function synchronized(string $orderId, callable $action): mixed { return $action(); }
  public function transaction(string $orderId, callable $action, bool $durable=FALSE): mixed {
    if ($durable && ++$this->durableCalls === 2 && $this->beforeSecondDurable) {
      ($this->beforeSecondDurable)($this);
    }
    $before=[$this->records,$this->events,$this->payments,$this->notes,$this->history];
    try { return $action(); } catch(Throwable $e) { [$this->records,$this->events,$this->payments,$this->notes,$this->history]=$before;throw $e; }
  }
  public function snapshot(string $orderId): array { return $this->order; }
  public function load(string $orderId): ?array { return $this->records[$orderId]??NULL; }
  public function byInvoice(string $invoiceId): ?array { foreach($this->records as $r) { if(($r['invoice_id']??'')===$invoiceId)return $r; } return $this->history[$invoiceId]??NULL; }
  public function byPreviousInvoice(string $invoiceId): ?array { foreach($this->records as $r) { if(isset($r['history'][$invoiceId]))return $r; } return NULL; }
  public function save(array $record): void { $this->records[$record['order_id']]=$record; }
  public function saveHistory(array $record): void { $this->history[$record['invoice_id']]=$record; }
  public function isCommitted(string $eventId): bool { return isset($this->events[$eventId]); }
  public function commitEvent(string $eventId): void { if($this->failEvent){$this->failEvent=FALSE;throw new RuntimeException("fixture_crash_before_event_commit");} $this->events[$eventId]=TRUE; }
  public function complete(array $record,array $invoice): void { $this->payments[]=$record['invoice_id']; }
  public function note(string $orderId,string $code): void { $this->notes[]=$code; }
  public function pending(int $since,int $limit): array { return array_slice(array_values($this->records),0,$limit); }
  public function markChecked(string $orderId, string $invoiceId = ''): void {}
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
 expect($p->handle($b,signature($b),1700000000)===409,'mismatch remains retryable');expect(!$s->payments,'mismatch not paid');expect($s->records['1']['review']??FALSE,'manual review');
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
 expect($p->handle($b,signature($b),1700000000)===500&&!$s->payments&&!$s->events&&!$s->records['1']['credited'],'transaction seam rolls all effects back and reports an internal error');
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
$tests['review_reconciliation_recovers_including_previously_committed_event']=static function():void {
 foreach([FALSE,TRUE] as $legacy) {
  $s=seeded();$s->order['amount']='101';
  $t=new MockTransport(array_fill(0,8,response(fixture())));
  $p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));
  expect(!$p->reconcile($s->records['1'])&&!$s->payments,'review is not applied');
  $eventId='reconcile_'.hash('sha256','inv_fixture:invoice.paid');
  expect(!$s->isCommitted($eventId),'failed review does not commit synthetic event');
  if($legacy) { $s->events[$eventId]=TRUE; }
  $s->order['amount']='100';
  expect($p->reconcile($s->records['1']),'corrected order reconciles');
  expect($s->payments===['inv_fixture']&&!$s->records['1']['review'],'one native payment clears review');
  expect($p->reconcile($s->records['1'])&&count($s->payments)===1,'repeat cannot pay twice');
 }
};
$tests['review_webhook_retries_same_event_after_correction']=static function():void {
 $s=seeded();$s->order['amount']='101';$t=new MockTransport(array_fill(0,3,response(fixture())));
 $p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));$body=eventBody(fixture());
 expect($p->handle($body,signature($body),1700000000)===409,'review requests retry');
 expect($p->handle($body,signature($body),1700000000)===409&&count($s->notes)===1,'review note not duplicated');
 $s->order['amount']='100';expect($p->handle($body,signature($body),1700000000)===200,'same event recovers');
 expect($s->payments===['inv_fixture'],'native payment once');
};
$tests['renewal_keeps_old_binding_for_late_callback_and_reconciliation']=static function():void {
 foreach([FALSE,TRUE] as $reconcile) {
  $s=seeded();$old=fixture('expired');$new=created();$new['invoice_id']='inv_new';$new['payment_url']='https://checkout.paymos.io/inv_new';
  $checkout=new CheckoutService($s,new TestClients(new MockTransport([response($old),response($new)])));
  expect($checkout->start('1','paymos','sandbox')===$new['payment_url'],'checkout renews expired invoice');
  expect($s->byInvoice('inv_fixture')['external_order_id']==='intent_fixture','historical identity retained');
  $s->records['1']['credited']=TRUE;$s->order['paid']=TRUE;$s->order['amount']='0';$current=$s->records['1'];
  $old['paid_late_at']=1700000000;
  $t=new MockTransport(array_fill(0,4,response($old)));$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));
  if($reconcile) { expect($p->reconcile($s->byInvoice('inv_fixture')),'history reconciles late payment'); }
  else { $b=eventBody($old,'invoice.paid_late');expect($p->handle($b,signature($b),1700000000)===200,'historical late callback accepted'); }
  $b=eventBody($old,'invoice.paid_late','evt_second_late');expect($p->handle($b,signature($b),1700000000)===200,'second delivery accepted');
  expect($s->notes===['paymos_paid_late']&&!$s->payments,'one advisory note and no native payment');
  expect($s->records['1']===$current,'current paid invoice remains unchanged');
  expect($s->history['inv_fixture']['late_noted'],'historical note marker persists');
 }
};
$tests['historical_event_requires_api_identity_and_never_applies_payment']=static function():void {
 $s=seeded();$s->saveHistory($s->records['1']);$s->records['1']['invoice_id']='inv_new';
 $i=fixture('expired');$i['paid_late_at']=1700000000;$bad=$i;$bad['order']['external_id']='other';
 $t=new MockTransport([response($bad)]);$p=new PaymentProcessor($s,new TestClients($t),new TestEvents($s));
 $b=eventBody($i,'invoice.paid_late');expect($p->handle($b,signature($b),1700000000)===409,'historical API mismatch rejected');
 $b=eventBody(fixture());expect($p->handle($b,signature($b),1700000000)===409,'historical normal payment cannot alter current order');
 expect(!$s->notes&&!$s->payments,'no mutation from invalid historical evidence');
};
$tests['late_payment_for_previous_generation_is_noted_once_without_touching_current_payment']=static function():void {
 // 48-01: the invoice expires, a new generation is issued for the order; the
 // expired invoice then reports money that arrived after it closed.
 $s=seeded();
 $new=['invoice_id'=>'inv_new','payment_url'=>'https://checkout.paymos.io/inv_new','status'=>'awaiting_client'];
 $t=new MockTransport([response(fixture('expired')),response($new)]);
 expect((new CheckoutService($s,new TestClients($t)))->start('1','paymos','sandbox')==='https://checkout.paymos.io/inv_new','renewal issued');
 $current=$s->records['1'];
 expect($current['invoice_id']==='inv_new' && $current['external_order_id']!=='intent_fixture','current binding is the new generation');
 expect(isset($current['history']['inv_fixture']),'the replaced binding is kept');
 $late=fixture('expired');$late['paid_late_at']=1700000000;
 $t2=new MockTransport([response($late)]);$p=new PaymentProcessor($s,new TestClients($t2),new TestEvents($s));
 $b=eventBody($late,'invoice.paid_late','evt_late_previous');
 expect($p->handle($b,signature($b),1700000000)===200,'late notice of the previous generation accepted');
 expect($s->notes===['paymos_paid_late'] && !$s->payments,'one note, no payment');
 expect($s->records['1']['invoice_id']==='inv_new' && $s->records['1']['external_order_id']===$current['external_order_id'] && empty($s->records['1']['credited']),'current binding untouched');
 expect($p->handle($b,signature($b),1700000000)===200 && count($s->notes)===1 && count($t2->requests())===1,'duplicate delivery adds nothing');
 $b2=eventBody($late,'invoice.paid_late','evt_late_previous_again');
 expect($p->handle($b2,signature($b2),1700000000)===200 && count($s->notes)===1,'a second late event id for the same invoice adds nothing');
 $paid=eventBody(fixture('paid'),'invoice.paid','evt_previous_paid');
 expect($p->handle($paid,signature($paid),1700000000)===409 && !$s->payments,'a closed generation is never credited');
};
$tests['composer_requires_php_sdk_1_12_0']=static function():void {
 $composer=json_decode((string)file_get_contents(dirname(__DIR__).'/composer.json'),TRUE,512,JSON_THROW_ON_ERROR);
 expect(($composer['require']['paymos/php-sdk']??'')==='^1.12.0','composer.json must require paymos/php-sdk ^1.12.0');
};
$tests['single_item_with_and_without_optionals_sends_products_and_native_total']=static function():void {
 $s=new MemoryStore();
 $s->order['amount']='47.00';
 $s->order['items']=[
  ['title'=>'<b>Canvas</b> &middot; Blue','description'=>'<p>Studio &quot;Pro&quot;\\v1<br>Line 2</p>','quantity'=>2,'unit_amount'=>'24.50'],
 ];
 $t=new MockTransport([response(created())]);
 $url=(new CheckoutService($s,new TestClients($t)))->start('1','paymos','sandbox');
 expect($url==='https://checkout.paymos.io/inv_fixture','single product checkout succeeds');
 $sent=json_decode($t->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect($sent['amount']==='47.00','native grand total preserved');
 expect($sent['products']===[[
  'title'=>'Canvas · Blue',
  'quantity'=>2,
  'unit_amount'=>'24.50',
  'description'=>"Studio \"Pro\"\\v1\nLine 2",
 ]],'single item normalized');
 expect($s->records['1']['products']===$sent['products'],'products persisted in InvoiceStore record');

 $s2=new MemoryStore();
 $s2->order['amount']='12.00';
 $s2->order['items']=[
  ['title'=>'Notebook','description'=>'&nbsp;','quantity'=>1,'unit_amount'=>'12.00'],
 ];
 $t2=new MockTransport([response(created())]);
 (new CheckoutService($s2,new TestClients($t2)))->start('1','paymos','sandbox');
 $sent2=json_decode($t2->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect($sent2['products']===[['title'=>'Notebook','quantity'=>1,'unit_amount'=>'12.00']],'blank description omitted per item');
};
$tests['multi_item_cart_excludes_adjustments_and_applies_all_or_nothing_fallback']=static function():void {
 $s=new MemoryStore();
 $s->order['amount']='42.50';
 $s->order['items']=[
  ['title'=>'Canvas','description'=>'Fine art','quantity'=>2,'unit_amount'=>'15.00'],
  ['title'=>'Notebook','quantity'=>1,'unit_amount'=>'12.50'],
  ['title'=>'Shipping','quantity'=>1,'unit_amount'=>'5.00','type'=>'shipping'],
  ['title'=>'Coupon','quantity'=>1,'unit_amount'=>'-5.00','type'=>'discount'],
 ];
 $t=new MockTransport([response(created())]);
 (new CheckoutService($s,new TestClients($t)))->start('1','paymos','sandbox');
 $sent=json_decode($t->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect($sent['amount']==='42.50','native grand total preserved on multi-item cart');
 expect($sent['products']===[
  ['title'=>'Canvas','quantity'=>2,'unit_amount'=>'15.00','description'=>'Fine art'],
  ['title'=>'Notebook','quantity'=>1,'unit_amount'=>'12.50'],
 ],'multi-item cart preserves products and excludes shipping/discount lines');

 // 50 items accepted; 51 items and fractional quantity fall back all-or-nothing to amount-only.
 $s50=new MemoryStore();
 $s50->order['amount']='50.00';
 for($i=1;$i<=50;$i++){$s50->order['items'][]=['title'=>'Item '.$i,'quantity'=>1,'unit_amount'=>'1.00'];}
 $t50=new MockTransport([response(created())]);
 (new CheckoutService($s50,new TestClients($t50)))->start('1','paymos','sandbox');
 $sent50=json_decode($t50->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect(count($sent50['products'])===50,'50 valid items sent');

 foreach(['over50'=>array_merge($s50->order['items'],[['title'=>'Item 51','quantity'=>1,'unit_amount'=>'1.00']]),
  'fractional'=>[['title'=>'Valid','quantity'=>1,'unit_amount'=>'20.00'],['title'=>'Fabric','quantity'=>1.5,'unit_amount'=>'10.00']]] as $case=>$items){
  $sf=new MemoryStore();$sf->order['amount']='35.00';$sf->order['items']=$items;
  $tf=new MockTransport([response(created())]);
  (new CheckoutService($sf,new TestClients($tf)))->start('1','paymos','sandbox');
  $sentF=json_decode($tf->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
  expect(!array_key_exists('products',$sentF) && !array_key_exists('products',$sf->records['1']),$case.' falls back all-or-nothing to amount-only');
 }
};
$tests['lost_response_retry_replays_persisted_products_and_supports_legacy_records']=static function():void {
 $s=new MemoryStore();
 $s->order['amount']='42.50';
 $s->order['items']=[
  ['title'=>'Edition "Alpha" \\ &lt;1&gt;','description'=>"Line 1\nLine 2 — Ω 🚀",'quantity'=>2,'unit_amount'=>'15.00'],
  ['title'=>'Notebook','quantity'=>1,'unit_amount'=>'12.50'],
 ];
 $t=new MockTransport([response(['title'=>'temporary'],503),response(created())]);
 $c=new CheckoutService($s,new TestClients($t));
 try{$c->start('1','paymos','sandbox');throw new RuntimeException('Expected API failure');}catch(\Paymos\Exception\ApiException $e){}
 $persistedProducts=$s->records['1']['products'];
 expect(count($persistedProducts)===2,'products persisted in record before HTTP');

 // Mutate order items (keeping total 42.50) before retry.
 $s->order['items']=[['title'=>'Replaced Item','quantity'=>1,'unit_amount'=>'42.50']];
 $url=$c->start('1','paymos','sandbox');
 expect($url==='https://checkout.paymos.io/inv_fixture','retry recovers checkout URL');
 $req1=json_decode($t->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 $req2=json_decode($t->requests()[1]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect($req1===$req2,'retry sends identical payload despite changed order items');
 expect($req2['products']===$persistedProducts,'original products[] A preserved across retry');
 expect($req2['products'][0]['title']==='Edition "Alpha" \\ <1>' && $req2['products'][0]['description']==="Line 1\nLine 2 — Ω 🚀",'special chars and Unicode preserved');

 // Legacy record without products key replays amount-only safely.
 $legacyStore=new MemoryStore();
 $legacyStore->save(['order_id'=>'1','gateway_id'=>'paymos','environment'=>'sandbox','project_id'=>'prj_fixture',
  'external_order_id'=>'intent_legacy','amount'=>'100.00','currency'=>'USD','invoice_id'=>'','payment_url'=>'',
  'status'=>'','credited'=>FALSE,'late_noted'=>FALSE]);
 $legacyTransport=new MockTransport([response(created())]);
 (new CheckoutService($legacyStore,new TestClients($legacyTransport)))->start('1','paymos','sandbox');
 $legacySent=json_decode($legacyTransport->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect(!array_key_exists('products',$legacySent),'legacy record without products remains amount-only');
};
$tests['historical_record_retry_removes_only_image_key_without_rewriting_record']=static function():void {
 $s=new MemoryStore();
 $historical=[
  ['title'=>'  <b>Frozen original</b>  ','description'=>"  Line 1\nLine 2 — Ω 🚀  ",'image_url'=>'https://shop.example/legacy.webp','quantity'=>2,'unit_amount'=>'12345678901234567890.123456789012345678'],
  ['title'=>'Support','quantity'=>1,'unit_amount'=>'0.00','image_url'=>NULL],
 ];
 $record=['order_id'=>'1','gateway_id'=>'paymos','environment'=>'sandbox','project_id'=>'prj_fixture',
  'external_order_id'=>'intent_historical','amount'=>'100.00','currency'=>'USD','invoice_id'=>'','payment_url'=>'',
  'status'=>'','credited'=>FALSE,'late_noted'=>FALSE,'products'=>$historical,
  'history'=>['inv_previous'=>['external_order_id'=>'intent_previous','products'=>$historical]]];
 $s->save($record);
 $recordBytes=serialize($record);
 $s->order['items']=[['title'=>'Changed native item','quantity'=>1,'unit_amount'=>'100.00']];
 $t=new MockTransport([response(['title'=>'temporary'],503)]);
 try{(new CheckoutService($s,new TestClients($t)))->start('1','paymos','sandbox');throw new LogicException('Expected API failure');}catch(\Paymos\Exception\ApiException $e){}
 $expectedProducts=$historical;
 unset($expectedProducts[0]['image_url'],$expectedProducts[1]['image_url']);
 $outbound=json_decode($t->requests()[0]['body'],TRUE,512,JSON_THROW_ON_ERROR);
 expect($outbound===['project_id'=>'prj_fixture','external_order_id'=>'intent_historical','amount'=>'100.00','currency'=>'USD','products'=>$expectedProducts],'retry projects exactly one retired key and retains all frozen values and identity');
 expect(serialize($s->records['1'])===$recordBytes,'retry must not rewrite record.products, prior history or any stored snapshot value');

 $s->order['amount']='101.00';
 $blocked=new MockTransport([]);
 try{(new CheckoutService($s,new TestClients($blocked)))->start('1','paymos','sandbox');throw new LogicException('Changed money must be blocked');}catch(RuntimeException $e){}
 expect(!$blocked->requests() && serialize($s->records['1'])===$recordBytes,'legacy media compatibility cannot bypass money guards or create a replacement');
};
$tests['old_preloaded_sdk_zip_falls_back_to_amount_only']=static function()use($root,$sdk):void {
 foreach(['missing_class','missing_method'] as $mode){
  $code='<?php declare(strict_types=1);
   $root='.var_export($root,TRUE).';
   $sdk='.var_export($sdk,TRUE).';
   spl_autoload_register(static function(string $class)use($root,$sdk):void{
    if($class==="Paymos\\\\Plugin\\\\InvoicePresentation"){
     if('.var_export($mode,TRUE).'==="missing_method"){eval("namespace Paymos\\\\Plugin; final class InvoicePresentation {}");}
     return;
    }
    foreach(["Paymos\\\\"=>$sdk."/","Drupal\\\\commerce_paymos\\\\"=>$root."/src/"] as $prefix=>$base){
     if(str_starts_with($class,$prefix)){
      $file=$base.str_replace("\\\\","/",substr($class,strlen($prefix))).".php";
      if(is_file($file)){require_once $file;}
     }
    }
   });
   $store=new class implements \\Drupal\\commerce_paymos\\Service\\PaymentStoreInterface {
    public array $records=[];
    public function synchronized(string $id, callable $a){return $a();}
    public function transaction(string $id, callable $a, bool $d=FALSE){return $a();}
    public function snapshot(string $id):array{return ["order_id"=>"1","uuid"=>"u","gateway_id"=>"paymos","amount"=>"24.50","currency"=>"USD","paid"=>FALSE,"cancelled"=>FALSE,"items"=>[["title"=>"Studio Pro","quantity"=>1,"unit_amount"=>"24.50"]]];}
    public function load(string $id):?array{return $this->records[$id]??NULL;}
    public function byInvoice(string $i):?array{return NULL;}
    public function byPreviousInvoice(string $i):?array{return NULL;}
    public function save(array $r):void{$this->records[$r["order_id"]]=$r;}
    public function saveHistory(array $r):void{}
    public function isCommitted(string $e):bool{return FALSE;}
    public function commitEvent(string $e):void{}
    public function complete(array $r,array $i):void{}
    public function note(string $o,string $c):void{}
    public function pending(int $s,int $l):array{return [];}
    public function markChecked(string $o,string $i=""):void{}
   };
   $t=new \\Paymos\\Http\\MockTransport([new \\Paymos\\Http\\HttpResponse(201,json_encode(["invoice_id"=>"inv_zip","payment_url"=>"https://checkout.paymos.io/inv_zip","status"=>"awaiting_client"]),[])]);
   $clients=new class($t) implements \\Drupal\\commerce_paymos\\Service\\ClientFactoryInterface {
    public function __construct(private \\Paymos\\Http\\MockTransport $t){}
    public function configuration(string $e):array{return ["project_id"=>"prj_zip","webhook_secret"=>"whsec"];}
    public function create(string $e):\\Paymos\\Client{return new \\Paymos\\Client(new \\Paymos\\ClientConfig("pk","sk","https://api.paymos.invalid"),$this->t);}
   };
   $url=(new \\Drupal\\commerce_paymos\\Service\\CheckoutService($store,$clients))->start("1","paymos","sandbox");
   $sent=json_decode($t->requests()[0]["body"],TRUE,512,JSON_THROW_ON_ERROR);
   if($url!=="https://checkout.paymos.io/inv_zip"||array_key_exists("products",$sent)||array_key_exists("products",$store->records["1"])){exit(2);}
  ';
  $proc=proc_open(escapeshellarg(PHP_BINARY),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  expect(is_resource($proc),'could not spawn PHP for '.$mode);
  fwrite($pipes[0],$code);fclose($pipes[0]);
  $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
  $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
  $status=proc_close($proc);
  expect($status===0,"old ZIP fallback ($mode) failed ($status): $out $err");
 }
};
$tests['invoice_store_snapshot_extracts_items_and_persists_products_json']=static function()use($root,$sdk):void {
 $code='<?php declare(strict_types=1);
  $root='.var_export($root,TRUE).';
  $sdk='.var_export($sdk,TRUE).';
  spl_autoload_register(static function(string $class)use($root,$sdk):void{
   foreach(["Paymos\\\\"=>$sdk."/","Drupal\\\\commerce_paymos\\\\"=>$root."/src/"] as $prefix=>$base){
    if(str_starts_with($class,$prefix)){
     $file=$base.str_replace("\\\\","/",substr($class,strlen($prefix))).".php";
     if(is_file($file)){require_once $file;}
    }
   }
  });
  eval(<<<\'STUBS\'
  namespace Drupal\Component\Datetime { interface TimeInterface { public function getCurrentTime(): int; } }
  namespace Drupal\Core\Lock { interface LockBackendInterface { public function acquire($n,$t=30.0); public function release($n); } }
  namespace Drupal\Core\KeyValueStore { interface KeyValueFactoryInterface {} interface KeyValueStoreInterface {} }
  namespace Drupal\commerce_payment { interface PaymentOrderUpdaterInterface {} }
  namespace Drupal\commerce_price { final class Price { public function __construct(private string $n,private string $c){} public function getNumber():string{return $this->n;} public function getCurrencyCode():string{return $this->c;} } }
  namespace Drupal\commerce_order\Entity { interface OrderInterface {} }
  namespace Drupal\Core\Entity { interface EntityTypeManagerInterface { public function getStorage($id); } }
  namespace Drupal\Core\Database { class Connection {} }
STUBS
  );
  $db=new class extends \Drupal\Core\Database\Connection {
   public array $rows=[];
   public function inTransaction():bool{return FALSE;}
   public function startTransaction(){return new class{public function rollBack(){}};}
   public function select($t,$a=null){
    $db=$this;
    return new class($db,$t) {
     private ?string $orderId=NULL;
     public function __construct(private $db,private string $t){}
     public function fields($a,$f){return $this;}
     public function condition($c,$v){$this->orderId=(string)$v;return $this;}
     public function forUpdate(){return $this;}
     public function execute(){
      $db=$this->db;$t=$this->t;$id=$this->orderId;
      return new class($db,$t,$id) {
       public function __construct(private $db,private string $t,private ?string $id){}
       public function fetchField(){
        if($this->t==="commerce_order"){return $this->id;}
        return $this->db->rows[$this->id]["data"]??FALSE;
       }
      };
     }
    };
   }
   public function merge($t){
    $db=$this;
    return new class($db) {
     private string $k="";private array $f=[];
     public function __construct(private $db){}
     public function key($c,$v){$this->k=(string)$v;return $this;}
     public function fields($f){$this->f=$f;return $this;}
     public function execute(){$this->db->rows[$this->k]=$this->f;}
    };
   }
  };
  $order=new class implements \Drupal\commerce_order\Entity\OrderInterface {
   public array $items=[];
   public string $total="47.00";
   public function id(){return 1;}
   public function uuid(){return "order-uuid-1";}
   public function get($f){return (object)["target_id"=>"paymos"];}
   public function getBalance(){return new \Drupal\commerce_price\Price($this->total,"USD");}
   public function isPaid(){return FALSE;}
   public function getState(){return new class{public function getId(){return "draft";}};}
   public function getItems(){return $this->items;}
  };
  $item1=new class {
   public function getTitle(){return "Studio Pro";}
   public function getQuantity(){return "2.00";}
   public function getUnitPrice(){return new \Drupal\commerce_price\Price("24.50","USD");}
   public function getPurchasedEntity(){return new class {
    public function getDescription(){return "Monthly license";}
   };}
  };
  $order->items=[$item1];
  $entities=new class($order) implements \Drupal\Core\Entity\EntityTypeManagerInterface {
   public function __construct(private $order){}
   public function getStorage($id){
    return new class($this->order) {
     public function __construct(private $order){}
     public function resetCache($ids){}
     public function load($id){return $this->order;}
    };
   }
  };
  $locks=new class implements \Drupal\Core\Lock\LockBackendInterface {
   public function acquire($n,$t=30.0){return TRUE;}
   public function release($n){}
  };
  $time=new class implements \Drupal\Component\Datetime\TimeInterface {
   public function getCurrentTime():int{return 1700000000;}
  };
  $updater=new class implements \Drupal\commerce_payment\PaymentOrderUpdaterInterface {};
  $kv=new class implements \Drupal\Core\KeyValueStore\KeyValueFactoryInterface {
   public function get($c){return new class implements \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    public function setIfNotExists($k,$v){return TRUE;}
   };}
  };
  $store=new \Drupal\commerce_paymos\Service\InvoiceStore($db,$entities,$locks,$time,$updater,$kv);
  $t=new \Paymos\Http\MockTransport([
   new \Paymos\Http\HttpResponse(503,"{\"title\":\"temp\"}",[]),
   new \Paymos\Http\HttpResponse(201,json_encode(["invoice_id"=>"inv_1","payment_url"=>"https://checkout.paymos.io/inv_1","status"=>"awaiting_client"]),[]),
  ]);
  $clients=new class($t) implements \Drupal\commerce_paymos\Service\ClientFactoryInterface {
   public function __construct(private $t){}
   public function configuration(string $e):array{return ["project_id"=>"prj_1","webhook_secret"=>"whsec"];}
   public function create(string $e):\Paymos\Client{return new \Paymos\Client(new \Paymos\ClientConfig("pk","sk","https://api.paymos.invalid"),$this->t);}
  };
  $checkout=new \Drupal\commerce_paymos\Service\CheckoutService($store,$clients);
  try{$checkout->start("1","paymos","sandbox");exit(2);}catch(\Paymos\Exception\ApiException $e){}
  $loaded=$store->load("1");
  if(!is_array($loaded)||!isset($loaded["products"])||count($loaded["products"])!==1||$loaded["products"][0]["quantity"]!==2){exit(3);}
  // Change order item after lost response; retry must send persisted products from InvoiceStore.
  $order->items=[new class {
   public function getTitle(){return "Changed";}
   public function getQuantity(){return "1.00";}
   public function getUnitPrice(){return new \Drupal\commerce_price\Price("47.00","USD");}
  }];
  $url=$checkout->start("1","paymos","sandbox");
  $req1=json_decode($t->requests()[0]["body"],TRUE,512,JSON_THROW_ON_ERROR);
  $req2=json_decode($t->requests()[1]["body"],TRUE,512,JSON_THROW_ON_ERROR);
  if($url!=="https://checkout.paymos.io/inv_1"||$req1!==$req2||$req2["products"][0]["title"]!=="Studio Pro"){exit(4);}

  // Fractional Drupal quantity "1.50" triggers all-or-nothing amount-only fallback;
  // bare OrderItem without title (PaymentKernelTest shape) also falls back safely.
  $db->rows=[];
  $order->items=[
   $item1,
   new class {
    public function getTitle(){return "Bulk";}
    public function getQuantity(){return "1.50";}
    public function getUnitPrice(){return new \Drupal\commerce_price\Price("10.00","USD");}
   },
  ];
  $t2=new \Paymos\Http\MockTransport([
   new \Paymos\Http\HttpResponse(201,json_encode(["invoice_id"=>"inv_2","payment_url"=>"https://checkout.paymos.io/inv_2","status"=>"awaiting_client"]),[]),
  ]);
  (new \Drupal\commerce_paymos\Service\CheckoutService($store,new class($t2) implements \Drupal\commerce_paymos\Service\ClientFactoryInterface {
   public function __construct(private $t){}
   public function configuration(string $e):array{return ["project_id"=>"prj_1","webhook_secret"=>"whsec"];}
   public function create(string $e):\Paymos\Client{return new \Paymos\Client(new \Paymos\ClientConfig("pk","sk","https://api.paymos.invalid"),$this->t);}
  }))->start("1","paymos","sandbox");
  $sent2=json_decode($t2->requests()[0]["body"],TRUE,512,JSON_THROW_ON_ERROR);
  if(array_key_exists("products",$sent2)){exit(5);}

  $db->rows=[];
  $order->items=[new class {
   public function getTitle(){return NULL;}
   public function getQuantity(){return "1.00";}
   public function getUnitPrice(){return new \Drupal\commerce_price\Price("47.00","USD");}
  }];
  $t3=new \Paymos\Http\MockTransport([
   new \Paymos\Http\HttpResponse(201,json_encode(["invoice_id"=>"inv_3","payment_url"=>"https://checkout.paymos.io/inv_3","status"=>"awaiting_client"]),[]),
  ]);
  (new \Drupal\commerce_paymos\Service\CheckoutService($store,new class($t3) implements \Drupal\commerce_paymos\Service\ClientFactoryInterface {
   public function __construct(private $t){}
   public function configuration(string $e):array{return ["project_id"=>"prj_1","webhook_secret"=>"whsec"];}
   public function create(string $e):\Paymos\Client{return new \Paymos\Client(new \Paymos\ClientConfig("pk","sk","https://api.paymos.invalid"),$this->t);}
  }))->start("1","paymos","sandbox");
  $sent3=json_decode($t3->requests()[0]["body"],TRUE,512,JSON_THROW_ON_ERROR);
  if(array_key_exists("products",$sent3)){exit(6);}

  // A product with an unreadable price invalidates the whole cart in either order.
  // Use the real InvoiceStore snapshot and CheckoutService, not MemoryStore candidates.
  $missingPriceItems=[
   "missing_getUnitPrice"=>new class {
    public function getTitle(){return "Missing price method";}
    public function getQuantity(){return "1.00";}
   },
   "null_price"=>new class {
    public function getTitle(){return "NULL price";}
    public function getQuantity(){return "1.00";}
    public function getUnitPrice(){return NULL;}
   },
   "scalar_price"=>new class {
    public function getTitle(){return "Scalar price";}
    public function getQuantity(){return "1.00";}
    public function getUnitPrice(){return "10.00";}
   },
   "missing_getNumber"=>new class {
    public function getTitle(){return "Unreadable price object";}
    public function getQuantity(){return "1.00";}
    public function getUnitPrice(){return new class {
     public function getCurrencyCode(){return "USD";}
    };}
   },
  ];
  $missingPriceFailures=[];
  foreach($missingPriceItems as $priceCase=>$invalidItem){
   foreach(["valid_first","invalid_first"] as $position){
    $case=$priceCase."_".$position;
    $db->rows=[];
    $order->total="47.00";
    $order->items=$position==="valid_first"?[$item1,$invalidItem]:[$invalidItem,$item1];
    $invoiceId="inv_".$case;
    $paymentUrl="https://checkout.paymos.io/".$invoiceId;
    $transport=new \Paymos\Http\MockTransport([
     new \Paymos\Http\HttpResponse(201,json_encode(["invoice_id"=>$invoiceId,"payment_url"=>$paymentUrl,"status"=>"awaiting_client"]),[]),
    ]);
    $caseClients=new class($transport) implements \Drupal\commerce_paymos\Service\ClientFactoryInterface {
     public function __construct(private $transport){}
     public function configuration(string $e):array{return ["project_id"=>"prj_1","webhook_secret"=>"whsec"];}
     public function create(string $e):\Paymos\Client{return new \Paymos\Client(new \Paymos\ClientConfig("pk","sk","https://api.paymos.invalid"),$this->transport);}
    };
    $caseUrl=(new \Drupal\commerce_paymos\Service\CheckoutService($store,$caseClients))->start("1","paymos","sandbox");
    $requests=$transport->requests();
    if(count($requests)!==1||$requests[0]["method"]!=="POST"||$requests[0]["url"]!=="https://api.paymos.invalid/v1/invoices"){
     throw new \RuntimeException($case.": expected exactly one fresh invoice create and no replacement request");
    }
    $sent=json_decode($requests[0]["body"],TRUE,512,JSON_THROW_ON_ERROR);
    $record=$store->load("1");
    if(!is_array($record)||!is_string($record["external_order_id"]??NULL)||$record["external_order_id"]===""){
     throw new \RuntimeException($case.": missing durable invoice intent");
    }
    if(array_key_exists("products",$sent)||array_key_exists("products",$record)){
     $missingPriceFailures[]=$case.": unreadable product price must omit the entire products array from request and record";
    }
    $sentIdentity=$sent;unset($sentIdentity["products"]);
    $recordIdentity=$record;unset($recordIdentity["products"]);
    if($sentIdentity!==["project_id"=>"prj_1","external_order_id"=>$record["external_order_id"],"amount"=>"47.00","currency"=>"USD"]
     ||$recordIdentity!==["order_id"=>"1","gateway_id"=>"paymos","environment"=>"sandbox","project_id"=>"prj_1",
      "external_order_id"=>$record["external_order_id"],"amount"=>"47.00","currency"=>"USD","invoice_id"=>$invoiceId,
      "payment_url"=>$paymentUrl,"status"=>"awaiting_client","credited"=>FALSE,"late_noted"=>FALSE]
     ||count($db->rows)!==1||$caseUrl!==$paymentUrl){
     throw new \RuntimeException($case.": native financial values or durable invoice identity changed");
    }
   }
  }
  if($missingPriceFailures){throw new \RuntimeException(implode("\n",$missingPriceFailures));}
 ';
 $proc=proc_open(escapeshellarg(PHP_BINARY),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 expect(is_resource($proc),'could not spawn PHP for InvoiceStore snapshot test');
 fwrite($pipes[0],$code);fclose($pipes[0]);
 $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $status=proc_close($proc);
 expect($status===0,"InvoiceStore snapshot test failed ($status): $out $err");
};
$failed=0;foreach($tests as $name=>$test){try{$test();echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".get_class($e).' '.$e->getMessage()."\n";}}
echo count($tests)." isolated service tests; native platform NOT claimed\n";
// The Connect fixture declares its own Drupal doubles; a separate process keeps them apart.
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/connect-generation.php'),$connectStatus);
if($connectStatus!==0){$failed++;}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/RefundAdapterTest.php'),$refundStatus);
if($refundStatus!==0){$failed++;}
exit($failed?1:0);
