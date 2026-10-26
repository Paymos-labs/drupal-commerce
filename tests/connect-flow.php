<?php

declare(strict_types=1);

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormState;
use Drupal\commerce_paymos\Form\ConnectForm;
use Drupal\commerce_paymos\Service\ConnectionStore;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\{HttpResponse, MockTransport};

/** Actual form callbacks, SDK protocol and encrypted Drupal state; mock HTTP only. */
function paymos_native_connect_flow(): void {
  $clock = new class(time()) implements TimeInterface {
    public function __construct(public int $now) {}
    public function getRequestTime(): int { return $this->now; }
    public function getRequestMicroTime(): float { return (float) $this->now; }
    public function getCurrentTime(): int { return $this->now; }
    public function getCurrentMicroTime(): float { return (float) $this->now; }
  };
  $container = \Drupal::getContainer();
  $connection = new ConnectionStore($container->get('state'), $container->get('private_key'),
    $container->get('request_stack'), $clock);
  $connection->disconnect();
  $source = $connection->sourceUrl();
  $environments = [];
  foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
    $environments[$environment] = ['api_key' => 'pk_' . $prefix . '_native',
      'api_secret' => 'sk_' . $prefix . '_native', 'project_id' => 'prj_native',
      'webhook_secret' => 'whsec_' . $prefix . '_native'];
  }
  $response = static fn(array $data, int $status = 200): HttpResponse => new HttpResponse($status, json_encode($data, JSON_THROW_ON_ERROR), []);
  $transport = new MockTransport([
    $response(['device_code' => 'device_native_fixture', 'user_code' => 'NATV-CODE',
      'verification_uri_complete' => 'https://app.paymos.io/connect/cms/verify?user_code=NATV-CODE',
      'expires_in' => 600, 'interval' => 5]),
    $response(['error' => 'authorization_pending'], 400),
    $response(['access_token' => 'ephemeral_fixture_token', 'paymos_config' => [
      'plugin' => 'drupal-commerce', 'source_url' => $source, 'environments' => $environments]]),
  ]);
  $form = new ConnectForm($connection, new DeviceConnectClient('https://app.paymos.io', $transport),
    $clock, $container->get('commerce_paymos.reconciler'));
  $render = [];
  $formState = new FormState();
  $form->submitForm($render, $formState);
  $device = $connection->device();
  if (!$device || count($transport->requests()) !== 1 || !$formState->isRebuilding()) {
    throw new RuntimeException('Native Connect start did not persist its device state');
  }
  parse_str((string) parse_url($device['verification_url'], PHP_URL_QUERY), $approval);
  if (($approval['cms'] ?? '') !== 'drupal-commerce' || ($approval['source_url'] ?? '') !== $source
    || !str_starts_with($approval['return_url'] ?? '', $source . '/admin/commerce/config/payment/paymos')) {
    throw new RuntimeException('Native Connect approval binding is incorrect');
  }
  $deviceCiphertext = $container->get('state')->get('commerce_paymos.device');
  if (!is_string($deviceCiphertext) || str_contains($deviceCiphertext, 'device_native_fixture')) {
    throw new RuntimeException('Native temporary device state is not encrypted');
  }
  $form->checkConnection($render, $formState);
  if (count($transport->requests()) !== 1) { throw new RuntimeException('Early poll was not rate limited locally'); }
  $clock->now += 6;
  $form->checkConnection($render, $formState);
  if ($connection->credentials() !== [] || count($transport->requests()) !== 2) {
    throw new RuntimeException('Pending approval must not save credentials');
  }
  $clock->now += 6;
  $form->checkConnection($render, $formState);
  $saved = $connection->credentials();
  if (count($transport->requests()) !== 3 || ($saved['sandbox']['project_id'] ?? '') !== 'prj_native'
    || ($saved['live']['project_id'] ?? '') !== 'prj_native' || $connection->device() !== NULL) {
    throw new RuntimeException('Approved connection did not store both bound environments and clear the device');
  }
  $ciphertext = $container->get('state')->get('commerce_paymos.connection');
  foreach (['sk_test_native', 'sk_live_native', 'ephemeral_fixture_token'] as $forbidden) {
    if (str_contains($ciphertext, $forbidden)) { throw new RuntimeException('Connect state leaked a credential or token'); }
  }
  $wrong = $environments;
  $wrong['live']['project_id'] = 'prj_wrong';
  try {
    $connection->accept(['status' => 'connected', 'plugin' => 'drupal-commerce',
      'source_url' => $source, 'credentials' => $wrong], $source);
    throw new LogicException('Mixed-project credentials were accepted');
  }
  catch (RuntimeException $e) {
    if ($connection->credentials() !== $saved) { throw new RuntimeException('Rejected connection overwrote the valid connection'); }
  }
  $connection->saveDevice(['cms' => 'drupal-commerce', 'source_url' => $source,
    'started_at' => $clock->now - 2, 'expires_in' => 1, 'device_code' => 'expired_fixture']);
  if ($connection->device() !== NULL || $container->get('state')->get('commerce_paymos.device') !== NULL) {
    throw new RuntimeException('Expired device state was not cleared');
  }
  $connection->disconnect();
  if ($connection->credentials() !== []) { throw new RuntimeException('Disconnect did not clear credentials'); }
  print "PASS native Connect callbacks + SDK: start, local poll interval, pending, approval, encrypted two environments, project binding, token disposal, expiry, disconnect\n";
}
