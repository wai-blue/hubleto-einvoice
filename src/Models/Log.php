<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models;

use Hubleto\Framework\Db\Column\DateTime;
use Hubleto\Framework\Db\Column\Integer;
use Hubleto\Framework\Db\Column\Lookup;
use Hubleto\Framework\Db\Column\Text;
use Hubleto\Framework\Db\Column\Varchar;

class Log extends \Hubleto\Erp\Model
{
  const STATUS_INFO = 0;
  const STATUS_SUCCESS = 1;
  const STATUS_ERROR = 2;

  public string $table = 'einvoice_logs';
  public string $recordManagerClass = RecordManagers\Log::class;

  public array $relations = [
    'EINVOICE' => [self::BELONGS_TO, Einvoice::class, 'id_einvoice'],
  ];

  public function describeColumns(): array
  {
    return array_merge(parent::describeColumns(), [
      'id_einvoice' => (new Lookup($this, $this->translate('e-Invoice'), Einvoice::class))->setRequired()->setDefaultVisible(),
      'action_type' => (new Varchar($this, $this->translate('Action Type')))->setDefaultVisible(),
      'status' => (new Integer($this, $this->translate('Status')))->setEnumValues([
        self::STATUS_INFO => $this->translate('Info'),
        self::STATUS_SUCCESS => $this->translate('Success'),
        self::STATUS_ERROR => $this->translate('Error'),
      ])->setDefaultVisible(),
      'message' => (new Text($this, $this->translate('Message')))->setDefaultVisible(),
      'timestamp' => (new DateTime($this, $this->translate('Timestamp')))->setDefaultVisible(),
    ]);
  }

  public function describeTable(): \Hubleto\Framework\Description\Table
  {
    $description = parent::describeTable();
    $description->show(['header', 'fulltextSearch', 'columnSearch', 'moreActionsButton']);
    $description->show(['footer']);

    return $description;
  }
}
