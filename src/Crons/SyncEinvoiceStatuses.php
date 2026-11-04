<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Crons;

use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;

class SyncEinvoiceStatuses extends \Hubleto\Erp\Cron
{
  public string $schedulingPattern = '0 * * * *';

  public function run(): void
  {
    /** @var Einvoice $mEinvoice */
    $mEinvoice = $this->getModel(Einvoice::class);

    $einvoicesToSync = $mEinvoice->record
      ->where('status', Einvoice::STATUS_SENT)
      ->whereNotNull('transmission_id')
      ->get()
    ;

    $this->logger()->info('SyncEinvoiceStatuses: syncing ' . $einvoicesToSync->count() . ' sent e-invoices.');

    foreach ($einvoicesToSync as $einvoice) {
      try {
        $mEinvoice->syncProviderStatus((int) $einvoice->id);
      } catch (\Throwable $e) {
        $this->logger()->error('SyncEinvoiceStatuses: failed for e-invoice #' . $einvoice->id . ': ' . $e->getMessage());
      }
    }
  }
}
