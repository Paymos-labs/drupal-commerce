<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_paymos\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_order\Entity\{Order, OrderItem};
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_paymos\Form\ConnectForm;
use Drupal\commerce_paymos\Service\{CheckoutService, ClientFactoryInterface, EventStore, PaymentProcessor, PaymentStoreInterface};
use Drupal\commerce_price\Price;
use Paymos\{Client, ClientConfig};
use Paymos\Http\{HttpResponse, MockTransport};
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises actual Commerce entities and Drupal SQL transactions, with mock HTTP.
 *
 * @group commerce_paymos
 */
final class PaymentKernelTest extends OrderKernelTestBase {
  protected static $modules = ['commerce_payment', 'commerce_paymos'];
  private Order $order;
  private PaymentGateway $gateway;
  private PaymentStoreInterface $invoices;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installConfig('commerce_payment');
    $this->installSchema('commerce_paymos', ['commerce_paymos_invoice', 'commerce_paymos_event']);
    // Preserve the native test request and its session for core teardown.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertNotNull($request);
    $request->server->set('HTTPS', 'on');
    $request->server->set('SERVER_PORT', 443);
    $request->headers->set('host', 'shop.example.invalid');
    $this->gateway = PaymentGateway::create([
      'id' => 'paymos', 'label' => 'Paymos', 'plugin' => 'paymos',
      'configuration' => ['mode' => 'test'],
    ]);
    $this->gateway->save();
    $item = OrderItem::create(['type' => 'test', 'quantity' => 1, 'unit_price' => new Price('100', 'USD')]);
    $item->save();
    $this->order = Order::create(['type' => 'default', 'store_id' => $this->store->id(),
      'order_items' => [$item], 'payment_gateway' => $this->gateway->id(), 'state' => 'draft']);
    $this->order->save();
    $this->invoices = $this->container->get('commerce_paymos.store');
  }

  private function record(): array {
    return ['order_id' => (string) $this->order->id(), 'gateway_id' => 'paymos',
      'environment' => 'sandbox', 'project_id' => 'prj_fixture', 'external_order_id' => 'intent_fixture',
      'amount' => '100', 'currency' => 'USD', 'invoice_id' => 'inv_fixture',
      'payment_url' => 'https://checkout.paymos.io/inv_fixture', 'status' => 'awaiting_payment',
      'credited' => FALSE, 'late_noted' => FALSE];
  }

  public function testNativeBuyerReturnBridge(): void {
    require_once dirname(__DIR__, 2) . '/return-flow.php';
    paymos_native_return_flow($this->order, FALSE);
    $this->assertSame([], $this->payments(), 'Return navigation creates no completed payment');
  }

  private function invoice(string $status = 'paid'): array {
    return ['invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture', 'is_test' => TRUE,
      'status' => $status, 'order' => ['external_id' => 'intent_fixture', 'amount' => '100', 'currency' => 'USD']];
  }

  private function processor(array $responses, ?PaymentStoreInterface $store = NULL): PaymentProcessor {
    $store ??= $this->invoices;
    return new PaymentProcessor($store, new KernelClients(new MockTransport($responses)),
      new EventStore($store, $this->container->get('lock')));
  }

  private function response(array $data): HttpResponse {
    return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR), []);
  }

  private function deliver(PaymentProcessor $processor, array $invoice, string $type = 'invoice.paid', string $id = 'evt_fixture'): int {
    $now = time();
    $body = json_encode(['event_id' => $id, 'event_type' => $type, 'version' => 1,
      'occurred_at' => $now, 'data' => $invoice], JSON_THROW_ON_ERROR);
    return $processor->handle($body, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec_fixture'), $now);
  }

  private function payments(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $storage->resetCache();
    return $storage->loadByProperties(['order_id' => $this->order->id()]);
  }

  public function testNativeDiscoveryConnectionAndExport(): void {
    $this->assertSame('paymos', $this->gateway->getPlugin()->getPluginId());
    $this->assertSame('commerce_paymos_connect', ConnectForm::create($this->container)->getFormId());
    $connection = $this->container->get('commerce_paymos.connection');
    $this->assertSame([], $connection->credentials());
    $gatewayStorage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway');
    $this->assertArrayNotHasKey('paymos', $gatewayStorage->loadMultipleForOrder($this->order));
    $credentials = [];
    foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
      $credentials[$environment] = ['api_key' => 'pk_' . $prefix . '_fixture',
        'api_secret' => 'sk_' . $prefix . '_fixture', 'project_id' => 'prj_fixture', 'webhook_secret' => 'whsec_fixture'];
    }
    $connection->accept(['status' => 'connected', 'plugin' => 'drupal-commerce',
      'source_url' => 'https://shop.example.invalid', 'credentials' => $credentials], 'https://shop.example.invalid');
    $this->assertSame('prj_fixture', $connection->credentials()['sandbox']['project_id']);
    $ciphertext = $this->container->get('state')->get('commerce_paymos.connection');
    $this->assertIsString($ciphertext);
    $this->assertStringNotContainsString('sk_test_fixture', $ciphertext);
    $export = json_encode($this->gateway->toArray(), JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('sk_test_fixture', $export);
    $this->assertStringNotContainsString('whsec_fixture', $export);
    $this->assertArrayHasKey('paymos', $gatewayStorage->loadMultipleForOrder($this->order));
    $this->container->get('request_stack')->push(Request::create('https://other.example.invalid/'));
    try {
      $this->assertArrayNotHasKey('paymos', $gatewayStorage->loadMultipleForOrder($this->order));
    }
    finally { $this->container->get('request_stack')->pop(); }
    $connection->disconnect();
    $this->assertSame([], $connection->credentials());
    $this->assertNull($this->container->get('state')->get('commerce_paymos.connection'));
  }

  public function testDurableIntentExistsBeforeApiRequest(): void {
    $transport = new MockTransport([new HttpResponse(503, '{"title":"temporary"}', [])]);
    $checkout = new CheckoutService($this->invoices, new KernelClients($transport));
    try {
      $checkout->start((string) $this->order->id(), 'paymos', 'sandbox');
      $this->fail('Expected unknown API outcome');
    }
    catch (\Paymos\Exception\ApiException $e) {}
    $this->assertFalse($this->container->get('database')->inTransaction());
    $record = $this->invoices->load((string) $this->order->id());
    $this->assertNotEmpty($record['external_order_id']);
    $payload = json_decode($transport->requests()[0]['body'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($record['external_order_id'], $payload['external_order_id']);
    $this->assertIsString($record['amount']);
    $this->assertTrue((new Price('100', 'USD'))->equals(new Price($record['amount'], $record['currency'])));
    $this->assertSame([], $this->payments());
  }

  public function testVerifiedNativePaymentPlacesOrderAndDuplicatesStaySingle(): void {
    $this->invoices->save($this->record());
    $processor = $this->processor([$this->response($this->invoice())]);
    $this->assertSame(200, $this->deliver($processor, $this->invoice()));
    $payments = $this->payments();
    $this->assertCount(1, $payments);
    $payment = reset($payments);
    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame('test', $payment->getPaymentGatewayMode());
    $order = $this->reloadEntity($this->order);
    $this->assertTrue($order->isPaid(), 'Native total_paid is updated before request teardown');
    $this->assertNotSame('draft', $order->getState()->getId());
    $this->assertTrue($this->invoices->isCommitted('evt_fixture'));
    $this->assertSame(200, $this->deliver($processor, $this->invoice()));
    $this->assertSame(200, $this->deliver($processor, $this->invoice(), 'invoice.paid', 'evt_second'));
    $this->assertCount(1, $this->payments());
  }

  public function testPartialLateAndChangedOrderCannotPay(): void {
    $this->invoices->save($this->record());
    $processor = $this->processor([]);
    $this->assertSame(200, $this->deliver($processor, $this->invoice('underpaid_waiting'), 'invoice.underpaid_waiting'));
    $this->assertSame([], $this->payments());
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price('101', 'USD'));
    $item->save();
    $this->order->save();
    $processor = $this->processor([$this->response($this->invoice())]);
    $this->assertSame(200, $this->deliver($processor, $this->invoice(), 'invoice.paid', 'evt_changed'));
    $this->assertSame([], $this->payments());
    $this->assertTrue($this->invoices->load((string) $this->order->id())['review']);
    $record = $this->record();
    $record['status'] = 'expired';
    $this->invoices->save($record);
    $late = $this->invoice('expired') + ['paid_late_at' => time()];
    $processor = $this->processor([$this->response($late)]);
    $this->assertSame(200, $this->deliver($processor, $late, 'invoice.paid_late', 'evt_late'));
    $this->assertSame([], $this->payments());
  }

  public function testReturnAndInvalidSignatureCannotPay(): void {
    $this->invoices->save($this->record());
    $this->assertSame(401, $this->processor([])->handle('{}', 't=' . time() . ',v1=' . str_repeat('0', 64)));
    try {
      $this->gateway->getPlugin()->onReturn($this->order, Request::create('/?status=paid&invoice_id=inv_fixture'));
      $this->fail('Browser query must not complete an order');
    }
    catch (PaymentGatewayException $e) {}
    $this->assertSame([], $this->payments());
    $this->assertFalse($this->reloadEntity($this->order)->isPaid());
  }

  public function testDatabaseRollbackAfterNativePaymentAndRetry(): void {
    $this->invoices->save($this->record());
    $store = new FailOnceStore($this->invoices);
    $processor = $this->processor([$this->response($this->invoice()), $this->response($this->invoice())], $store);
    $this->assertSame(503, $this->deliver($processor, $this->invoice()));
    $this->assertSame([], $this->payments());
    $this->assertFalse($this->reloadEntity($this->order)->isPaid());
    $this->assertFalse($this->invoices->isCommitted('evt_fixture'));
    $this->assertFalse($this->invoices->load((string) $this->order->id())['credited']);
    $this->assertSame(200, $this->deliver($processor, $this->invoice()));
    $this->assertCount(1, $this->payments());
    $this->assertTrue($this->reloadEntity($this->order)->isPaid());
  }

  public function testReconciliationAndUninstallGuard(): void {
    $this->invoices->save($this->record());
    $validator = $this->container->get('commerce_paymos.uninstall_validator');
    $this->assertNotEmpty($validator->validate('commerce_paymos'));
    $this->assertSame([], $validator->validate('other_module'));
    $processor = $this->processor([$this->response($this->invoice()), $this->response($this->invoice())]);
    $this->assertTrue($processor->reconcile($this->record()));
    $this->assertCount(1, $this->payments());
    $this->assertSame([], $validator->validate('commerce_paymos'));
  }

  public function testReconciliationQueueDoesNotStarveOlderUnresolvedInvoices(): void {
    $record = $this->record();
    $this->invoices->save($record);
    $second = $record;
    $second['order_id'] = 'fixture_second';
    $second['invoice_id'] = 'inv_second';
    $this->invoices->save($second);
    $database = $this->container->get('database');
    $database->update('commerce_paymos_invoice')->fields(['updated' => time() - 172800])->execute();
    $this->assertCount(2, $this->invoices->pending(0, 50), 'Two-day outage does not drop unresolved invoices');
    $first = $this->invoices->pending(0, 1)[0];
    $this->invoices->markChecked($first['order_id']);
    $next = $this->invoices->pending(0, 1)[0];
    $this->assertNotSame($first['order_id'], $next['order_id'], 'Unchanged or failed lookups rotate behind unchecked invoices');
  }
}

/** SDK transport is the only mock; the platform and SQL are real. */
final class KernelClients implements ClientFactoryInterface {
  public function __construct(private MockTransport $transport) {}
  public function configuration(string $environment): array {
    return ['project_id' => 'prj_fixture', 'webhook_secret' => $environment === 'sandbox' ? 'whsec_fixture' : 'whsec_live'];
  }
  public function create(string $environment): Client {
    return new Client(new ClientConfig('pk_test_fixture', 'sk_test_fixture', 'https://api.example.invalid'), $this->transport);
  }
}

/** Injects failure after native payment/order saves, before the dedup insert. */
final class FailOnceStore implements PaymentStoreInterface {
  private bool $fail = TRUE;
  public function __construct(private PaymentStoreInterface $inner) {}
  public function synchronized(string $id, callable $action): mixed { return $this->inner->synchronized($id, $action); }
  public function transaction(string $id, callable $action, bool $durable = FALSE): mixed { return $this->inner->transaction($id, $action, $durable); }
  public function snapshot(string $id): array { return $this->inner->snapshot($id); }
  public function load(string $id): ?array { return $this->inner->load($id); }
  public function byInvoice(string $id): ?array { return $this->inner->byInvoice($id); }
  public function save(array $record): void { $this->inner->save($record); }
  public function isCommitted(string $id): bool { return $this->inner->isCommitted($id); }
  public function commitEvent(string $id): void {
    if ($this->fail) { $this->fail = FALSE; throw new \RuntimeException('fixture_failure_before_dedup'); }
    $this->inner->commitEvent($id);
  }
  public function complete(array $record, array $invoice): void { $this->inner->complete($record, $invoice); }
  public function note(string $id, string $code): void { $this->inner->note($id, $code); }
  public function pending(int $since, int $limit): array { return $this->inner->pending($since, $limit); }
  public function markChecked(string $id): void { $this->inner->markChecked($id); }
}
