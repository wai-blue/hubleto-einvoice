<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\Migrations;

use Hubleto\Framework\Migration;

class Einvoice_0003 extends Migration
{
  public function upgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      DROP INDEX `provider_transmission_id`,
      ADD UNIQUE INDEX `provider_transmission_id` (`provider`, `transmission_id`)");
  }

  public function downgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      DROP INDEX `provider_transmission_id`,
      ADD INDEX `provider_transmission_id` (`provider`, `transmission_id`)");
  }

  public function upgradeForeignKeys(): void
  {
  }

  public function downgradeForeignKeys(): void
  {
  }
}
