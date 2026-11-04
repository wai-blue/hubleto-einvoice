<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers;

use Hubleto\App\External\WaiBlue\Einvoice\Crons\SyncEinvoiceStatuses;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\Erp\Controller;

class Einvoices extends Controller
{
  private ?array $invoiceSyncResult = null;
  private ?string $invoiceSyncError = null;

  public function init(): void
  {
    parent::init();

    if ($this->router()->urlParamAsString('action') === 'sync-invoices') {
      /** @var Einvoice $mEinvoice */
      $mEinvoice = $this->getModel(Einvoice::class);
      $app = $this->getEinvoiceApp();
      $position = json_decode($app->providers()->providerConfigAsString('invoiceSyncPosition'), true);

      try {
        $this->invoiceSyncResult = $mEinvoice->syncProviderDocuments(
          100,
          is_array($position) ? $position : [],
        );
        $app->providers()->saveProviderConfigValue(
          'invoiceSyncPosition',
          json_encode($this->invoiceSyncResult['nextPosition'], JSON_UNESCAPED_SLASHES) ?: '',
        );
        $app->providers()->saveProviderConfigValue('lastInvoiceSyncAt', date('Y-m-d H:i:s'));
      } catch (\Throwable $e) {
        $this->invoiceSyncError = $e->getMessage();
      }
    }

    // FOR TESTING ONLY - DELETE LATER
    if ($this->router()->urlParamAsString('action') === 'sync-statuses') {
      /** @var SyncEinvoiceStatuses $syncCron */
      $syncCron = $this->getService(SyncEinvoiceStatuses::class);
      $syncCron->run();
      $this->getEinvoiceApp()->providers()->saveProviderConfigValue('lastStatusSyncAt', date('Y-m-d H:i:s'));

      header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? ($this->env()->projectUrl . '/einvoice')));
      exit;
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
    $this->setView('@Hubleto:App:External:WaiBlue:Einvoice/Einvoices.twig');
  }

  private function getEinvoiceApp(): \Hubleto\App\External\WaiBlue\Einvoice\Loader
  {
    return $this->appManager()->getApp(\Hubleto\App\External\WaiBlue\Einvoice\Loader::class);
  }
}
