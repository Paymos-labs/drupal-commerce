<?php

declare(strict_types=1);

/**
 * Contract: run with Drush php:script on an approved disposable remote site.
 * PAYMOS_REFUND_NATIVE_STAND_APPROVED=1; PAYMOS_REFUND_NATIVE_PAYMENT_ID identifies
 * a single, completed Paymos payment for an already paid native order of 100 USD.
 * Its InvoiceStore binding must be credited, unambiguous and unchanged.
 * Uses real Drupal services/entities/SQL and mock SDK HTTP; no real cash API.
 * Keep stdout as evidence together with composer.lock and the module revision.
 */
use Drupal\commerce_paymos\Service\{ClientFactoryInterface, RefundAdapter, RefundIntentStore, RefundNativeGateway};
use Paymos\{Client, ClientConfig};
use Paymos\Http\{HttpResponse, TransportInterface};
use Paymos\Plugin\CredentialSet;

$scenarios = ['native-over20-full100','native-reorg-recompletion','native-upgrade-schema',
  'native-protected-form-csrf-permissions','native-crash-before-after-commit',
  'native-core-mail-stock-boundary','native-reinstall-history'];
if (getenv('PAYMOS_REFUND_NATIVE_STAND_APPROVED') !== '1' || !class_exists('Drupal') || !\Drupal::hasContainer()) {
  foreach ($scenarios as $scenario) { echo 'SKIP ' . $scenario . ': approved Drupal Commerce 3 stand/bootstrap unavailable' . "\n"; }
  echo '0 passed; 0 failed; ' . count($scenarios) . " skipped; no native version proof\n";
  return;
}

\Drupal\commerce_paymos\SdkBootstrap::register();
$versions = [];
foreach (['drupal/core','drupal/commerce'] as $package) {
  if (!class_exists('Composer\\InstalledVersions') || !\Composer\InstalledVersions::isInstalled($package)) {
    throw new RuntimeException('Native package version evidence unavailable: ' . $package);
  }
  $versions[$package] = \Composer\InstalledVersions::getPrettyVersion($package);
}
echo json_encode(['packages'=>$versions,'php'=>PHP_VERSION,'driver'=>\Drupal::database()->driver()],JSON_UNESCAPED_SLASHES) . "\n";
if (strpos(ltrim($versions['drupal/commerce'],'v'),'3.') !== 0) { throw new RuntimeException('This fixture requires Commerce 3'); }
$key = (string)getenv('PAYMOS_REFUND_NATIVE_PAYMENT_ID');
if ($key === '') { throw new RuntimeException('Seeded native payment ID required'); }
$store = \Drupal::service('commerce_paymos.refund_store');
$native = \Drupal::service('commerce_paymos.refund_native');
$native->assertStorage(); $snapshot = $native->snapshot($key);
if ($snapshot['amount_minor'] !== '10000' || $snapshot['currency'] !== 'USD' || $store->forPayment($key)) {
  throw new RuntimeException('Fixture requires a fresh paid 100 USD native payment');
}

