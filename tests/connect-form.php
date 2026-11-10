<?php

declare(strict_types=1);

namespace Drupal\Core\Form {
    interface FormStateInterface {}
    class FormBase {
        public function currentUser() { return (object) ['id' => 1]; }
        public function t($text) { return $text; }
        public function messenger() { return new class {
            public function addStatus($text) {}
            public function addError($text) { throw new \RuntimeException($text); }
        }; }
    }
}

namespace {
    class Drupal {
        public static $services = [];
        public static function service($name) { return self::$services[$name]; }
    }
    require dirname(__DIR__) . '/src/Form/ConnectForm.php';
    $form = (new ReflectionClass(\Drupal\commerce_paymos\Form\ConnectForm::class))->newInstanceWithoutConstructor();
    $state = new class implements \Drupal\Core\Form\FormStateInterface {
        public $data = [];
        public $payment = 'A';
        public function get($key) { return $this->data[$key] ?? null; }
        public function set($key, $value) { $this->data[$key] = $value; return $this; }
        public function getValue($key) { return $this->payment; }
        public function setRebuild() {}
    };
    $plugin = new class {
        public $requests = [];
        public function requestRefund($payment, $mode, $token, $user) { $this->requests[] = [$payment->id(), $mode, $token]; }
    };
    $gateway = new class($plugin) {
        private $plugin;
        public function __construct($plugin) { $this->plugin = $plugin; }
        public function getPluginId() { return 'paymos'; }
        public function getPlugin() { return $this->plugin; }
    };
    Drupal::$services['entity_type.manager'] = new class($gateway) {
        private $gateway;
        public function __construct($gateway) { $this->gateway = $gateway; }
        public function getStorage($name) { return $this; }
        public function load($id) { return new class($id, $this->gateway) {
            private $id; private $gateway;
            public function __construct($id, $gateway) { $this->id = $id; $this->gateway = $gateway; }
            public function id() { return $this->id; }
            public function getPaymentGateway() { return $this->gateway; }
        }; }
    };
    Drupal::$services['uuid'] = new class {
        private $next = 0;
        public function generate() { return 'operation-' . ++$this->next; }
    };
    $render = [];
    $form->refundFull($render, $state);
    $form->refundFull($render, $state);
    $state->payment = 'B';
    $form->refundFull($render, $state);
    $form->refundOverpayment($render, $state);
    $state->payment = 'A';
    $form->refundFull($render, $state);
    $tokens = array_column($plugin->requests, 2);
    if ($tokens !== ['operation-1', 'operation-1', 'operation-2', 'operation-3', 'operation-1']) {
        throw new RuntimeException('Payment/mode identity must isolate keys and preserve retries, including switching back.');
    }
    echo "PASS BUG-1047 same-form payment/mode keys and retries\n";
}
