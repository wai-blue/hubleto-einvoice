<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers;

use Hubleto\App\External\WaiBlue\Einvoice\Models\RecordManagers\Einvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends \Hubleto\Erp\RecordManager
{
  public $table = 'einvoice_logs';

  /** @return BelongsTo<Einvoice, covariant Log> */
  public function EINVOICE(): BelongsTo
  {
    return $this->belongsTo(Einvoice::class, 'id_einvoice', 'id');
  }
}
