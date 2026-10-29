<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Service;

use Paymos\Plugin\{AmountGuard, EffectClaim, NativeProjectionResult, RefundIntent, RefundIntentPage, RefundIntentStoreInterface, RefundStates, VerifiedRefund};

/** Owner-fenced leases span HTTP; native row locks span only SQL transactions. */
final class RefundIntentStore implements RefundIntentStoreInterface {
  private $db;
  private $time;
  private $owners = [];

  public function __construct($database, $time) { $this->db = $database; $this->time = $time; }

  public function withPaymentLock(string $paymentKey, callable $action) {
    if (isset($this->owners[$paymentKey])) { $this->assertOwner($paymentKey); return $action(); }
    $owner = bin2hex(random_bytes(24));
    $now = $this->time->getCurrentTime();
    try {
      $this->db->insert('commerce_paymos_refund_lock')->fields([
        'payment_key'=>$paymentKey, 'owner'=>$owner, 'expires'=>$now + 300,
      ])->execute();
    }
    catch (\Drupal\Core\Database\IntegrityConstraintViolationException $e) {
      $won = $this->db->update('commerce_paymos_refund_lock')->fields(['owner'=>$owner,'expires'=>$now + 300])
        ->condition('payment_key',$paymentKey)->condition('expires',$now,'<=')->execute();
      if (!$won) { throw new PaymentBusyException('paymos_refund_busy'); }
    }
    $this->owners[$paymentKey] = $owner;
    try { $result = $action(); $this->assertOwner($paymentKey); return $result; }
    finally {
      unset($this->owners[$paymentKey]);
      $this->db->delete('commerce_paymos_refund_lock')->condition('payment_key',$paymentKey)->condition('owner',$owner)->execute();
    }
  }

  private function assertOwner(string $key, bool $rowLock = FALSE): void {
    $query = $this->db->select('commerce_paymos_refund_lock','l')->fields('l',['owner'])
      ->condition('payment_key',$key)->condition('expires',$this->time->getCurrentTime(),'>');
    if ($rowLock) { $query->forUpdate(); }
    if (!isset($this->owners[$key]) || $query->execute()->fetchField() !== $this->owners[$key]) {
      throw new PaymentBusyException('paymos_refund_lease_lost');
    }
  }

  public function transaction(callable $action) {
    if (!$this->owners) { throw new \RuntimeException('refund_payment_lock_required'); }
    $transaction = $this->db->startTransaction();
    try {
      foreach ($this->owners as $key => $owner) {
        $key = (string)$key;
        $this->assertOwner($key, TRUE);
        $id = $this->db->select('commerce_payment','p')->fields('p',['payment_id'])
          ->condition('payment_id',$key)->forUpdate()->execute()->fetchField();
        if ($id === FALSE) { throw new \RuntimeException('refund_payment_missing'); }
        $orderId = $this->db->select('commerce_payment','p')->fields('p',['order_id'])
          ->condition('payment_id',$key)->execute()->fetchField();
        $this->db->select('commerce_order','o')->fields('o',['order_id'])
          ->condition('order_id',$orderId)->forUpdate()->execute()->fetchField();
      }
      $result = $action();
      foreach ($this->owners as $key => $owner) { $this->assertOwner((string)$key); }
      return $result;
    }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
  }

