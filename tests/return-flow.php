<?php

declare(strict_types=1);

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_paymos\Controller\ReturnController;
use Drupal\commerce_paymos\Service\ReturnBridge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Actual native session/entities/redirect handling; no payment HTTP requests. */
function paymos_native_return_flow(OrderInterface $order, bool $routes = TRUE): void {
  $container = \Drupal::getContainer();
  if (!$container->has('commerce_paymos.return_bridge')) {
    throw new RuntimeException('Native buyer return bridge is not registered');
  }
  $check = static function(bool $value, string $label): void {
    if (!$value) { throw new RuntimeException('Native return regression: ' . $label); }
  };
  $stack = $container->get('request_stack');
  $source = $stack->getCurrentRequest()->getSchemeAndHttpHost();
  $session = new Session(new MockArraySessionStorage());
  $request = Request::create($source . '/paymos/return');
  $request->setSession($session);
  $clock = new class(time()) implements TimeInterface {
    public function __construct(public int $now) {}
    public function getRequestTime(): int { return $this->now; }
    public function getRequestMicroTime(): float { return (float) $this->now; }
    public function getCurrentTime(): int { return $this->now; }
    public function getCurrentMicroTime(): float { return (float) $this->now; }
  };
  $bridge = new ReturnBridge($stack, $clock, $container->get('entity_type.manager'));
  $return = '/checkout/' . $order->id() . '/payment/return';
  $cancel = '/checkout/' . $order->id() . '/payment/cancel';
  $storage = $container->get('entity_type.manager')->getStorage('commerce_payment');
  $payments = count($storage->loadByProperties(['order_id' => $order->id()]));
  $paid = $order->isPaid();
  $stack->push($request);
  try {
    $check($bridge->target(FALSE) === '/', 'missing session context');
    $bridge->remember($order, $source . $return, $source . $cancel);
    $check($bridge->target(FALSE) === $return, 'native success');
    $check($bridge->target(FALSE) === '/', 'consumed context');
    $bridge->remember($order, $return, $cancel);
    $check($bridge->target(TRUE) === $cancel, 'native cancellation');
    $bridge->remember($order, $return . '?attempt=old', $cancel);
    $bridge->remember($order, $return . '?attempt=new', $cancel);
    $check($bridge->target(FALSE) === $return . '?attempt=new', 'latest native callback');
    $bridge->remember($order, $return, $cancel);
    $clock->now += 3600;
    $check($bridge->target(FALSE) === '/', 'one-hour expiry boundary');
    $clock->now -= 3600;
    foreach (['order_uuid' => 'changed', 'gateway_id' => 'other', 'source_url' => 'https://other.invalid',
      'order_id' => '999999999999999999999999', 'return_url' => '//other.invalid'] as $key => $value) {
      $bridge->remember($order, $return, $cancel);
      $context = $session->get('commerce_paymos.return');
      $context[$key] = $value;
      $session->set('commerce_paymos.return', $context);
      $check($bridge->target(FALSE) === '/', 'changed binding ' . $key);
    }
    foreach (['https://other.invalid/path', '//other.invalid', 'http://' . $request->getHost() . $return,
      $source . ':444' . $return, 'https://user@' . $request->getHost() . $return,
      $return . '#fragment', '/\\other.invalid', $return . "\r\n"] as $unsafe) {
      try {
        $bridge->remember($order, $unsafe, $cancel);
        throw new LogicException('Unsafe native return accepted');
      }
      catch (InvalidArgumentException $e) {}
    }
    $bridge->remember($order, $return, $cancel);
    $other = Request::create($source . '/paymos/return');
    $other->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($other);
    try { $check($bridge->target(FALSE) === '/', 'different browser'); }
    finally { $stack->pop(); }
    $check($bridge->target(FALSE) === $return, 'original browser retained');

    $sub = Request::create($source . '/sub/index.php/paymos/return', 'GET', [], [], [],
      ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/var/www/sub/index.php']);
    $sub->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($sub);
    try {
      $check($sub->getBasePath() === '/sub', 'native basepath fixture');
      $bridge->remember($order, '/sub' . $return, '/sub' . $cancel);
      $check($bridge->target(FALSE) === '/sub' . $return, 'basepath callback retained');
      try {
        $bridge->remember($order, $return, $cancel);
        throw new LogicException('Callback outside store basepath accepted');
      }
      catch (InvalidArgumentException $e) {}
      $check($bridge->target(FALSE) === '/sub/', 'basepath fallback');
    }
    finally { $stack->pop(); }

    $request->query->set('destination', '/user/login');
    $request->query->set('url', 'https://other.invalid');
    $request->query->set('paid', '1');
    $bridge->remember($order, $return, $cancel);
    $subscriber = $container->get('redirect_response_subscriber');
    try {
      $response = (new ReturnController($bridge, $subscriber))->handle($request);
      $event = new ResponseEvent($container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);
      $subscriber->checkRedirectUrl($event);
      $check(parse_url($event->getResponse()->getTargetUrl(), PHP_URL_PATH) === $return, 'destination ignored');
      $check(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'), 'no shared cache');
    }
    finally { $subscriber->setIgnoreDestination(FALSE); }
    $fresh = $container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($order->id());
    $check($fresh->isPaid() === $paid && count($storage->loadByProperties(['order_id' => $order->id()])) === $payments,
      'navigation never creates payment');
    if ($routes) {
      $route = $container->get('router.route_provider')->getRouteByName('commerce_paymos.return');
      $check($route->getMethods() === ['GET'] && $route->getOption('no_cache') === TRUE, 'native public route/cache policy');
    }
  }
  finally { $stack->pop(); }
  print "PASS native buyer return: success, cancel, latest, expiry, binding, other browser, unsafe URL, destination, no-store, no payment mutation\n";
}
