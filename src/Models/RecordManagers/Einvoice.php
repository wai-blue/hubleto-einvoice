<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers;

use Hubleto\App\Community\Invoices\Models\RecordManagers\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice as EinvoiceModel;
use Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers\Log;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Einvoice extends \Hubleto\Erp\RecordManager
{
  public $table = 'einvoices';

  public function recordSave(array $record, int $idMasterRecord = 0, array $saveRelations = [], string $relation = ''): array
  {
    return $this->getConnection()->transaction(function () use ($record): array {
      $original = $this->where('einvoices.id', (int) ($record['id'] ?? 0))->lockForUpdate()->first();
      if (!$original || !$this->model->getPermissions($original->toArray())[2]) {
        throw new \Hubleto\Framework\Exceptions\NotEnoughPermissionsException('Cannot change this e-Invoice.');
      }
      $status = $record['status'] ?? null;
      $allowed = [
        (string) $original->status,
        (string) EinvoiceModel::STATUS_GENERATED,
        (string) EinvoiceModel::STATUS_WONT_SEND,
      ];
      if ((!is_int($status) && !is_string($status)) || !in_array((string) $status, $allowed, true)) {
        throw new \Exception('Only the sending preference can be changed manually.');
      }
      $original->update(['status' => (int) $status]);
      return $original->toArray();
    });
  }

  /** @return BelongsTo<Invoice, covariant Einvoice> */
  public function INVOICE(): BelongsTo
  {
    return $this->belongsTo(Invoice::class, 'id_invoice', 'id');
  }

  /** @return HasMany<Log, covariant Einvoice> */
  public function LOGS(): HasMany
  {
    return $this->hasMany(Log::class, 'id_einvoice', 'id');
  }

  public function prepareReadQuery(mixed $query = null, int $level = 0, array|null $includeRelations = null): mixed
  {
    $query = parent::prepareReadQuery($query, $level, $includeRelations);

    $hubleto = \Hubleto\Erp\Loader::getGlobalApp();
    $filters = $hubleto->router()->urlParamAsArray("filters");

    if (isset($filters["fStatus"]) && is_numeric($filters["fStatus"])) {
      $query = $query->where("einvoices.status", $filters["fStatus"]);
    }
    if (isset($filters["fInboundOutbound"]) && $filters["fInboundOutbound"] > 0) {
      $query = $query->where("einvoices.inbound_outbound", $filters["fInboundOutbound"]);
    }

    return $query;
  }
}
