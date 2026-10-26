<?php

declare(strict_types=1);

/**
 * Run only through Drush in the disposable remote-CI installation.
 * No HTTP server, real credentials or real payment API is used.
 */
use Drupal\Core\Lock\NullLockBackend;
use Drupal\commerce_order\Entity\{Order, OrderItem, OrderItemType};
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_paymos\Service\{ClientFactoryInterface, EventStore, InvoiceStore, PaymentProcessor};
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Paymos\{Client, ClientConfig};
use Paymos\Http\{HttpResponse, TransportInterface};

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('PAYMOS_NATIVE_CI') !== '1') {
  throw new RuntimeException('This fixture requires the disposable remote CI site');
}
$phase = getenv('PAYMOS_NATIVE_PHASE');
if (!in_array($phase, ['same', 'different'], TRUE)) { throw new RuntimeException('Invalid fixture phase'); }
$action = getenv('PAYMOS_NATIVE_ACTION');
$stateKey = 'commerce_paymos.native.' . $phase;
$database = \Drupal::database();
$entities = \Drupal::entityTypeManager();
$time = \Drupal::time();
// Exercise authoritative SQL serialization even when every fast lease is absent.
$locks = new NullLockBackend();
$store = new InvoiceStore($database, $entities, $locks, $time, \Drupal::service('commerce_payment.order_updater'));

if ($action === 'setup') {
  $anonymous = new \Drupal\Core\Session\AnonymousUserSession();
  $access = \Drupal::service('access_manager')->checkNamedRoute('commerce_paymos.connect', [], $anonymous, TRUE);
  if ($access->isAllowed()) { throw new RuntimeException('Anonymous admin access was allowed'); }
  $switcher = \Drupal::service('account_switcher');
  $switcher->switchTo(\Drupal\user\Entity\User::load(1));
  try {
    $form = \Drupal::formBuilder()->getForm(\Drupal\commerce_paymos\Form\ConnectForm::class);
    if (empty($form['form_token']) || empty($form['actions']['connect'])) {
      throw new RuntimeException('Native authenticated Form API did not build its CSRF-protected Connect form');
    }
    require_once __DIR__ . '/connect-flow.php';
    paymos_native_connect_flow();
  }
  finally { $switcher->switchBack(); }
  \Drupal::service('commerce_price.currency_importer')->import('USD');
  $shop = Store::create(['type' => 'online', 'uid' => 1, 'name' => 'Native CI fixture',
    'mail' => 'native@example.invalid', 'default_currency' => 'USD', 'timezone' => 'UTC',
    'address' => ['country_code' => 'US'], 'billing_countries' => ['US'], 'is_default' => TRUE]);
  $shop->save();
  if (!OrderItemType::load('paymos_native')) {
    OrderItemType::create(['id' => 'paymos_native', 'label' => 'Native fixture', 'orderType' => 'default'])->save();
  }
  if (!PaymentGateway::load('paymos_native')) {
    PaymentGateway::create(['id' => 'paymos_native', 'label' => 'Paymos native fixture',
      'plugin' => 'paymos', 'configuration' => ['mode' => 'test']])->save();
  }
  $item = OrderItem::create(['type' => 'paymos_native', 'quantity' => 1, 'unit_price' => new Price('100', 'USD')]);
  $item->save();
  $order = Order::create(['type' => 'default', 'store_id' => $shop->id(), 'order_items' => [$item],
    'payment_gateway' => 'paymos_native', 'state' => 'draft']);
  $order->save();
  $record = ['order_id' => (string) $order->id(), 'gateway_id' => 'paymos_native', 'environment' => 'sandbox',
    'project_id' => 'prj_native', 'external_order_id' => 'intent_native_' . $phase,
    'amount' => '100', 'currency' => 'USD', 'invoice_id' => 'inv_native_' . $phase,
    'payment_url' => 'https://checkout.paymos.io/inv_native_' . $phase,
    'status' => 'awaiting_payment', 'credited' => FALSE, 'late_noted' => FALSE];
  $store->save($record);
  \Drupal::state()->set($stateKey, $record);
  print "PASS native fixture created: $phase\n";
  return;
}

