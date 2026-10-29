<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Paymos\Plugin\{CredentialSet, NativeProjectionResult, NativeRefundProjectorInterface, RefundCoordinator, RefundIntent, RefundStates, VerifiedRefund};

/** Protected native actions and verified observations share the SDK coordinator. */
final class RefundAdapter implements NativeRefundProjectorInterface {
  private $store;
  private $native;
  private $clients;
  private $installation;

  public function __construct(RefundIntentStore $store, RefundNativeGateway $native, ClientFactoryInterface $clients, $installation) {
    $this->store = $store; $this->native = $native; $this->clients = $clients; $this->installation = $installation;
  }
  private function connection(string $environment, string $project, bool $creating): void {
    $values = $this->clients->configuration($environment);
    if ($values['project_id'] !== $project || !CredentialSet::hasCapability($values,'invoice_refunds_v1')) {
      throw new \RuntimeException('refund_reconnect_required');
    }
    if ($creating && !CredentialSet::refundsEnabled($values)) { throw new \RuntimeException('refunds_not_enabled'); }
  }
  public function canRefund(string $key): bool {
    try {
      $snapshot = $this->native->snapshot($key); $this->connection($snapshot['environment'],$snapshot['project_id'],TRUE);
      $this->native->assertStorage();
      $this->store->assertNoExternalReview($key);
      foreach ($this->store->forPayment($key) as $intent) {
        if (!in_array($intent->cashState(),[RefundStates::FAILED,RefundStates::CANCELLED,RefundStates::COMPLETED],TRUE)
          || $intent->projectionState() === RefundStates::NATIVE_REVIEW
          || ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED)) { return FALSE; }
      }
      return TRUE;
    }
    catch (\Throwable $e) { return FALSE; }
  }
  public function request(string $key, string $mode, string $token, $amount = NULL): RefundIntent {
    $this->store->assertHttpAllowed();
    if (!in_array($mode,[RefundStates::FULL,RefundStates::OVERPAYMENT],TRUE) || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$token)) {
      throw new \RuntimeException('refund_request_invalid');
    }
    return $this->store->withPaymentLock($key,function () use ($key,$mode,$token,$amount) {
      $installation = (string)$this->installation->get('system.site')->get('uuid');
      $existing = $this->store->findByRequestToken($installation,$token);
      if ($existing !== NULL) {
        if ($existing->paymentKey() !== $key || $existing->mode() !== $mode) { throw new \RuntimeException('refund_request_conflict'); }
        if ($amount !== NULL && ($mode !== RefundStates::FULL || $amount->getCurrencyCode() !== $existing->fullDraft()['currency']
          || !\Paymos\Plugin\AmountGuard::amountsEqual($amount->getNumber(),$existing->fullDraft()['amount']))) {
          throw new \RuntimeException('refund_request_conflict');
        }
        $intent = $existing;
      }
      else {
        $this->native->assertStorage(); $snapshot = $this->native->snapshot($key);
        $this->connection($snapshot['environment'],$snapshot['project_id'],TRUE);
        if ($amount !== NULL && ($mode !== RefundStates::FULL || $amount->getCurrencyCode() !== $snapshot['currency']
          || !\Paymos\Plugin\AmountGuard::amountsEqual($amount->getNumber(),$snapshot['amount']))) { throw new \RuntimeException('refund_partial_not_supported'); }
        $intent = $this->store->createIntent(new RefundIntent([
          'operation_id'=>self::uuid(),'request_token'=>$token,'installation_id'=>$installation,'platform'=>'drupal-commerce',
          'native_payment_key'=>$key,'invoice_id'=>$snapshot['invoice_id'],'project_id'=>$snapshot['project_id'],
          'environment'=>$snapshot['environment'],'mode'=>$mode,'native_snapshot'=>$snapshot,
          'full_draft'=>$mode === RefundStates::FULL ? $snapshot : NULL,
        ]));
      }
      $this->connection($intent->environment(),$intent->projectId(),$intent->cashState() === RefundStates::NOT_SUBMITTED);
      $state = $this->coordinator($intent)->submit($intent->operationId());
      if ($state !== RefundStates::UNKNOWN) { $this->reconcile($intent->operationId()); }
      return $this->store->getIntent($intent->operationId());
    });
  }
  private static function uuid(): string {
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes); return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
  }
  public function preflight(RefundIntent $intent): ?string {
    try {
      $this->connection($intent->environment(),$intent->projectId(),TRUE); $this->native->assertStorage();
      if ($this->native->snapshot($intent->paymentKey()) !== $intent->toArray()['native_snapshot']) { return 'refund_native_snapshot_changed'; }
      if ($intent->mode() === RefundStates::FULL && $intent->fullDraft() !== $intent->toArray()['native_snapshot']) { return 'refund_native_draft_conflict'; }
      return NULL;
    }
    catch (\RuntimeException $e) { return $e->getMessage(); }
  }
  public function inspect(string $operationId): NativeProjectionResult { return $this->native->inspect($this->store->getIntent($operationId)); }
  public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult { return $this->native->apply($intent,$refund); }
  private function coordinator(RefundIntent $intent): RefundCoordinator {
    return new RefundCoordinator($this->clients->create($intent->environment()),$this->store,$this);
  }
  public function reconcile(string $operationId): string {
    $this->store->assertHttpAllowed();
    $intent = $this->store->getIntent($operationId);
    return $this->store->withPaymentLock($intent->paymentKey(),function () use ($operationId) {
      $intent = $this->store->getIntent($operationId); $this->connection($intent->environment(),$intent->projectId(),FALSE);
      try {
        $action = $this->coordinator($intent)->reconcile($operationId);
        $this->store->transaction(function () use ($operationId) { $this->native->sync($this->store->getIntent($operationId)); });
        return $action;
      }
      catch (\RuntimeException $e) {
        $this->store->recordState($operationId,$this->store->getIntent($operationId)->cashState(),RefundStates::NATIVE_REVIEW,'refund_native_sync_review');
        return RefundStates::SHOW_REVIEW;
      }
      finally { $this->store->schedule($operationId); }
    });
  }
  /** Signature verification happens in PaymentProcessor; event money is never evidence. */
  public function callback(array $data, string $environment): void {
    foreach (['withdrawal_id','invoice_id','project_id','mode','external_order_id'] as $field) {
      if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') { throw new \RuntimeException('refund_callback_scope_missing'); }
    }
    if (!isset($data['is_test']) || $data['is_test'] !== ($environment === 'sandbox')) { throw new \RuntimeException('refund_callback_scope_conflict'); }
    $intent = $this->store->findByExternalId($data['external_order_id']);
    $bound = $this->store->findByWithdrawalId($data['withdrawal_id']);
    if ($intent === NULL) { $intent = $bound; }
    if ($intent === NULL) { $this->observeUnbound($data,$environment); return; }
    if ($intent->invoiceId() !== $data['invoice_id'] || $intent->projectId() !== $data['project_id']
      || $intent->environment() !== $environment || $intent->mode() !== $data['mode'] || $intent->externalOrderId() !== $data['external_order_id']
      || ($intent->withdrawalId() !== NULL && $intent->withdrawalId() !== $data['withdrawal_id'])
      || ($bound !== NULL && $bound->operationId() !== $intent->operationId())) { throw new \RuntimeException('refund_callback_scope_conflict'); }
    $this->reconcile($intent->operationId());
  }
  private function observeUnbound(array $data, string $environment): void {
    $this->store->assertHttpAllowed();
    $key = $this->native->paymentForInvoice($data['invoice_id']);
    $this->store->withPaymentLock($key,function () use ($data,$environment,$key) {
      $snapshot = $this->native->mapping($key); $this->connection($environment,$snapshot['project_id'],FALSE);
      if ($snapshot['environment'] !== $environment || $snapshot['project_id'] !== $data['project_id']) { throw new \RuntimeException('refund_callback_scope_conflict'); }
      $client = $this->clients->create($environment);
      $verified = VerifiedRefund::fromApi($client->refunds()->get($data['withdrawal_id']),$client->invoices()->get($data['invoice_id']),new \DateTimeImmutable());
      if ($verified->withdrawalId() !== $data['withdrawal_id'] || $verified->invoiceId() !== $data['invoice_id']
        || $verified->projectId() !== $data['project_id'] || $verified->environment() !== $environment
        || $verified->mode() !== $data['mode'] || $verified->externalOrderId() !== $data['external_order_id']) { throw new \RuntimeException('refund_callback_scope_conflict'); }
      $this->store->transaction(function () use ($key,$verified) { $this->native->observeExternal($key,$verified); });
    });
  }
  public function run(int $limit = 100): int {
    $page = $this->store->listDue(new \DateTimeImmutable(),$limit,NULL); $checked = 0;
    foreach ($page->items() as $intent) {
      try { $this->reconcile($intent->operationId()); $checked++; }
      catch (\Throwable $e) {
        try { $this->store->withPaymentLock($intent->paymentKey(),function () use ($intent) { $this->store->schedule($intent->operationId()); }); }
        catch (\Throwable $ignored) { /* Another owner will rotate this operation. */ }
      }
    }
    foreach ($this->store->externalDue(new \DateTimeImmutable(),$limit) as $marker) {
      $data = $marker['observation'];
      try { $this->observeUnbound($data,$data['environment']); $checked++; }
      catch (\Throwable $e) { /* Retain verified history until its connection recovers. */ }
    }
    return $checked;
  }
  public function candidates(): array {
    return array_filter($this->native->candidates(),function ($payment) { return $this->canRefund((string)$payment->id()); });
  }
}
