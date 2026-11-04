<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers\Api;

use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\Erp\Controllers\ApiController;

class SendEinvoice extends ApiController
{
  public function response(): array
  {
    $einvoiceId = (int) (
      $this->router()->urlParamAsInteger('einvoiceId')
      ?? $this->router()->urlParamAsInteger('exportId')
      ?? 0
    );
    if ($einvoiceId <= 0) {
      throw new \Exception('Invalid e-invoice ID');
    }

    /** @var Einvoice $mEinvoice */
    $mEinvoice = $this->getModel(Einvoice::class);
    $result = $mEinvoice->sendToProvider($einvoiceId);

    return [
      'status' => 'success',
      'message' => 'e-Invoice sent to provider.',
      'result' => $result,
    ];
  }
}
