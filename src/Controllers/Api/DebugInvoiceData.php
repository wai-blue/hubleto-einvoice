<?php
// only for testing and debugging purposes, delete later
namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers\Api;

use Hubleto\App\Community\Documents\Generator;
use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\Erp\Controllers\ApiController;

class DebugInvoiceData extends ApiController
{
  public function response(): array
  {
    $invoiceId = (int) ($this->router()->urlParamAsInteger('invoiceId') ?? 0);
    if ($invoiceId <= 0) {
      throw new \Exception('Invalid invoice ID');
    }

    /** @var Invoice */
    $mInvoice = $this->getModel(Invoice::class);

    $invoice = $mInvoice->record->find($invoiceId);
    if (!$invoice) {
      throw new \Exception('Invoice not found');
    }

    /** @var Generator $generator */
    $generator = $this->getService(Generator::class);
    $invoiceData = $generator->getPreviewVars(Invoice::class, $invoiceId);
    if (isset($invoiceData['hubleto'])) {
      unset($invoiceData['hubleto']);
    }

    return [
      'status' => 'success',
      'invoiceId' => $invoiceId,
      'rawData' => $invoiceData,
    ];
  }
}
