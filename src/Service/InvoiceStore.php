<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_price\Price;
use Paymos\Plugin\AmountGuard;

/** Database row locks and transactions remain authoritative if a lease expires. */
final class InvoiceStore implements PaymentStoreInterface {
  public function __construct(private Connection $db, private EntityTypeManagerInterface $entities,
    private LockBackendInterface $locks, private TimeInterface $time,
    private PaymentOrderUpdaterInterface $paymentOrderUpdater) {}

  public function synchronized(string $orderId, callable $action): mixed {
    $key = 'commerce_paymos:order:' . hash('sha256', $orderId);
    if (!$this->locks->acquire($key, 180.0)) { throw new PaymentBusyException('paymos_order_busy'); }
    try { return $action(); }
    finally { $this->locks->release($key); }
  }

  public function transaction(string $orderId, callable $action, bool $durable=FALSE): mixed {
    if ($durable && $this->db->inTransaction()) {
      // An enclosing transaction could roll back an intent after the POST took effect.
      throw new \RuntimeException('paymos_durable_intent_requires_root_transaction');
    }
    $transaction = $this->db->startTransaction();
    try {
      $id = $this->db->select('commerce_order','o')->fields('o',['order_id'])
        ->condition('order_id',$orderId)->forUpdate()->execute()->fetchField();
      if ($id === FALSE) { throw new \RuntimeException('paymos_order_missing'); }
      return $action();
    }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    // Drupal commits on transaction-object destruction before control returns.
  }

  private function order(string $orderId): OrderInterface {
    $storage = $this->entities->getStorage('commerce_order');
    $storage->resetCache([$orderId]);
    $order = $storage->load($orderId);
    if (!$order instanceof OrderInterface) { throw new \RuntimeException('paymos_order_missing'); }
    return $order;
  }

  public function snapshot(string $orderId): array {
    $order = $this->order($orderId);
    $balance = $order->getBalance();
    if ($balance === NULL) { throw new \RuntimeException('paymos_order_has_no_total'); }
    return ['order_id'=>(string)$order->id(), 'uuid'=>$order->uuid(),
      'gateway_id'=>(string)$order->get('payment_gateway')->target_id,
      'amount'=>$balance->getNumber(), 'currency'=>$balance->getCurrencyCode(),
      'paid'=>$order->isPaid(), 'cancelled'=>$order->getState()->getId()==='canceled'];
  }

  public function load(string $orderId): ?array {
    $data = $this->db->select('commerce_paymos_invoice','i')->fields('i',['data'])
      ->condition('order_id',$orderId)->execute()->fetchField();
    return $data === FALSE ? NULL : json_decode($data,TRUE,512,JSON_THROW_ON_ERROR);
  }

  public function byInvoice(string $invoiceId): ?array {
    if ($invoiceId === '') { return NULL; }
    $data = $this->db->select('commerce_paymos_invoice','i')->fields('i',['data'])
      ->condition('invoice_id',$invoiceId)->execute()->fetchField();
    return $data === FALSE ? NULL : json_decode($data,TRUE,512,JSON_THROW_ON_ERROR);
  }

  public function save(array $record): void {
    $this->db->merge('commerce_paymos_invoice')->key('order_id',$record['order_id'])->fields([
      'invoice_id'=>($record['invoice_id']??'') !== '' ? $record['invoice_id'] : NULL,
      'data'=>json_encode($record,JSON_THROW_ON_ERROR), 'credited'=>empty($record['credited'])?0:1,
      'updated'=>$this->time->getCurrentTime(),
    ])->execute();
  }

  public function isCommitted(string $eventId): bool {
    return (bool)$this->db->select('commerce_paymos_event','e')->fields('e',['event_id'])
      ->condition('event_id',$eventId)->execute()->fetchField();
  }

  public function commitEvent(string $eventId): void {
    $this->db->insert('commerce_paymos_event')->fields(['event_id'=>$eventId,
      'committed'=>$this->time->getCurrentTime()])->execute();
  }

  public function complete(array $record, array $invoice): void {
    $storage = $this->entities->getStorage('commerce_payment');
    $existing = $storage->loadByProperties(['remote_id'=>$record['invoice_id'], 'payment_gateway'=>$record['gateway_id']]);
    if ($existing) {
      $payment = reset($existing);
      if ((string)$payment->getOrderId() !== $record['order_id'] || $payment->getState()->getId() !== 'completed'
        || !AmountGuard::amountsEqual($payment->getAmount()->getNumber(),$record['amount'])
        || $payment->getAmount()->getCurrencyCode() !== $record['currency']
        || $payment->getPaymentGatewayMode() !== ($record['environment'] === 'sandbox' ? 'test' : 'live')) {
        throw new \RuntimeException('paymos_existing_native_payment_mismatch');
      }
    }
    else {
      $storage->create(['state'=>'completed', 'amount'=>new Price($record['amount'],$record['currency']),
        'payment_gateway'=>$record['gateway_id'], 'order_id'=>$record['order_id'],
        'payment_gateway_mode'=>$record['environment']==='sandbox'?'test':'live',
        'remote_id'=>$record['invoice_id'], 'remote_state'=>$invoice['status']])->save();
    }
    // Commerce otherwise defers total_paid and order placement until teardown,
    // outside the transaction containing this payment and the dedup receipt.
    $this->paymentOrderUpdater->updateOrder($this->order($record['order_id']), TRUE);
  }

  public function note(string $orderId, string $code): void {
    $order = $this->order($orderId);
    $notes = $order->getData('paymos_notes',[]);
    $notes[] = ['code'=>$code,'time'=>$this->time->getCurrentTime()];
    $order->setData('paymos_notes',$notes)->save();
  }

  public function pending(int $since, int $limit): array {
    $rows = $this->db->select('commerce_paymos_invoice','i')->fields('i',['data'])
      ->condition('credited',0)->condition('updated',$since,'>=')->orderBy('checked')->orderBy('order_id')
      ->range(0,min(50,max(1,$limit)))->execute()->fetchCol();
    return array_map(static fn($row)=>json_decode($row,TRUE,512,JSON_THROW_ON_ERROR),$rows);
  }

  public function markChecked(string $orderId): void {
    // Never write back a stale JSON record over a concurrent callback's credit.
    $this->db->update('commerce_paymos_invoice')->fields(['checked'=>$this->time->getCurrentTime()])
      ->condition('order_id',$orderId)->execute();
  }
}
