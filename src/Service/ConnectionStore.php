<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\PrivateKey;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\commerce_paymos\SdkBootstrap;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;
use Symfony\Component\HttpFoundation\RequestStack;

/** Stores secrets outside exportable gateway configuration. */
final class ConnectionStore {
  public const CMS = 'drupal-commerce';
  private const KEY = 'commerce_paymos.connection';
  private const DEVICE = 'commerce_paymos.device';
  private const AAD = 'paymos-drupal-commerce-v1';

  private const LOCK = 'commerce_paymos.connect';

  public function __construct(
    private StateInterface $state,
    private PrivateKey $privateKey,
    private RequestStack $requests,
    private TimeInterface $time,
    private ?LockBackendInterface $lock = NULL,
  ) { SdkBootstrap::register(); }

  /**
   * Runs $action only while the stored Connect attempt is still the one
   * identified by $deviceCode (48-02). A poll's HTTP request runs outside this
   * lock; another administrator may start a newer attempt meanwhile, and the
   * older poll's result must neither replace that attempt's state nor clear it
   * nor write the older approval's credentials. Every device-state write goes
   * through this lock, so the check and the write are one step.
   */
  public function whileCurrentAttempt(string $deviceCode, callable $action): mixed {
    return $this->locked(function () use ($deviceCode, $action): mixed {
      $current = $this->device();
      if (!$current || !is_string($current['device_code'] ?? NULL) || !hash_equals($current['device_code'], $deviceCode)) {
        throw new \RuntimeException('paymos_connect_attempt_replaced');
      }
      return $action();
    });
  }

  /** Starts a new attempt; it replaces any earlier one. */
  public function startAttempt(array $device): void {
    $this->locked(function () use ($device): void { $this->saveDevice($device); });
  }

  private function locked(callable $action): mixed {
    $lock = $this->lock ?? \Drupal::lock();
    if (!$lock->acquire(self::LOCK, 30.0)) {
      $lock->wait(self::LOCK, 10);
      if (!$lock->acquire(self::LOCK, 30.0)) { throw new \RuntimeException('paymos_connect_busy'); }
    }
    try { return $action(); }
    finally { $lock->release(self::LOCK); }
  }

  public function sourceUrl(): string {
    $request = $this->requests->getCurrentRequest();
    if (!$request || !$request->isSecure()) {
      throw new \RuntimeException('paymos_https_required');
    }
    return rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
  }

  private function material(): string {
    return $this->privateKey->get() . "\n" . Settings::getHashSalt();
  }

  /** @return array<string, array<string, string>> */
  public function credentials(): array {
    $envelope = $this->state->get(self::KEY);
    if (!is_string($envelope) || $envelope === '') {
      return [];
    }
    $data = AesGcmEnvelope::open($envelope, $this->material(), self::AAD);
    if (($data['plugin'] ?? '') !== self::CMS || ($data['source_url'] ?? '') !== $this->sourceUrl()) {
      throw new \RuntimeException('paymos_store_binding_changed');
    }
    return CredentialSet::normalize($data['environments'] ?? []);
  }

  public function accept(array $result, string $expectedSource): void {
    if (($result['status'] ?? '') !== 'connected'
      || ($result['plugin'] ?? '') !== self::CMS
      || ($result['source_url'] ?? '') !== $expectedSource
      || $expectedSource !== $this->sourceUrl()) {
      throw new \RuntimeException('paymos_connect_binding_mismatch');
    }
    $credentials = CredentialSet::normalize($result['credentials'] ?? []);
    if (!isset($credentials['sandbox'], $credentials['live'])
      || $credentials['sandbox']['project_id'] !== $credentials['live']['project_id']) {
      throw new \RuntimeException('paymos_connect_incomplete');
    }
    $data = ['plugin' => self::CMS, 'source_url' => $expectedSource, 'environments' => $credentials];
    $this->state->set(self::KEY, AesGcmEnvelope::seal($data, $this->material(), self::AAD));
    $this->clearDevice();
  }

  public function saveDevice(array $device): void {
    if (($device['cms'] ?? '') !== self::CMS || ($device['source_url'] ?? '') !== $this->sourceUrl()) {
      throw new \RuntimeException('paymos_device_binding_mismatch');
    }
    $this->state->set(self::DEVICE, AesGcmEnvelope::seal($device, $this->material(), self::AAD . ':device'));
  }

  public function device(): ?array {
    $envelope = $this->state->get(self::DEVICE);
    if (!is_string($envelope) || $envelope === '') { return NULL; }
    $device = AesGcmEnvelope::open($envelope, $this->material(), self::AAD . ':device');
    if ($this->time->getCurrentTime() >= (int)($device['started_at'] ?? 0) + (int)($device['expires_in'] ?? 0)) {
      $this->clearDevice();
      return NULL;
    }
    return $device;
  }

  public function clearDevice(): void {
    $this->state->delete(self::DEVICE);
  }

  public function disconnect(): void {
    $this->state->delete(self::KEY);
    $this->clearDevice();
  }
}
