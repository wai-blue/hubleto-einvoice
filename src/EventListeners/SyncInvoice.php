<?php declare(strict_types=1);

namespace Hubleto\App\External\WaiBlue\Einvoice\EventListeners;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\Framework\Interfaces\EventListenerInterface;
use Hubleto\Framework\Interfaces\ModelInterface;

class SyncInvoice extends \Hubleto\Framework\EventListener implements EventListenerInterface
{
  public function onModelAfterCreate(ModelInterface $model, array $record): void
  {
    $this->sync($model, $record);
  }

  public function onModelAfterUpdate(ModelInterface $model, array $originalRecord, array $savedRecord): void
  {
    $this->sync($model, $savedRecord);
  }

  private function sync(ModelInterface $model, array $record): void
  {
    if (get_class($model) !== Invoice::class || empty($record['id'])) {
      return;
    }

    /** @var Einvoice $mEinvoice */
    $mEinvoice = $this->getModel(Einvoice::class);
    $mEinvoice->syncFromInvoice((int) $record['id']);
  }
}
