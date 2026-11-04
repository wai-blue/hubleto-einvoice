<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\Migrations;

use Hubleto\Framework\Migration;

class Einvoice_0004 extends Migration
{
  public function upgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      ADD `inbound_outbound` int NOT NULL DEFAULT 2 AFTER `id_invoice`,
      ADD INDEX `inbound_outbound` (`inbound_outbound`)");
  }

  public function downgradeSchema(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
      DROP INDEX `inbound_outbound`,
      DROP COLUMN `inbound_outbound`");
  }

  public function upgradeForeignKeys(): void
  {
  }

  public function downgradeForeignKeys(): void
  {
  }
}
