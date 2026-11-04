<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\Migrations;

use Hubleto\Framework\Migration;

class Log_0001 extends Migration
{

  public function upgradeSchema(): void
  {
    $this->db->execute("set foreign_key_checks = 0;
drop table if exists `einvoice_logs`;
set foreign_key_checks = 1;");
    $this->db->execute("SET foreign_key_checks = 0;
drop table if exists `einvoice_logs`;
create table `einvoice_logs` (
 `id` int(8) primary key auto_increment,
 `id_einvoice` int(8) NULL default NULL,
 `action_type` varchar(255) ,
 `status` int(255) ,
 `message` text ,
 `timestamp` datetime ,
 index `id` (`id`),
 index `id_einvoice` (`id_einvoice`),
 index `status` (`status`),
 index `timestamp` (`timestamp`)) ENGINE = InnoDB;
SET foreign_key_checks = 1;");
  }

  public function downgradeSchema(): void
  {
    $this->db->execute("set foreign_key_checks = 0;
drop table if exists `einvoice_logs`;
set foreign_key_checks = 1;");
  }

  public function upgradeForeignKeys(): void
  {
    $this->db->execute("ALTER TABLE `einvoice_logs`
          ADD CONSTRAINT `fk_einvoice_logs_id_einvoice`
          FOREIGN KEY (`id_einvoice`)
          REFERENCES `einvoices` (`id`)
          ON DELETE CASCADE
          ON UPDATE RESTRICT;");
  }

  public function downgradeForeignKeys(): void
  {
    $this->db->execute("ALTER TABLE `einvoice_logs`
          DROP FOREIGN KEY `fk_einvoice_logs_id_einvoice`;");
  }
}