  public function createIntent(RefundIntent $intent): RefundIntent {
    if ($this->db->inTransaction()) { throw new \RuntimeException('refund_intent_requires_root_commit'); }
    return $this->withPaymentLock($intent->paymentKey(), function () use ($intent) {
      return $this->transaction(function () use ($intent) {
        $data = $intent->toArray();
        $existing = $this->findByRequestToken($data['installation_id'],$data['request_token']);
        if ($existing !== NULL) {
          $old = $existing->toArray();
          foreach (['installation_id','request_token','platform','native_payment_key','invoice_id','project_id','environment','mode','full_draft'] as $key) {
            if ($old[$key] !== $data[$key]) { throw new \RuntimeException('refund_request_conflict'); }
          }
          return $existing;
        }
        $this->assertNoExternalReview($intent->paymentKey());
        foreach ($this->forPayment($intent->paymentKey()) as $other) {
          if (!in_array($other->cashState(),[RefundStates::FAILED,RefundStates::CANCELLED,RefundStates::COMPLETED],TRUE)
            || $other->projectionState() === RefundStates::NATIVE_REVIEW
            || ($other->mode() === RefundStates::FULL && $other->cashState() === RefundStates::COMPLETED)) {
            throw new \RuntimeException('refund_payment_unresolved');
          }
        }
        $now = $this->time->getCurrentTime();
        $data += ['created_at'=>$now,'checks'=>0];
        $this->db->insert('commerce_paymos_refund')->fields([
          'operation_id'=>$intent->operationId(), 'installation_id'=>$data['installation_id'],
          'request_token'=>$data['request_token'], 'payment_key'=>$intent->paymentKey(),
          'external_id'=>$intent->externalOrderId(), 'withdrawal_id'=>NULL,
          'data'=>json_encode($data,JSON_THROW_ON_ERROR), 'created'=>$now, 'due'=>$now + 60,
        ])->execute();
        return new RefundIntent($data);
      });
    });
  }

