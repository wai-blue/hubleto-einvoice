<?php

namespace Hubleto\App\Enterprise\Einvoice;

class Loader extends \Hubleto\Framework\App
{
  public function init(): void
  {
    parent::init();

    $this->router()->get([
      '/^einvoice\/?$/' => Controllers\Exports::class,
      '/^einvoice\/exports\/?$/' => Controllers\Exports::class,
      '/^einvoice\/exports\/add\/?$/' => ['controller' => Controllers\Exports::class, 'vars' => ['recordId' => -1]],
      '/^einvoice\/api\/debug-invoice-data\/(?P<invoiceId>\d+)\/?$/' => Controllers\Api\DebugInvoiceData::class, // only for testing and debugging purposes, delete later
    ]);
  }

  public function installTables(int $round): void
  {
    if ($round == 1) {
      $this->getModel(Models\Export::class)->dropTableIfExists()->install();
      $this->getModel(Models\Log::class)->dropTableIfExists()->install();
    }
  }
}