$record = \Drupal::state()->get($stateKey);
if (!is_array($record)) { throw new RuntimeException('Run fixture setup first'); }
$invoice = ['invoice_id' => $record['invoice_id'], 'project_id' => 'prj_native', 'is_test' => TRUE,
  'status' => 'paid', 'order' => ['external_id' => $record['external_order_id'], 'amount' => '100', 'currency' => 'USD']];

if ($action === 'deliver') {
  $barrier = getenv('PAYMOS_NATIVE_BARRIER');
  $deadline = microtime(TRUE) + 30;
  while (!is_file($barrier)) {
    if (microtime(TRUE) >= $deadline) { throw new RuntimeException('Fixture barrier timed out'); }
    usleep(10000);
  }
  $eventId = getenv('PAYMOS_NATIVE_EVENT');
  if (!preg_match('/^evt_native_[a-z0-9_]+$/D', $eventId)) { throw new RuntimeException('Invalid fixture event'); }
  $clientFactory = new class($invoice) implements ClientFactoryInterface {
    public function __construct(private array $invoice) {}
    public function configuration(string $environment): array {
      return ['project_id' => 'prj_native', 'webhook_secret' => $environment === 'sandbox' ? 'whsec_native' : 'whsec_other'];
    }
    public function create(string $environment): Client {
      $transport = new class($this->invoice) implements TransportInterface {
        public function __construct(private array $invoice) {}
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
          if ($method !== 'GET' || !str_ends_with($url, '/invoices/' . $this->invoice['invoice_id'])) {
            throw new RuntimeException('Unexpected native fixture API request');
          }
          usleep(250000);
          return new HttpResponse(200, json_encode($this->invoice, JSON_THROW_ON_ERROR), []);
        }
      };
      return new Client(new ClientConfig('pk_test_native', 'sk_test_native', 'https://api.example.invalid'), $transport);
    }
  };
  $processor = new PaymentProcessor($store, $clientFactory, new EventStore($store, $locks));
  for ($attempt = 0; $attempt < 30; $attempt++) {
    $now = time();
    $body = json_encode(['event_id' => $eventId, 'event_type' => 'invoice.paid', 'version' => 1,
      'occurred_at' => $now, 'data' => $invoice], JSON_THROW_ON_ERROR);
    $result = $processor->handle($body, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec_native'), $now);
    if ($result === 200) {
      print "PASS native worker: $phase / $eventId / attempt $attempt\n";
      return;
    }
    if (!in_array($result, [409, 503], TRUE)) { throw new RuntimeException('Unexpected native rejection: ' . $result); }
    usleep(100000);
  }
  throw new RuntimeException('Native delivery did not converge');
}

if ($action === 'verify') {
  $payments = $entities->getStorage('commerce_payment')->loadByProperties(['order_id' => $record['order_id']]);
  $order = Order::load($record['order_id']);
  $eventCount = (int) $database->select('commerce_paymos_event', 'e')->condition('event_id',
    $database->escapeLike('evt_native_' . $phase . '_') . '%', 'LIKE')->countQuery()->execute()->fetchField();
  $expected = $phase === 'same' ? 1 : 5;
  if (count($payments) !== 1 || !$order->isPaid() || $order->getState()->getId() === 'draft'
    || empty($store->load($record['order_id'])['credited']) || $eventCount !== $expected) {
    throw new RuntimeException('Native concurrency invariant failed: payments=' . count($payments) . ', events=' . $eventCount);
  }
  $payment = reset($payments);
  if (!$payment->getAmount()->equals(new Price('100', 'USD')) || $payment->getPaymentGatewayMode() !== 'test') {
    throw new RuntimeException('Native payment amount or environment mismatch');
  }
  print "PASS native MySQL concurrency without fast leases: $phase / 5 workers / 1 payment / $eventCount events\n";
  return;
}
throw new RuntimeException('Unknown fixture action');
