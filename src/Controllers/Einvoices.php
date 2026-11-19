<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\App\External\WaiBlue\Einvoice\Services\Synchronization;
use Hubleto\Erp\Controller;

class Einvoices extends Controller
{
  private ?array $invoiceSyncResult = null;
  private ?string $invoiceSyncError = null;

  public function init(): void
  {
    parent::init();

    if ($this->router()->urlParamAsString('action') === 'sync-invoices') {
      try {
        $result = (new Synchronization($this->getEinvoiceApp()))->run(true, false);
        $this->invoiceSyncResult = $result['documents'];
        $this->invoiceSyncError = implode(' ', $result['errors']) ?: null;
      } catch (\Throwable $e) {
        $this->invoiceSyncError = $e->getMessage();
      }
    }

    if ($this->router()->urlParamAsString('action') === 'sync-statuses') {
      try {
        $result = (new Synchronization($this->getEinvoiceApp()))->run(false, true);
        $this->invoiceSyncError = implode(' ', $result['errors']) ?: null;
      } catch (\Throwable $e) {
        $this->invoiceSyncError = $e->getMessage();
      }
    }

    if ($this->router()->urlParamAsString('action') === 'download') {
      $id = $this->router()->urlParamAsInteger('id');
      if ($id > 0) {
        /** @var Einvoice */
        $mEinvoice = $this->getModel(Einvoice::class);
        $export = $mEinvoice->record->find($id);
        if ($export) {
          header('Content-Type: text/xml');
          header('Content-Disposition: attachment; filename="einvoice-' . $id . '.xml"');
          echo $export->xml_content;
          exit;
        }
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $ids = $this->router()->urlParamAsArray('ids');

      if (!empty($ids)) {
        /** @var Einvoice */
        $mEinvoice = $this->getModel(Einvoice::class);
        foreach ($ids as $id) {
          try {
            $mEinvoice->createFromInvoice((int) $id);
          } catch (\Throwable $e) {
          }
        }

        if (isset($_SERVER['HTTP_REFERER'])) {
          header('Location: ' . $_SERVER['HTTP_REFERER']);
          exit;
        }
      }
    }
  }

  public function prepareView(): void
  {
    parent::prepareView();
    $this->viewParams['invoiceSyncResult'] = $this->invoiceSyncResult;
    $this->viewParams['invoiceSyncError'] = $this->invoiceSyncError;
    $this->viewParams['lastSynchronization'] = (new Synchronization($this->getEinvoiceApp()))->lastResult();
    $this->viewParams['outboundToSend'] = $this->getModel(Einvoice::class)->countOutboundToSend();
    $this->setView('@Hubleto:App:External:WaiBlue:Einvoice/Einvoices.twig');
  }

  private function getEinvoiceApp(): Loader
  {
    return $this->appManager()->getApp(Loader::class);
  }
}
