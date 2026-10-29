<?php

declare(strict_types=1);

namespace {
  class Drupal {
    public static $database;
    public static function database() { return self::$database; }
  }
}

namespace Drupal\Core\Database {
  class IntegrityConstraintViolationException extends \RuntimeException {}
}
namespace Drupal\Core\Entity\Sql {
  class SqlContentEntityStorage {}
}
namespace Drupal\Core\State {
  interface StateInterface {}
}
namespace Drupal\Core {
  class PrivateKey { public function get() { return 'fixture-installation-private-key'; } }
}
namespace Drupal\Component\Datetime {
  interface TimeInterface {}
}
namespace Drupal\Core\Site {
  class Settings { public static function getHashSalt() { return 'fixture-site-salt'; } }
}
namespace Symfony\Component\HttpFoundation {
  class RequestStack {
    public function getCurrentRequest() {
      return new class {
        public function isSecure() { return TRUE; }
        public function getSchemeAndHttpHost() { return 'https://shop.example.invalid'; }
        public function getBasePath() { return ''; }
      };
    }
  }
}
namespace Drupal\commerce_price {
  final class Price {
    private $number;
    private $currency;
    public function __construct($number,$currency) { $this->number = $number; $this->currency = $currency; }
    public function getNumber() { return $this->number; }
    public function getCurrencyCode() { return $this->currency; }
  }
}
namespace Drupal\commerce_payment\Entity {
  interface PaymentInterface {}
}
namespace Drupal\commerce_payment\Exception {
  class PaymentGatewayException extends \RuntimeException {}
}
namespace Drupal\commerce_payment\Plugin\Commerce\PaymentGateway {
  interface SupportsRefundsInterface {
    public function refundPayment(\Drupal\commerce_payment\Entity\PaymentInterface $payment, \Drupal\commerce_price\Price $amount = NULL);
    public function canRefundPayment(\Drupal\commerce_payment\Entity\PaymentInterface $payment);
  }
  class OffsitePaymentGatewayBase {
    protected $parentEntity;
    public function getMode() { return 'test'; }
  }
}
namespace PaymosDrupalRefundTest {
  use Drupal\Core\Database\IntegrityConstraintViolationException;
  use Drupal\commerce_price\Price;

