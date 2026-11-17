<?php

namespace Hubleto\App\External\WaiBlue\Einvoice;

class Loader extends \Hubleto\Erp\App
{
  private ?Providers $providers = null;

  public function providers(): Providers
  {
    return $this->providers ??= new Providers($this);
  }

  public function init(): void
  {
    parent::init();

    $this->router()->get([
      '/^einvoice(\/(?<recordId>\d+))?\/?$/' => Controllers\Einvoices::class,
      '/^einvoice\/add\/?$/' => ['controller' => Controllers\Einvoices::class, 'vars' => ['recordId' => -1]],
      '/^settings\/einvoice\/?$/' => Controllers\Settings::class,
      '/^einvoice\/api\/einvoices\/(?P<einvoiceId>\d+)\/send\/?$/' => Controllers\Api\SendEinvoice::class,
      '/^einvoice\/api\/einvoices\/(?P<einvoiceId>\d+)\/sync-status\/?$/' => Controllers\Api\SyncEinvoiceStatus::class,
      '/^einvoice\/api\/einvoices\/(?P<einvoiceId>\d+)\/create-invoice\/?$/' => Controllers\Api\CreateInvoice::class,
    ]);

    $this->cronManager()->addCron(Crons\SyncEinvoiceStatuses::class);

    $syncInvoiceListener = $this->getService(EventListeners\SyncInvoice::class);
    $this->eventManager()->addEventListener('onModelAfterCreate', $syncInvoiceListener);
    $this->eventManager()->addEventListener('onModelAfterUpdate', $syncInvoiceListener);

    $settingsApp = $this->appManager()->getApp(\Hubleto\App\Community\Settings\Loader::class);
    $settingsApp->addSetting($this, [
      'title' => $this->translate('e-Invoice'),
      'icon' => 'fas fa-file-invoice',
      'url' => 'settings/einvoice',
    ]);
  }

  public function installApp(int $round): void
  {
    if ($round == 1) {
      $this->getModel(Models\Einvoice::class)->upgradeSchema();
      $this->getModel(Models\Log::class)->upgradeSchema();
    }
  }

  public function getSidebarBadgeNumber(): int
  {
    return
      $this->countEinvoicesByStatus(Models\Einvoice::STATUS_GENERATED)
      + $this->countEinvoicesByStatus(Models\Einvoice::STATUS_FAILED)
    ;
  }

  public function renderPriorityNotifications(): string
  {
    $notifications = '';
    $statuses = [
      Models\Einvoice::STATUS_GENERATED => $this->translate('Waiting to be sent'),
      Models\Einvoice::STATUS_FAILED => $this->translate('Failed'),
    ];

    foreach ($statuses as $status => $label) {
      $count = $this->countEinvoicesByStatus($status);
      if ($count > 0) {
        $notifications .= '
          <a
            href="' . $this->env()->projectUrl . '/einvoice?filters%5BfStatus%5D=' . $status . '"
            class="block badge badge-danger"
          >' . $label . ': ' . $count . '</a>
        ';
      }
    }

    return $notifications;
  }

  public function renderSecondSidebar(): string
  {
    $sync = (new Services\Synchronization($this))->lastResult();
    $lastInvoiceSyncAt = $this->formatSyncTimestamp($sync['lastDocumentSyncAt'] ?? $this->providers()->providerConfigAsString('lastInvoiceSyncAt'));
    $lastStatusSyncAt = $this->formatSyncTimestamp($sync['lastStatusSyncAt'] ?? $this->providers()->providerConfigAsString('lastStatusSyncAt'));

    return '
      ' . $this->secondSidebarTitle() . '
      <div class="app-sidebar-buttons">
        ' . $this->secondSidebarButton('einvoice?action=sync-invoices', 'fas fa-cloud-arrow-down', 'Sync from provider') . '
        ' . ($lastInvoiceSyncAt !== '' ? '<div class="text-xs text-gray-500 px-2">' . $this->translate('Last sync') . ': ' . $lastInvoiceSyncAt . '</div>' : '') . '
        ' . $this->secondSidebarButton('einvoice?action=sync-statuses', 'fas fa-rotate', 'Sync statuses now') . '
        ' . ($lastStatusSyncAt !== '' ? '<div class="text-xs text-gray-500 px-2">' . $this->translate('Last sync') . ': ' . $lastStatusSyncAt . '</div>' : '') . '
        <br/>
        ' . $this->secondSidebarButton('settings/einvoice', 'fas fa-gear', 'Providers') . '
      </div>
    ';
  }

  private function countEinvoicesByStatus(int $status): int
  {
    /** @var Models\Einvoice $mEinvoice */
    $mEinvoice = $this->getModel(Models\Einvoice::class);

    return $mEinvoice->record->where('einvoices.status', $status)->count();
  }

  private function formatSyncTimestamp(string $timestamp): string
  {
    $unixTimestamp = strtotime($timestamp);
    return $unixTimestamp === false ? '' : date('d.m.Y H:i', $unixTimestamp);
  }
}
