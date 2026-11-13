<?php

declare(strict_types=1);

// 48-02: a Connect poll that was waiting for its HTTP answer while another
// administrator started a newer attempt must leave that attempt and the stored
// credentials untouched. Runs the real ConnectForm::checkConnection and
// ConnectionStore over minimal Drupal doubles; only the Connect HTTP is scripted.

namespace Drupal\Core\Form {
    interface FormStateInterface { public function setRebuild(); }
    class FormBase {
        public static $uid = '1';
        public function currentUser() { return new class { public function id() { return \Drupal\Core\Form\FormBase::$uid; } }; }
        public function t($text, array $args = []) { return $text; }
        public function messenger() { return new class {
            public function addStatus($text) { $GLOBALS['paymos_connect_messages'][] = ['status', (string) $text]; }
            public function addError($text) { $GLOBALS['paymos_connect_messages'][] = ['error', (string) $text]; }
        }; }
    }
}

namespace Drupal\Core {
    class PrivateKey { public function get() { return 'private-key-fixture'; } }
}

namespace Drupal\Core\Site {
    class Settings { public static function getHashSalt() { return 'hash-salt-fixture'; } }
}

namespace Drupal\Core\State {
    interface StateInterface {
        public function get($key, $default = NULL);
        public function set($key, $value);
        public function delete($key);
    }
}

namespace Drupal\Core\Lock {
    interface LockBackendInterface {
        public function acquire($name, $timeout = 30.0);
        public function wait($name, $delay = 30);
        public function release($name);
    }
}

namespace Drupal\Component\Datetime {
    interface TimeInterface { public function getCurrentTime(); }
}

namespace Drupal\Core {
    class Url {}
}

namespace Drupal\commerce_paymos\Service {
    final class Reconciler {}
}

namespace Symfony\Component\HttpFoundation {
    class Request {
        public function isSecure() { return TRUE; }
        public function getSchemeAndHttpHost() { return 'https://shop.example.test'; }
        public function getBasePath() { return ''; }
    }
    class RequestStack { public function getCurrentRequest() { return new Request(); } }
}

namespace Symfony\Component\DependencyInjection {
    interface ContainerInterface {}
}

