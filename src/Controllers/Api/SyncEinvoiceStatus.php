<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers\Api;

use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\Erp\Controllers\ApiController;

class SyncEinvoiceStatus extends ApiController
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
    $result = $mEinvoice->syncProviderStatus($einvoiceId);
    $this->appManager()
      ->getApp(\Hubleto\App\External\WaiBlue\Einvoice\Loader::class)
      ->providers()
      ->saveProviderConfigValue('lastStatusSyncAt', date('Y-m-d H:i:s'));

    return [
      'status' => 'success',
      'message' => 'e-Invoice status synchronized.',
      'result' => $result,
    ];
  }
}
