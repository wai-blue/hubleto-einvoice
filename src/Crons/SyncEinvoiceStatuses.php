<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Crons;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Services\Synchronization;

class SyncEinvoiceStatuses extends \Hubleto\Erp\Cron
{
  public string $schedulingPattern = '0 * * * *';

  public function run(): void
  {
    try {
      $result = (new Synchronization($this->appManager()->getApp(Loader::class)))->run(true, true, 'cron');
      $this->logger()->info('e-Invoice synchronization: ' . $result['status']);
    } catch (\Throwable $e) {
      $this->logger()->error('e-Invoice synchronization: ' . $e->getMessage());
    }
  }
}
