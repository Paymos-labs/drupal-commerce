<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Paymos\Plugin\{AmountGuard, NativeProjectionResult, RefundIntent, RefundStates, VerifiedRefund};

/** Commerce payment mutations only; no gateway cash call or order placement. */
final class RefundNativeGateway {
  private $entities;
  private $invoices;
  private $db;
  private $store;
  private static $paymentWrites;

  public function __construct($entities, PaymentStoreInterface $invoices, $database, RefundIntentStore $store) {
    $this->entities = $entities; $this->invoices = $invoices; $this->db = $database; $this->store = $store;
  }

  public static function isWritingPayment(PaymentInterface $payment): bool {
    return self::$paymentWrites !== NULL && self::$paymentWrites->contains($payment);
  }

  private function saveRefundedPayment(PaymentInterface $payment, Price $amount, string $state): void {
    // Scope permission to this object so nested saves cannot authorize another payment.
    if (self::$paymentWrites === NULL) { self::$paymentWrites = new \SplObjectStorage(); }
    $depth = self::$paymentWrites->contains($payment) ? self::$paymentWrites[$payment] : 0;
    self::$paymentWrites[$payment] = $depth + 1;
    try {
      $payment->setRefundedAmount($amount);
      $payment->setState($state);
      $payment->save();
    }
    finally {
      if ($depth) { self::$paymentWrites[$payment] = $depth; }
      else { self::$paymentWrites->detach($payment); }
    }
  }

  public function payment(string $key) {
    $storage = $this->entities->getStorage('commerce_payment'); $storage->resetCache([$key]);
    $payment = $storage->load($key);
    if (!$payment) { throw new \RuntimeException('refund_payment_missing'); }
    return $payment;
  }
  public function candidates(): array {
    $storage = $this->entities->getStorage('commerce_payment');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('state','completed')->sort('payment_id','DESC')->range(0,100)->execute();
    $payments = [];
    foreach ($storage->loadMultiple($ids) as $payment) {
      if ($payment->getPaymentGateway() && $payment->getPaymentGateway()->getPluginId() === 'paymos') { $payments[] = $payment; }
    }
    return $payments;
  }