  private function find(string $field, string $value): ?RefundIntent {
    $data = $this->db->select('commerce_paymos_refund','r')->fields('r',['data'])
      ->condition($field,$value)->execute()->fetchField();
    return $data === FALSE ? NULL : new RefundIntent(json_decode($data,TRUE,512,JSON_THROW_ON_ERROR));
  }
  public function findByRequestToken(string $installationId, string $token): ?RefundIntent {
    $data = $this->db->select('commerce_paymos_refund','r')->fields('r',['data'])
      ->condition('installation_id',$installationId)->condition('request_token',$token)->execute()->fetchField();
    return $data === FALSE ? NULL : new RefundIntent(json_decode($data,TRUE,512,JSON_THROW_ON_ERROR));
  }
  public function getIntent(string $operationId): RefundIntent {
    $intent = $this->find('operation_id',$operationId);
    if ($intent === NULL) { throw new \RuntimeException('refund_intent_missing'); }
    return $intent;
  }
  public function findByExternalId(string $key): ?RefundIntent { return $this->find('external_id',$key); }
  public function findByWithdrawalId(string $id): ?RefundIntent { return $this->find('withdrawal_id',$id); }
  public function forPayment(string $key): array {
    $rows = $this->db->select('commerce_paymos_refund','r')->fields('r',['data'])->condition('payment_key',$key)->execute()->fetchCol();
    return array_map(static function ($data) { return new RefundIntent(json_decode($data,TRUE,512,JSON_THROW_ON_ERROR)); },$rows);
  }
  private function save(array $data): void {
    if (!$this->db->inTransaction()) { $this->transaction(function () use ($data) { $this->save($data); }); return; }
    $this->assertOwner($data['native_payment_key']);
    $this->db->update('commerce_paymos_refund')->fields([
      'data'=>json_encode($data,JSON_THROW_ON_ERROR),'withdrawal_id'=>$data['withdrawal_id'],
    ])->condition('operation_id',$data['operation_id'])->execute();
  }
  public function bindWithdrawal(string $operationId, string $id): string {
    if (!$this->db->inTransaction()) { return $this->transaction(function () use ($operationId,$id) { return $this->bindWithdrawal($operationId,$id); }); }
    $intent = $this->getIntent($operationId);
    $this->assertOwner($intent->paymentKey());
    if ($intent->withdrawalId() !== NULL) { return $intent->withdrawalId() === $id ? 'same' : 'conflict'; }
    $other = $this->findByWithdrawalId($id);
    if ($id === '' || $other !== NULL) { return 'conflict'; }
    $data = $intent->toArray(); $data['withdrawal_id'] = $id;
    $binding = $this->db->startTransaction();
    try { $this->save($data); }
    catch (\Drupal\Core\Database\IntegrityConstraintViolationException $e) { $binding->rollBack(); return 'conflict'; }
    return 'bound';
  }
  public function recordObservation(string $operationId, VerifiedRefund $snapshot): void {
    $intent = $this->getIntent($operationId); $pin = $intent->observation();
    if ($intent->withdrawalId() !== $snapshot->withdrawalId() || $intent->invoiceId() !== $snapshot->invoiceId()
      || $intent->projectId() !== $snapshot->projectId() || $intent->environment() !== $snapshot->environment()
      || $intent->mode() !== $snapshot->mode() || $intent->externalOrderId() !== $snapshot->externalOrderId()
      || ($pin !== NULL && (!AmountGuard::amountsEqual($pin->principalDecimal(),$snapshot->principalDecimal())
        || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network() || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
      throw new \RuntimeException('refund_binding_conflict');
    }
    $data = $intent->toArray(); $data['observation'] = $snapshot->toArray(); $this->save($data);
  }
  public function recordProjection(string $operationId, NativeProjectionResult $result): void {
    $data = $this->getIntent($operationId)->toArray();
    $data['projection'] = $result->toArray(); $this->save($data);
  }
  public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void {
    $data = $this->getIntent($operationId)->toArray();
    $data['cash_state'] = $cashState; $data['projection_state'] = $projectionState; $data['review_reason'] = $reason;
    $this->save($data);
  }
  public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim {
    if ($this->db->inTransaction()) { throw new \RuntimeException('refund_effect_requires_root_commit'); }
    return $this->transaction(function () use ($operationId,$kind,$generation) {
      $data = $this->getIntent($operationId)->toArray(); $key = $kind . ':' . $generation;
      if (isset($data['effects'][$key]) && $data['effects'][$key] !== RefundStates::EFFECT_FAILED_NO_EFFECT) {
        return new EffectClaim(EffectClaim::ALREADY_STARTED);
      }
      $data['effects'][$key] = RefundStates::EFFECT_STARTED; $this->save($data);
      return new EffectClaim(EffectClaim::CLAIMED);
    });
  }
  public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void {
    $data = $this->getIntent($operationId)->toArray(); $key = $kind . ':' . $generation;
    if (($data['effects'][$key] ?? NULL) !== RefundStates::EFFECT_STARTED) { throw new \RuntimeException('refund_effect_not_started'); }
    $data['effects'][$key] = $state; $this->save($data);
  }
  public function marker(string $operationId): ?array {
    $data = $this->db->select('commerce_paymos_refund_projection','p')->fields('p',['data'])
      ->condition('operation_id',$operationId)->execute()->fetchField();
    return $data === FALSE ? NULL : json_decode($data,TRUE,512,JSON_THROW_ON_ERROR);
  }
  public function saveMarker(RefundIntent $intent, array $marker): void {
    $this->assertOwner($intent->paymentKey());
    $this->db->merge('commerce_paymos_refund_projection')->key('operation_id',$intent->operationId())
      ->fields(['payment_key'=>$intent->paymentKey(),'external'=>0,'checked'=>$this->time->getCurrentTime(),
        'data'=>json_encode($marker,JSON_THROW_ON_ERROR)])->execute();
  }
  public function saveExternal(string $key, VerifiedRefund $refund, array $marker): void {
    $this->assertOwner($key);
    $this->db->merge('commerce_paymos_refund_projection')->key('operation_id','external-' . sha1($refund->withdrawalId()))
      ->fields(['payment_key'=>$key,'external'=>1,'checked'=>$this->time->getCurrentTime(),
        'data'=>json_encode($marker + ['observation'=>$refund->toArray()],JSON_THROW_ON_ERROR)])->execute();
  }
  public function assertNoExternalReview(string $key): void {
    $rows = $this->db->select('commerce_paymos_refund_projection','p')->fields('p',['data'])
      ->condition('payment_key',$key)->condition('external',1)->execute()->fetchCol();
    foreach ($rows as $row) {
      $data = json_decode($row,TRUE,512,JSON_THROW_ON_ERROR); $refund = VerifiedRefund::fromStorage($data['observation']);
      if ($refund->mode() === RefundStates::FULL || !empty($data['review_reason'])
        || !in_array($refund->status(),['completed','failed','cancelled'],TRUE)) { throw new \RuntimeException('refund_unbound_native_review'); }
    }
  }
  public function externalDue(\DateTimeImmutable $now, int $limit): array {
    $rows = $this->db->select('commerce_paymos_refund_projection','p')->fields('p',['data'])
      ->condition('external',1)->condition('checked',$now->getTimestamp() - 86400,'<=')->orderBy('checked')
      ->range(0,min(100,max(1,$limit)))->execute()->fetchCol();
    return array_map(static function ($row) { return json_decode($row,TRUE,512,JSON_THROW_ON_ERROR); },$rows);
  }
  public function externalRecent(int $limit = 20): array {
    if (!$this->db->schema()->tableExists('commerce_paymos_refund_projection')) { return []; }
    $rows = $this->db->select('commerce_paymos_refund_projection','p')->fields('p',['data'])
      ->condition('external',1)->orderBy('checked','DESC')->range(0,min(100,max(1,$limit)))->execute()->fetchCol();
    return array_map(static function ($row) { return json_decode($row,TRUE,512,JSON_THROW_ON_ERROR); },$rows);
  }
  public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage {
    $limit = min(100,max(1,$limit));
    $query = $this->db->select('commerce_paymos_refund','r')->fields('r',['data','due','operation_id'])
      ->condition('due',$now->getTimestamp(),'<=')->orderBy('due')->orderBy('operation_id')->range(0,$limit + 1);
    if ($cursor !== NULL) {
      $after = json_decode((string)base64_decode($cursor,TRUE),TRUE);
      if (!is_array($after) || count($after) !== 2 || !is_int($after[0]) || !is_string($after[1])) { throw new \InvalidArgumentException('refund_cursor_invalid'); }
      $query->condition($query->orConditionGroup()->condition('due',$after[0],'>')
        ->condition($query->andConditionGroup()->condition('due',$after[0])->condition('operation_id',$after[1],'>')));
    }
    $rows = $query->execute()->fetchAll(); $hasMore = count($rows) > $limit;
    $rows = array_slice($rows,0,$limit); $items = []; $last = NULL;
    foreach ($rows as $row) { $items[] = new RefundIntent(json_decode($row->data,TRUE,512,JSON_THROW_ON_ERROR)); $last = $row; }
    return new RefundIntentPage($items,$hasMore ? base64_encode(json_encode([(int)$last->due,$last->operation_id])) : NULL);
  }
  public function schedule(string $operationId): void {
    $data = $this->getIntent($operationId)->toArray(); $now = $this->time->getCurrentTime();
    $terminal = in_array($data['cash_state'],[RefundStates::COMPLETED,RefundStates::FAILED,RefundStates::CANCELLED,RefundStates::REVIEW],TRUE);
    if (!$terminal && $now - $data['created_at'] >= 86400) {
      $data['projection_state'] = RefundStates::NATIVE_REVIEW; $data['review_reason'] = 'refund_unresolved_24h';
      $terminal = TRUE;
    }
    $backoff = [60,120,300,900,3600]; $checks = (int)$data['checks']; $data['checks'] = $checks + 1;
    $this->save($data);
    $this->db->update('commerce_paymos_refund')->fields(['due'=>$now + ($terminal ? 86400 : $backoff[min($checks,4)])])
      ->condition('operation_id',$operationId)->execute();
  }
  public function assertHttpAllowed(): void {
    if ($this->db->inTransaction()) { throw new \RuntimeException('refund_http_requires_committed_intent'); }
  }
  public function recent(int $limit = 20): array {
    if (!$this->db->schema()->tableExists('commerce_paymos_refund')) { return []; }
    $rows = $this->db->select('commerce_paymos_refund','r')->fields('r',['data'])
      ->orderBy('created','DESC')->orderBy('operation_id')->range(0,min(100,max(1,$limit)))->execute()->fetchCol();
    return array_map(static function ($row) { return new RefundIntent(json_decode($row,TRUE,512,JSON_THROW_ON_ERROR)); },$rows);
  }
  public function hasHistory(): bool {
    foreach (['commerce_paymos_refund','commerce_paymos_refund_projection'] as $table) {
      if ($this->db->schema()->tableExists($table) && $this->db->select($table,'r')->fields('r',['operation_id'])->range(0,1)->execute()->fetchField() !== FALSE) { return TRUE; }
    }
    return FALSE;
  }
}
