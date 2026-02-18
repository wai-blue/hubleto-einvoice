<?php

namespace Hubleto\App\Enterprise\Einvoice\Models\RecordManagers;

use Hubleto\App\Community\Invoices\Models\RecordManagers\Invoice;
use Hubleto\App\Enterprise\Einvoice\Models\RecordManagers\Log;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Export extends \Hubleto\Erp\RecordManager
{
  public $table = 'einvoice_exports';

  /** @return BelongsTo<Invoice, covariant Export> */
  public function INVOICE(): BelongsTo
  {
    return $this->belongsTo(Invoice::class, 'id_invoice', 'id');
  }

  /** @return HasMany<Log, covariant Export> */
  public function LOGS(): HasMany
  {
    return $this->hasMany(Log::class, 'id_export', 'id');
  }

  public function prepareReadQuery(mixed $query = null, int $level = 0, array|null $includeRelations = null): mixed
  {
    $query = parent::prepareReadQuery($query, $level, $includeRelations);

    $hubleto = \Hubleto\Erp\Loader::getGlobalApp();
    $filters = $hubleto->router()->urlParamAsArray("filters");

    if (isset($filters["fStatus"]) && is_numeric($filters["fStatus"])) {
      $query = $query->where("einvoice_exports.status", $filters["fStatus"]);
    }

    return $query;
  }
}