namespace {
    $root = dirname(__DIR__);
    $sdk = getenv('PAYMOS_SDK_SRC') ?: dirname($root) . '/php-sdk/src';
    spl_autoload_register(static function (string $class) use ($root, $sdk): void {
        foreach (['Paymos\\' => $sdk . '/', 'Drupal\\commerce_paymos\\' => $root . '/src/'] as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) { require_once $file; }
            }
        }
    });

    use Drupal\commerce_paymos\Form\ConnectForm;
    use Drupal\commerce_paymos\Service\ConnectionStore;
    use Paymos\Connect\DeviceConnectClient;
    use Paymos\Http\HttpResponse;

    final class GenerationState implements \Drupal\Core\State\StateInterface {
        public array $values = [];
        public function get($key, $default = NULL) { return $this->values[$key] ?? $default; }
        public function set($key, $value) { $this->values[$key] = $value; }
        public function delete($key) { unset($this->values[$key]); }
    }
    final class GenerationLock implements \Drupal\Core\Lock\LockBackendInterface {
        public array $held = [];
        public int $acquired = 0;
        public function acquire($name, $timeout = 30.0) { if (isset($this->held[$name])) { return FALSE; } $this->held[$name] = TRUE; $this->acquired++; return TRUE; }
        public function wait($name, $delay = 30) { return isset($this->held[$name]); }
        public function release($name) { unset($this->held[$name]); }
    }
    final class GenerationClock implements \Drupal\Component\Datetime\TimeInterface {
        public int $now = 1700000000;
        public function getCurrentTime() { return $this->now; }
    }
    /** Answers the poll, after letting another administrator start attempt B. */
    final class GenerationTransport implements \Paymos\Http\TransportInterface {
        public $duringPoll;
        public HttpResponse $answer;
        public int $calls = 0;
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            $this->calls++;
            ($this->duringPoll)();
            return $this->answer;
        }
    }
    final class GenerationFormState implements \Drupal\Core\Form\FormStateInterface {
        public function setRebuild() {}
    }

    function generation_expect($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

    function generation_device(string $code, int $now): array {
        return ['cms' => ConnectionStore::CMS, 'source_url' => 'https://shop.example.test', 'device_code' => $code,
            'user_code' => strtoupper($code), 'verification_url' => 'https://app.paymos.io/connect/cms/verify?user_code=' . $code,
            'interval' => 5, 'expires_in' => 600, 'started_at' => $now - 10, 'next_poll' => 0, 'owner_uid' => '1'];
    }

    $environments = [];
    foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
        $environments[$environment] = ['api_key' => 'pk_' . $prefix . '_a', 'api_secret' => 'sk_' . $prefix . '_a',
            'project_id' => 'prj_a', 'webhook_secret' => 'whsec_' . $prefix . '_a'];
    }
    $answers = [
        'connected' => new HttpResponse(200, json_encode(['access_token' => 'token_a', 'paymos_config' => [
            'plugin' => 'drupal-commerce', 'source_url' => 'https://shop.example.test', 'environments' => $environments]]), []),
        'denied' => new HttpResponse(400, json_encode(['error' => 'access_denied']), []),
        'pending' => new HttpResponse(400, json_encode(['error' => 'authorization_pending']), []),
        'slow_down' => new HttpResponse(400, json_encode(['error' => 'slow_down']), []),
    ];

    $failed = 0;
    foreach ($answers as $variant => $answer) {
        try {
            $GLOBALS['paymos_connect_messages'] = [];
            $state = new GenerationState();
            $lock = new GenerationLock();
            $clock = new GenerationClock();
            $store = new ConnectionStore($state, new \Drupal\Core\PrivateKey(), new \Symfony\Component\HttpFoundation\RequestStack(), $clock, $lock);
            $store->startAttempt(generation_device('device_a', $clock->now));
            $transport = new GenerationTransport();
            $transport->answer = $answer;
            $deviceB = generation_device('device_b', $clock->now);
            $deviceB['owner_uid'] = '2';
            $transport->duringPoll = static function () use ($store, $deviceB): void { $store->startAttempt($deviceB); };
            $form = new ConnectForm($store, new DeviceConnectClient('https://app.paymos.io', $transport), $clock,
                new \Drupal\commerce_paymos\Service\Reconciler());

            $render = [];
            $form->checkConnection($render, new GenerationFormState());

            generation_expect($transport->calls === 1, $variant . ': attempt A was polled once');
            $current = $store->device();
            generation_expect(($current['device_code'] ?? NULL) === 'device_b', $variant . ': attempt B survives the stale poll of A');
            generation_expect($current === $deviceB, $variant . ': attempt B is stored exactly as its administrator saved it');
            generation_expect($state->get('commerce_paymos.connection') === NULL, $variant . ': the stale poll stores no credentials');
            generation_expect($lock->held === [], $variant . ': the Connect lock is released');
            echo "PASS stale_connect_poll_preserves_new_flow_and_credentials[$variant]\n";
        }
        catch (Throwable $e) {
            $failed++;
            echo "FAIL stale_connect_poll_preserves_new_flow_and_credentials[$variant]: " . $e->getMessage() . "\n";
        }
    }

    // The current attempt still completes normally.
    try {
        $state = new GenerationState();
        $clock = new GenerationClock();
        $store = new ConnectionStore($state, new \Drupal\Core\PrivateKey(), new \Symfony\Component\HttpFoundation\RequestStack(), $clock, new GenerationLock());
        $store->startAttempt(generation_device('device_a', $clock->now));
        $transport = new GenerationTransport();
        $transport->answer = $answers['connected'];
        $transport->duringPoll = static function (): void {};
        $render = [];
        (new ConnectForm($store, new DeviceConnectClient('https://app.paymos.io', $transport), $clock,
            new \Drupal\commerce_paymos\Service\Reconciler()))->checkConnection($render, new GenerationFormState());
        generation_expect(($store->credentials()['live']['project_id'] ?? '') === 'prj_a', 'current attempt stores its credentials');
        generation_expect($store->device() === NULL, 'current attempt clears its device state');
        echo "PASS current_connect_poll_completes\n";
    }
    catch (Throwable $e) {
        $failed++;
        echo 'FAIL current_connect_poll_completes: ' . $e->getMessage() . "\n";
    }

    exit($failed ? 1 : 0);
}
