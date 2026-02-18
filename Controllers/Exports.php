<?php

namespace Hubleto\App\Enterprise\Einvoice\Controllers;

use Hubleto\App\Enterprise\Einvoice\Models\Export;
use Hubleto\Erp\Controller;

class Exports extends Controller
{
  public function init(): void
  {
    parent::init();

    // FOR TESTING ONLY - DELETE LATER
    if ($this->router()->urlParamAsString('action') === 'download') {
      $id = $this->router()->urlParamAsInteger('id');
      if ($id > 0) {
        /** @var Export */
        $mExport = $this->getModel(Export::class);
        $export = $mExport->record->find($id);
        if ($export) {
          header('Content-Type: text/xml');
          header('Content-Disposition: attachment; filename="export-' . $id . '.xml"');
          echo $export->xml_content;
          exit;
        }
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $ids = $this->router()->urlParamAsArray('ids');

      if (!empty($ids)) {
        /** @var Export */
        $mExport = $this->getModel(Export::class);
        foreach ($ids as $id) {
          try {
            $mExport->createFromInvoice((int) $id);
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
    $this->setView('@Hubleto:App:Enterprise:Einvoice/Exports.twig');
  }
}
