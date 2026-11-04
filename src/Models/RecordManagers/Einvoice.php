<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers;

use Hubleto\App\Community\Invoices\Models\RecordManagers\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers\Log;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Einvoice extends \Hubleto\Erp\RecordManager
{
  public $table = 'einvoices';

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