  public function assertStorage(): void {
    foreach (['commerce_payment','commerce_order'] as $type) {
      if (!$this->entities->getStorage($type) instanceof \Drupal\Core\Entity\Sql\SqlContentEntityStorage) {
        throw new \RuntimeException('refund_native_sql_storage_required');
      }
    }
    $driver = $this->db->driver();
    if (!in_array($driver,['mysql','pgsql'],TRUE)) { throw new \RuntimeException('refund_database_unsupported'); }
    foreach (['commerce_paymos_refund','commerce_paymos_refund_projection','commerce_paymos_refund_lock'] as $table) {
      if (!$this->db->schema()->tableExists($table)) { throw new \RuntimeException('refund_schema_upgrade_required'); }
    }
    if ($driver === 'mysql') {
      foreach (['commerce_payment','commerce_order','commerce_paymos_invoice','commerce_paymos_refund','commerce_paymos_refund_projection','commerce_paymos_refund_lock'] as $table) {
        $name = $this->db->getPrefix() . $table;
        $engine = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [':table'=>$name])->fetchField();
        if (strtolower((string)$engine) !== 'innodb') { throw new \RuntimeException('refund_transactional_storage_required'); }
      }
    }
  }

  public function snapshot(string $key, bool $refunded = FALSE): array {
    $payment = $this->payment($key); $record = $this->invoices->byInvoice((string)$payment->getRemoteId());
    $orderStorage = $this->entities->getStorage('commerce_order'); $orderStorage->resetCache([$payment->getOrderId()]);
    $order = $orderStorage->load($payment->getOrderId());
    $amount = $payment->getAmount(); $paidBack = $payment->getRefundedAmount();
    $gateway = $payment->getPaymentGateway();
    $matches = $this->entities->getStorage('commerce_payment')->loadByProperties(['order_id'=>$payment->getOrderId()]);
    if (!$record || !$order || !$gateway || $gateway->getPluginId() !== 'paymos' || count($matches) !== 1
      || empty($record['credited']) || !empty($record['review']) || (string)$order->id() !== $record['order_id']
      || (string)$gateway->id() !== $record['gateway_id'] || (string)$payment->getRemoteId() !== $record['invoice_id']
      || $payment->getPaymentGatewayMode() !== ($record['environment'] === 'sandbox' ? 'test' : 'live')
      || !$amount || !$paidBack || $amount->getCurrencyCode() !== $record['currency']
      || $paidBack->getCurrencyCode() !== $amount->getCurrencyCode()
      || !AmountGuard::amountsEqual($amount->getNumber(),$record['amount'])
      || !$order->getTotalPrice() || !AmountGuard::amountsEqual($order->getTotalPrice()->getNumber(),$record['amount'])
      || $order->getTotalPrice()->getCurrencyCode() !== $record['currency']
      || $payment->getState()->getId() !== ($refunded ? 'refunded' : 'completed')
      || !AmountGuard::amountsEqual($paidBack->getNumber(),$refunded ? $amount->getNumber() : '0')
      || (!$refunded && !$order->isPaid())) { throw new \RuntimeException('refund_native_mapping_conflict'); }
    $currency = $this->entities->getStorage('commerce_currency')->load($amount->getCurrencyCode());
    if (!$currency) { throw new \RuntimeException('refund_native_currency_missing'); }
    return ['payment_uuid'=>$payment->uuid(),'order_id'=>(string)$order->id(),'order_uuid'=>$order->uuid(),
      'gateway_id'=>(string)$gateway->id(),'invoice_id'=>$record['invoice_id'],'project_id'=>$record['project_id'],
      'environment'=>$record['environment'],'amount'=>$amount->getNumber(),'currency'=>$amount->getCurrencyCode(),
      'amount_minor'=>self::minor($amount->getNumber(),(int)$currency->getFractionDigits()),
      'notify'=>FALSE,'restock'=>FALSE];
  }

  public static function minor(string $amount, int $digits): string {
    $amount = AmountGuard::normalizeDecimal($amount);
    if ($digits < 0 || $digits > 18 || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$amount)) { throw new \RuntimeException('refund_native_amount_invalid'); }
    $parts = explode('.',$amount); $fraction = $parts[1] ?? '';
    if (strlen($fraction) > $digits) { throw new \RuntimeException('refund_native_precision_changed'); }
    $minor = ltrim($parts[0] . str_pad($fraction,$digits,'0'),'0');
    if ($minor === '') { throw new \RuntimeException('refund_native_amount_zero'); }
    return $minor;
  }

  public function paymentForInvoice(string $invoiceId): string {
    $payments = $this->entities->getStorage('commerce_payment')->loadByProperties(['remote_id'=>$invoiceId]);
    if (count($payments) !== 1) { throw new \RuntimeException('refund_native_mapping_conflict'); }
    $payment = reset($payments); return (string)$payment->id();
  }
  public function mapping(string $key): array {
    $payment = $this->payment($key); $record = $this->invoices->byInvoice((string)$payment->getRemoteId());
    if (!$record || empty($record['credited']) || (string)$payment->getOrderId() !== $record['order_id']
      || (string)$payment->getPaymentGatewayId() !== $record['gateway_id']
      || $payment->getPaymentGateway()->getPluginId() !== 'paymos'
      || $payment->getPaymentGatewayMode() !== ($record['environment'] === 'sandbox' ? 'test' : 'live')) {
      throw new \RuntimeException('refund_native_mapping_conflict');
    }
    return $record;
  }

  public function inspect(RefundIntent $intent): NativeProjectionResult {
    $marker = $this->store->marker($intent->operationId());
    try {
      $refunded = $marker !== NULL && !empty($marker['active']) && $intent->mode() === RefundStates::FULL;
      if ($marker !== NULL && $intent->mode() === RefundStates::OVERPAYMENT) {
        foreach ($this->store->forPayment($intent->paymentKey()) as $other) {
          $full = $other->mode() === RefundStates::FULL ? $this->store->marker($other->operationId()) : NULL;
          if ($full !== NULL && !empty($full['active'])) { $refunded = TRUE; }
        }
      }
      $snapshot = $this->snapshot($intent->paymentKey(),$refunded);
      if ($snapshot !== $intent->toArray()['native_snapshot']) { throw new \RuntimeException('refund_native_snapshot_changed'); }
      if ($marker === NULL) { return new NativeProjectionResult(NativeProjectionResult::ABSENT,$intent->mode()); }
      if ($marker['payment_key'] !== $intent->paymentKey() || $marker['withdrawal_id'] !== $intent->withdrawalId()
        || $marker['snapshot'] !== $snapshot || $marker['mode'] !== $intent->mode()) { throw new \RuntimeException('refund_native_marker_conflict'); }
      if ($intent->mode() === RefundStates::OVERPAYMENT) {
        $order = $this->entities->getStorage('commerce_order')->load($snapshot['order_id']);
        $notes = $order->getData('paymos_refund_notes',[]);
        if (!isset($notes[$intent->operationId()]) || $notes[$intent->operationId()] !== $marker['note']) {
          throw new \RuntimeException('refund_native_note_changed');
        }
      }
      return NativeProjectionResult::fromStorage($marker['result']);
    }
    catch (\RuntimeException $e) { return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS,$intent->mode(),NULL,NULL,NULL,NULL,NULL,$e->getMessage()); }
  }

  public function apply(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult {
    $found = $this->inspect($intent);
    if ($found->kind() !== NativeProjectionResult::ABSENT) { return $found; }
    $snapshot = $this->snapshot($intent->paymentKey()); $payment = $this->payment($intent->paymentKey());
    $marker = ['payment_key'=>$intent->paymentKey(),'withdrawal_id'=>$refund->withdrawalId(),
      'mode'=>$intent->mode(),'snapshot'=>$snapshot,'active'=>TRUE];
    if ($intent->mode() === RefundStates::FULL) {
      $this->saveRefundedPayment($payment,new Price($snapshot['amount'],$snapshot['currency']),'refunded');
      $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH,$intent->mode(),$intent->paymentKey(),$snapshot['amount_minor'],$snapshot['currency']);
    }
    else {
      $order = $this->entities->getStorage('commerce_order')->load($snapshot['order_id']);
      $notes = $order->getData('paymos_refund_notes',[]);
      $marker['note'] = ['code'=>'TODO-COPY:refund-overpayment-note','withdrawal_id'=>$refund->withdrawalId(),
        'amount'=>$refund->principalDecimal(),'asset'=>$refund->currency() . '/' . $refund->network(),'status'=>$refund->status()];
      $notes[$intent->operationId()] = $marker['note']; $order->setData('paymos_refund_notes',$notes)->save();
      $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH,$intent->mode(),$intent->operationId(),NULL,NULL,$refund->principalDecimal(),$refund->currency() . '/' . $refund->network());
    }
    $marker['result'] = $result->toArray(); $this->store->saveMarker($intent,$marker);
    return $result;
  }

  /** Rewind only a projection still wholly owned by this operation. */
  public function sync(RefundIntent $intent): void {
    $marker = $this->store->marker($intent->operationId()); $refund = $intent->observation();
    if ($marker === NULL || $refund === NULL) { return; }
    if ($intent->mode() === RefundStates::OVERPAYMENT) {
      // Later full operations own the financial state; an old cash note cannot undo it.
      $orderStorage = $this->entities->getStorage('commerce_order'); $orderStorage->resetCache([$marker['snapshot']['order_id']]);
      $order = $orderStorage->load($marker['snapshot']['order_id']);
      $notes = $order->getData('paymos_refund_notes',[]);
      if (($notes[$intent->operationId()] ?? NULL) !== $marker['note']) { throw new \RuntimeException('refund_native_note_changed'); }
      $marker['note']['status'] = $refund->status(); $notes[$intent->operationId()] = $marker['note'];
      $order->setData('paymos_refund_notes',$notes)->save(); $this->store->saveMarker($intent,$marker); return;
    }
    $completed = $refund->status() === 'completed';
    if ($completed === $marker['active']) { return; }
    if ($this->inspect($intent)->kind() !== NativeProjectionResult::EXACT_MATCH) { throw new \RuntimeException('refund_native_rewind_ambiguous'); }
    $payment = $this->payment($intent->paymentKey());
    $this->saveRefundedPayment($payment,new Price($completed ? $marker['snapshot']['amount'] : '0',$marker['snapshot']['currency']),$completed ? 'refunded' : 'completed');
    $marker['active'] = $completed; $this->store->saveMarker($intent,$marker);
  }
  public function observeExternal(string $key, VerifiedRefund $refund): void {
    $id = 'external-' . sha1($refund->withdrawalId()); $old = $this->store->marker($id);
    $marker = ['payment_key'=>$key,'observation'=>$refund->toArray(),'review_reason'=>NULL,
      'projection_state'=>$refund->status() === 'completed' ? RefundStates::PROJECTION_APPLIED : RefundStates::PROJECTION_PENDING];
    if ($old !== NULL) {
      $pin = VerifiedRefund::fromStorage($old['observation']);
      if ($pin->invoiceId() !== $refund->invoiceId() || $pin->projectId() !== $refund->projectId()
        || $pin->environment() !== $refund->environment() || $pin->mode() !== $refund->mode()
        || $pin->externalOrderId() !== $refund->externalOrderId() || !AmountGuard::amountsEqual($pin->principalDecimal(),$refund->principalDecimal())
        || $pin->currency() !== $refund->currency() || $pin->network() !== $refund->network() || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $refund)) {
        throw new \RuntimeException('refund_binding_conflict');
      }
    }
    if ($refund->mode() === RefundStates::FULL) {
      $marker['review_reason'] = 'refund_unbound_full_without_draft'; $marker['projection_state'] = RefundStates::NATIVE_REVIEW;
    }
    else {
      $mapping = $this->mapping($key); $orderStorage = $this->entities->getStorage('commerce_order');
      $orderStorage->resetCache([$mapping['order_id']]); $order = $orderStorage->load($mapping['order_id']);
      $notes = $order->getData('paymos_refund_notes',[]);
      if ($old !== NULL && isset($old['note']) && ($notes[$id] ?? NULL) !== $old['note']) { throw new \RuntimeException('refund_native_note_changed'); }
      if ($old !== NULL && isset($old['note']) && $refund->status() !== 'completed') {
        $marker['review_reason'] = 'cash_rewound'; $marker['projection_state'] = RefundStates::NATIVE_REVIEW;
      }
      $marker['note'] = ['code'=>'TODO-COPY:refund-overpayment-note','withdrawal_id'=>$refund->withdrawalId(),
        'amount'=>$refund->principalDecimal(),'asset'=>$refund->currency() . '/' . $refund->network(),'status'=>$refund->status()];
      if ($refund->status() === 'completed' || ($old !== NULL && isset($old['note']))) {
        $notes[$id] = $marker['note']; $order->setData('paymos_refund_notes',$notes)->save();
      }
      else { unset($marker['note']); }
    }
    $this->store->saveExternal($key,$refund,$marker);
  }
}
