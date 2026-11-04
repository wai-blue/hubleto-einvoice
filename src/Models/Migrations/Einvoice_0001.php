<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\Migrations;

use Hubleto\Framework\Migration;

class Einvoice_0001 extends Migration
{

  public function upgradeSchema(): void
  {
    $this->db->execute("set foreign_key_checks = 0;
drop table if exists `einvoices`;
set foreign_key_checks = 1;");
    $this->db->execute("SET foreign_key_checks = 0;
drop table if exists `einvoices`;
create table `einvoices` (
 `id` int(8) primary key auto_increment,
 `id_invoice` int(8) NULL default NULL,
 `xml_content` text ,
 `status` int(255) ,
 `transmission_id` varchar(255) ,
 `date_generated` datetime ,
 `date_sent` datetime ,
 `date_confirmed` datetime ,
 `peppol_response` text ,
 `error_message` text ,
 index `id` (`id`),
 index `id_invoice` (`id_invoice`),
 index `status` (`status`),
 index `date_generated` (`date_generated`),
 index `date_sent` (`date_sent`),
 index `date_confirmed` (`date_confirmed`)) ENGINE = InnoDB;
SET foreign_key_checks = 1;");
  }

  public function downgradeSchema(): void
  {
    $this->db->execute("set foreign_key_checks = 0;
drop table if exists `einvoices`;
set foreign_key_checks = 1;");
  }

  public function upgradeForeignKeys(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
          ADD CONSTRAINT `fk_7e2240a65a6131e662f0f8326d0fbfc5`
          FOREIGN KEY (`id_invoice`)
          REFERENCES `invoices` (`id`)
          ON DELETE RESTRICT
          ON UPDATE RESTRICT;");
  }

  public function downgradeForeignKeys(): void
  {
    $this->db->execute("ALTER TABLE `einvoices`
          DROP FOREIGN KEY `fk_7e2240a65a6131e662f0f8326d0fbfc5`;");
  }
}
