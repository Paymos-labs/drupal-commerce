<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_paymos\Plugin\Commerce\PaymentGateway\Paymos;
use Symfony\Component\HttpFoundation\{Request, RequestStack};

/** Session-only navigation to native Commerce callbacks, never payment evidence. */
final class ReturnBridge {
  private const KEY = 'commerce_paymos.return';
  private const TTL = 3600;

  public function __construct(private RequestStack $requests, private TimeInterface $time,
    private EntityTypeManagerInterface $entities) {}

  public function remember(OrderInterface $order, string $returnUrl, string $cancelUrl): void {
    $request = $this->requests->getCurrentRequest();
    if (!$request || !$request->isSecure() || !$request->hasSession()) {
      throw new \RuntimeException('paymos_return_session_required');
    }
    $return = $this->localTarget($returnUrl, $request);
    $cancel = $this->localTarget($cancelUrl, $request);
    if ($return === NULL || $cancel === NULL) {
      throw new \InvalidArgumentException('paymos_invalid_native_return');
    }
    $request->getSession()->set(self::KEY, [
      'order_id' => (string) $order->id(), 'order_uuid' => $order->uuid(),
      'gateway_id' => (string) $order->get('payment_gateway')->target_id,
      'source_url' => $this->source($request), 'return_url' => $return, 'cancel_url' => $cancel,
      'expires_at' => $this->time->getCurrentTime() + self::TTL,
    ]);
  }

  public function target(bool $cancel): string {
    $request = $this->requests->getCurrentRequest();
    $fallback = ($request?->getBasePath() ?? '') . '/';
    if (!$request || !$request->isSecure() || !$request->hasSession()) { return $fallback; }
    // Consume the navigation context. A subsequent checkout refreshes it.
    $context = $request->getSession()->remove(self::KEY);
    try {
      if (!is_array($context) || ($context['expires_at'] ?? 0) <= $this->time->getCurrentTime()
        || ($context['source_url'] ?? '') !== $this->source($request)
        || !is_string($context['order_id'] ?? NULL) || !ctype_digit($context['order_id'])
        || strlen($context['order_id']) > 64) { return $fallback; }
      $order = $this->entities->getStorage('commerce_order')->loadUnchanged($context['order_id']);
      if (!$order instanceof OrderInterface || $order->uuid() !== ($context['order_uuid'] ?? '')
        || (string) $order->get('payment_gateway')->target_id !== ($context['gateway_id'] ?? '')
        || !$order->get('payment_gateway')->entity?->getPlugin() instanceof Paymos) { return $fallback; }
      $target = $context[$cancel ? 'cancel_url' : 'return_url'] ?? NULL;
      return is_string($target) ? ($this->localTarget($target, $request) ?? $fallback) : $fallback;
    }
    catch (\Throwable $e) { return $fallback; }
  }

  private function source(Request $request): string {
    return $request->getSchemeAndHttpHost() . $request->getBasePath();
  }

  private function localTarget(string $url, Request $request): ?string {
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return NULL; }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { return NULL; }
    if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])) {
      if (strtolower($parts['scheme'] ?? '') !== 'https'
        || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
        || ($parts['port'] ?? 443) !== $request->getPort()) { return NULL; }
    }
    $path = $parts['path'] ?? '';
    $base = $request->getBasePath();
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')
      || ($base !== '' && !str_starts_with($path, $base . '/'))) { return NULL; }
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
  }
}