final class NativeRefundTransport implements TransportInterface {
  public $refunds = [];
  public $posts = 0;
  private $snapshot;
  public function __construct(array $snapshot) { $this->snapshot = $snapshot; }
  public function request($method,$url,array $headers,$body,$timeoutSeconds) {
    if (\Drupal::database()->inTransaction()) { throw new RuntimeException('Native transaction crossed HTTP'); }
    if ($method === 'POST') {
      $this->posts++; $input = json_decode($body,TRUE,512,JSON_THROW_ON_ERROR); $key = $input['external_order_id'];
      if (!isset($this->refunds[$key])) {
        $intent = \Drupal::service('commerce_paymos.refund_store')->findByExternalId($key);
        if (!$intent || $intent->cashState() !== 'unknown') { throw new RuntimeException('Intent was not committed before cash'); }
        $this->refunds[$key] = ['withdrawal_id'=>'wd_native_' . count($this->refunds), 'invoice_id'=>$this->snapshot['invoice_id'],
          'project_id'=>$this->snapshot['project_id'],'is_test'=>$this->snapshot['environment'] === 'sandbox',
          'external_order_id'=>$key,'mode'=>$input['mode'],'amount'=>$input['mode'] === 'full' ? '100' : '20',
          'currency'=>'USDT','network'=>'TRC20','first_sender'=>'native_fixture_sender','status'=>'created'];
      }
      $data = $this->refunds[$key];
    }
    elseif (strpos($url,'/v1/invoices/') !== FALSE) {
      $data = ['invoice_id'=>$this->snapshot['invoice_id'],'project_id'=>$this->snapshot['project_id'],
        'is_test'=>$this->snapshot['environment'] === 'sandbox','status'=>'paid_over'];
    }
    else {
      $data = NULL;
      foreach ($this->refunds as $refund) { if (substr($url,-strlen($refund['withdrawal_id'])) === $refund['withdrawal_id']) { $data = $refund; } }
      if ($data === NULL) { throw new RuntimeException('Unknown fixture withdrawal'); }
    }
    return new HttpResponse(200,json_encode($data,JSON_THROW_ON_ERROR),[]);
  }
  public function status(string $id, string $status): void { foreach ($this->refunds as &$refund) { if ($refund['withdrawal_id'] === $id) { $refund['status'] = $status; } } }
}
final class NativeRefundClients implements ClientFactoryInterface {
  private $transport;
  private $snapshot;
  public function __construct($transport,array $snapshot) { $this->transport = $transport; $this->snapshot = $snapshot; }
  public function configuration(string $environment): array {
    $prefix = $environment === 'sandbox' ? 'test' : 'live';
    return CredentialSet::normalizeEnvironment($environment,['api_key'=>'pk_' . $prefix . '_fixture',
      'api_secret'=>'sk_' . $prefix . '_fixture','webhook_secret'=>'whsec_native_fixture','project_id'=>$this->snapshot['project_id'],
      'capabilities'=>['invoice_refunds_v1'],'refunds_enabled'=>TRUE,'refund_contract_version'=>1]);
  }
  public function create(string $environment): Client {
    $config = $this->configuration($environment);
    return new Client(new ClientConfig($config['api_key'],$config['api_secret'],'https://api.paymos.invalid'),$this->transport);
  }
}
function nativeRefundAssert(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$transport = new NativeRefundTransport($snapshot);
$adapter = new RefundAdapter($store,$native,new NativeRefundClients($transport,$snapshot),\Drupal::service('config.factory'));
$orderStorage = \Drupal::entityTypeManager()->getStorage('commerce_order');
$order = $orderStorage->load($snapshot['order_id']); $state = $order->getState()->getId();
$over = $adapter->request($key,'overpayment',\Drupal::service('uuid')->generate());
$transport->status($over->withdrawalId(),'completed'); $adapter->reconcile($over->operationId()); $adapter->reconcile($over->operationId());
$orderStorage->resetCache([$snapshot['order_id']]); $order = $orderStorage->load($snapshot['order_id']);
nativeRefundAssert($order->isPaid() && $order->getState()->getId() === $state,'Overpayment changed paid order state');
nativeRefundAssert(\Paymos\Plugin\AmountGuard::amountsEqual($native->payment($key)->getRefundedAmount()->getNumber(),'0'),'Overpayment changed native refunded amount');
$notes = $order->getData('paymos_refund_notes',[]);
nativeRefundAssert(isset($notes[$over->operationId()]) && count($notes) === 1,'Overpayment note duplicated');
$full = $adapter->request($key,'full',\Drupal::service('uuid')->generate());
$transport->status($full->withdrawalId(),'completed'); $adapter->reconcile($full->operationId()); $adapter->reconcile($full->operationId());
nativeRefundAssert($native->payment($key)->getState()->getId() === 'refunded','Full not projected');
nativeRefundAssert(\Paymos\Plugin\AmountGuard::amountsEqual($native->payment($key)->getRefundedAmount()->getNumber(),'100'),'Full original total mismatch');
nativeRefundAssert($transport->posts === 2,'Unexpected additional cash POST');
echo "PASS native-over20-full100\n";
$marker = $store->marker($full->operationId());
$transport->status($full->withdrawalId(),'signed'); $adapter->reconcile($full->operationId());
nativeRefundAssert($native->payment($key)->getState()->getId() === 'completed','Owned projection did not rewind');
$transport->status($full->withdrawalId(),'completed'); $adapter->reconcile($full->operationId());
nativeRefundAssert($store->marker($full->operationId()) === $marker && $transport->posts === 2,'Recompletion duplicated operation');
echo "PASS native-reorg-recompletion\n";
foreach (array_slice($scenarios,2) as $scenario) {
  echo 'SKIP ' . $scenario . ': requires separate upgrade copy, concurrent process/crash injection or observed native Form API/mail/stock instrumentation' . "\n";
}
echo "2 passed; 0 failed; 5 skipped; package versions recorded above\n";
