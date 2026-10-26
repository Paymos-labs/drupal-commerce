<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/** Prevents removal of the only receiver for unresolved invoices. */
final class UninstallValidator implements ModuleUninstallValidatorInterface {
  use StringTranslationTrait;

  public function __construct(private Connection $database) {}

  public function validate($module): array {
    if ($module !== 'commerce_paymos' || !$this->database->schema()->tableExists('commerce_paymos_invoice')) {
      return [];
    }
    $rows = $this->database->select('commerce_paymos_invoice', 'i')->fields('i', ['data'])
      ->condition('credited', 0)->execute();
    foreach ($rows as $row) {
      $record = json_decode($row->data, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!in_array($record['status'] ?? '', ['expired', 'underpaid', 'cancelled'], TRUE)) {
        return [(string) $this->t('Finish pending Paymos payments before uninstalling this module')];
      }
    }
    return [];
  }
}