  final class Clock implements \Drupal\Component\Datetime\TimeInterface {
    public $now;
    public function __construct() { $this->now = time(); }
    public function getCurrentTime() { return $this->now; }
  }
  final class Result {
    private $rows;
    public function __construct($rows) { $this->rows = array_values($rows); }
    public function fetchField() { return $this->rows ? reset($this->rows[0]) : FALSE; }
    public function fetchCol() { return array_map(static function ($row) { return reset($row); },$this->rows); }
    public function fetchAll() { return array_map(static function ($row) { return (object)$row; },$this->rows); }
  }
  class Conditions {
    protected $conditions = [];
    private $or;
    public function __construct($or = FALSE) { $this->or = $or; }
    public function condition($field,$value = NULL,$operator = '=') { $this->conditions[] = [$field,$value,$operator]; return $this; }
    public function matches($row) {
      $results = [];
      foreach ($this->conditions as $condition) {
        list($field,$value,$operator) = $condition;
        if ($field instanceof self) { $results[] = $field->matches($row); continue; }
        $actual = $row[$field] ?? NULL;
        switch ($operator) {
          case '>': $ok = $actual > $value; break;
          case '<=': $ok = $actual <= $value; break;
          case '>=': $ok = $actual >= $value; break;
          default: $ok = $actual == $value;
        }
        $results[] = $ok;
      }
      return $this->or ? in_array(TRUE,$results,TRUE) : !in_array(FALSE,$results,TRUE);
    }
  }
  final class Query extends Conditions {
    private $db;
    private $table;
    private $kind;
    private $columns = [];
    private $values = [];
    private $keys = [];
    private $orders = [];
    private $offset = 0;
    private $limit = PHP_INT_MAX;
    public function __construct($db,$table,$kind) { parent::__construct(); $this->db = $db; $this->table = $table; $this->kind = $kind; }
    public function fields($alias,$columns = NULL) {
      if (is_array($alias)) { $this->values = $alias; }
      else { $this->columns = $columns; }
      return $this;
    }
    public function key($field,$value) { $this->keys[$field] = $value; return $this; }
    public function forUpdate() { return $this; }
    public function orderBy($column,$direction = 'ASC') { $this->orders[] = $column; return $this; }
    public function range($offset,$limit) { $this->offset = $offset; $this->limit = $limit; return $this; }
    public function orConditionGroup() { return new Conditions(TRUE); }
    public function andConditionGroup() { return new Conditions(); }
    public function execute() {
      if ($this->kind === 'insert' || $this->kind === 'merge') {
        $values = $this->keys + $this->values; $table = $this->db->tables[$this->table] ?? [];
        $unique = [['operation_id'],['event_id'],['payment_key']];
        if ($this->table === 'commerce_paymos_refund') { $unique = [['operation_id'],['installation_id','request_token'],['external_id'],['withdrawal_id']]; }
        if ($this->table === 'commerce_paymos_refund_projection') { $unique = [['operation_id']]; }
        foreach ($table as $index => $row) {
          if ($this->kind === 'merge' && array_intersect_key($row,$this->keys) === $this->keys) {
            $this->db->tables[$this->table][$index] = $values + $row; return 1;
          }
          foreach ($unique as $columns) {
            $same = TRUE;
            foreach ($columns as $column) { if (!isset($values[$column]) || ($row[$column] ?? NULL) !== $values[$column]) { $same = FALSE; } }
            if ($same) { throw new IntegrityConstraintViolationException('fixture_unique'); }
          }
        }
        if ($this->db->failProjection && $this->table === 'commerce_paymos_refund_projection') {
          $this->db->failProjection = FALSE; throw new \RuntimeException('fixture_projection_crash');
        }
        $this->db->tables[$this->table][] = $values; return 1;
      }
      $rows = []; $changed = 0;
      foreach ($this->db->tables[$this->table] ?? [] as $index => $row) {
        if (!$this->matches($row)) { continue; }
        if ($this->kind === 'update') { $this->db->tables[$this->table][$index] = $this->values + $row; $changed++; }
        elseif ($this->kind === 'delete') { unset($this->db->tables[$this->table][$index]); $changed++; }
        else { $rows[] = $row; }
      }
      if ($this->kind !== 'select') { return $changed; }
      usort($rows,function ($a,$b) { foreach ($this->orders as $field) { $cmp = $a[$field] <=> $b[$field]; if ($cmp) { return $cmp; } } return 0; });
      $rows = array_slice($rows,$this->offset,$this->limit);
      return new Result(array_map(function ($row) { return array_intersect_key($row,array_flip($this->columns)); },$rows));
    }
  }
  final class Transaction {
    private $db;
    private $before;
    private $rolledBack = FALSE;
    public function __construct($db) {
      $this->db = $db; $this->before = serialize([$db->tables,$db->entities->payments,$db->entities->orders]); $db->depth++;
    }
    public function rollBack() {
      list($this->db->tables,$this->db->entities->payments,$this->db->entities->orders) = unserialize($this->before);
      $this->rolledBack = TRUE;
    }
    public function __destruct() { $this->db->depth--; }
  }
  final class Database {
    public $tables = ['commerce_paymos_refund'=>[], 'commerce_paymos_refund_projection'=>[], 'commerce_paymos_refund_lock'=>[]];
    public $depth = 0;
    public $entities;
    public $failProjection = FALSE;
    public $driverName = 'pgsql';
    public $engine = 'InnoDB';
    public function select($table,$alias) { return new Query($this,$table,'select'); }
    public function insert($table) { return new Query($this,$table,'insert'); }
    public function merge($table) { return new Query($this,$table,'merge'); }
    public function update($table) { return new Query($this,$table,'update'); }
    public function delete($table) { return new Query($this,$table,'delete'); }
    public function startTransaction() { return new Transaction($this); }
    public function inTransaction() { return $this->depth > 0; }
    public function driver() { return $this->driverName; }
    public function getPrefix() { return 'shop_'; }
    public function query($sql,$parameters) {
      if (strpos($parameters[':table'],'shop_commerce_') !== 0 || strpos($parameters[':table'],'"') !== FALSE) {
        throw new \RuntimeException('fixture_invalid_mysql_table_name');
      }
      return new Result([['ENGINE'=>$this->engine]]);
    }
    public function schema() { return $this; }
    public function tableExists($table) { return array_key_exists($table,$this->tables); }
    public function createTable($table,$definition) { $this->tables[$table] = []; }
  }
  final class StateValue {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function getId() { return $this->value; }
  }
  final class Gateway {
    private $plugin;
    public function __construct($plugin = NULL) { $this->plugin = $plugin; }
    public function id() { return 'paymos'; }
    public function getPluginId() { return 'paymos'; }
    public function getPlugin() { return $this->plugin; }
  }
  final class Payment implements \Drupal\commerce_payment\Entity\PaymentInterface {
    public $gateway;
    public $amount = '100.00';
    public $refunded = '0';
    public $state = 'completed';
    public $saves = 0;
    public function id() { return '1'; }
    public function uuid() { return 'native-payment-uuid'; }
    public function getOrderId() { return '10'; }
    public function getAmount() { return new Price($this->amount,'USD'); }
    public function getRefundedAmount() { return new Price($this->refunded,'USD'); }
    public function getRemoteId() { return 'inv_fixture'; }
    public function getPaymentGateway() { return $this->gateway ?: new Gateway(); }
    public function getPaymentGatewayId() { return 'paymos'; }
    public function getPaymentGatewayMode() { return 'test'; }
    public function getState() { return new StateValue($this->state); }
    public function setRefundedAmount($price) { $this->refunded = $price->getNumber(); return $this; }
    public function setState($state) { $this->state = $state; return $this; }
    public function save() { $this->saves++; }
    public function access($op,$account) { return $account->allowed; }
  }
  final class Order {
    public $data = [];
    public $stock = 7;
    public $state = 'completed';
    public function id() { return '10'; }
    public function uuid() { return 'native-order-uuid'; }
    public function isPaid() { return TRUE; }
    public function getTotalPrice() { return new Price('100.00','USD'); }
    public function getData($key,$default) { return $this->data[$key] ?? $default; }
    public function setData($key,$value) { $this->data[$key] = $value; return $this; }
    public function save() {}
  }
  final class Storage extends \Drupal\Core\Entity\Sql\SqlContentEntityStorage {
    private $entities;
    private $kind;
    public function __construct($entities,$kind) { $this->entities = $entities; $this->kind = $kind; }
    public function resetCache($ids = []) {}
    public function load($id) {
      if ($this->kind === 'commerce_currency') { return new class { public function getFractionDigits() { return 2; } }; }
      $map = $this->kind === 'commerce_payment' ? $this->entities->payments : $this->entities->orders;
      return $map[$id] ?? NULL;
    }
    public function loadByProperties($properties) { return $this->entities->payments; }
  }
  final class Entities {
    public $payments;
    public $orders;
    public function __construct() { $this->payments = ['1'=>new Payment()]; $this->orders = ['10'=>new Order()]; }
    public function getStorage($kind) { return new Storage($this,$kind); }
  }
  final class Config {
    public function get($key) { return $key === 'uuid' ? 'installation-fixture-uuid' : $this; }
  }
}
