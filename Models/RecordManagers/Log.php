<?php

namespace Hubleto\App\Enterprise\Einvoice\Models\RecordManagers;

use Hubleto\App\Enterprise\Einvoice\Models\RecordManagers\Export;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends \Hubleto\Erp\RecordManager
{
  public $table = 'einvoice_logs';

  /** @return BelongsTo<Export, covariant Log> */
  public function EXPORT(): BelongsTo
  {
    return $this->belongsTo(Export::class, 'id_export', 'id');
  }
}
