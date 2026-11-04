<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\Migrations;

use Hubleto\Framework\Migration;

class Einvoice_0002 extends Migration
{
  public function upgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      ADD `provider` varchar(50) NULL AFTER `id_invoice`,
      ADD `invoice_number` varchar(255) NULL AFTER `provider`,
      ADD INDEX `provider_transmission_id` (`provider`, `transmission_id`),
      ADD INDEX `invoice_number` (`invoice_number`)");

    $this->db->execute("UPDATE `einvoices` SET `provider` = 'epostak' WHERE `provider` IS NULL");
    $this->db->execute("UPDATE `einvoices` `e`
      JOIN `invoices` `i` ON `i`.`id` = `e`.`id_invoice`
      SET `e`.`invoice_number` = `i`.`number`
      WHERE `e`.`invoice_number` IS NULL");
  }

  public function downgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      DROP INDEX `provider_transmission_id`,
      DROP INDEX `invoice_number`,
      DROP COLUMN `invoice_number`,
      DROP COLUMN `provider`");
  }

  public function upgradeForeignKeys(): void
  {
  }

  public function downgradeForeignKeys(): void
  {
  }
}
