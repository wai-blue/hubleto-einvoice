<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers\Api;

use Hubleto\App\External\WaiBlue\Einvoice\InboundInvoiceImporter;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\Erp\Controllers\ApiController;

class CreateInvoice extends ApiController
{
  public function response(): array
  {
    $einvoiceId = (int) $this->router()->urlParamAsInteger('einvoiceId');
    if ($einvoiceId <= 0) {
      throw new \Exception('Invalid e-invoice ID');
    }

    /** @var Loader $app */
    $app = $this->appManager()->getApp(Loader::class);
    $result = (new InboundInvoiceImporter($app))->import($einvoiceId);

    return [
      'status' => 'success',
      'message' => $result['created']
        ? 'Inbound Hubleto invoice created.'
        : 'The inbound Hubleto invoice already exists.',
      'result' => $result,
    ];
  }
}
