<?php

namespace Hubleto\App\Enterprise\Einvoice\Models;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\Framework\Db\Column\DateTime;
use Hubleto\Framework\Db\Column\Integer;
use Hubleto\Framework\Db\Column\Lookup;
use Hubleto\Framework\Db\Column\Text;
use Hubleto\Framework\Db\Column\Varchar;
use Hubleto\App\Enterprise\Einvoice\XmlGenerator;

class Export extends \Hubleto\Erp\Model
{
  const STATUS_PENDING = 0;
  const STATUS_GENERATED = 1;
  const STATUS_SENT = 2;
  const STATUS_CONFIRMED = 3;
  const STATUS_FAILED = 4;

  public string $table = 'einvoice_exports';
  public string $recordManagerClass = RecordManagers\Export::class;
  public ?string $lookupSqlValue = '{%TABLE%}.id';
  public ?string $lookupUrlAdd = 'einvoice/exports/add';
  public ?string $lookupUrlDetail = 'einvoice/exports/{%ID%}';

  public array $relations = [
    'INVOICE' => [self::BELONGS_TO, Invoice::class, 'id_invoice'],
    'LOGS' => [self::HAS_MANY, Log::class, 'id_export'],
  ];

  public function describeColumns(): array
  {
    return array_merge(parent::describeColumns(), [
      'id_invoice' => (new Lookup($this, $this->translate('Invoice'), Invoice::class))->setRequired()->setDefaultVisible(),
      'xml_content' => (new Text($this, $this->translate('XML Content'))),
      'status' => (new Integer($this, $this->translate('Status')))->setEnumValues([
        self::STATUS_PENDING => $this->translate('Pending'),
        self::STATUS_GENERATED => $this->translate('Generated'),
        self::STATUS_SENT => $this->translate('Sent'),
        self::STATUS_CONFIRMED => $this->translate('Confirmed'),
        self::STATUS_FAILED => $this->translate('Failed'),
      ])->setDefaultValue(self::STATUS_PENDING)->setDefaultVisible(),
      'transmission_id' => (new Varchar($this, $this->translate('Transmission ID')))->setDefaultVisible(),
      'date_generated' => (new DateTime($this, $this->translate('Date generated'))),
      'date_sent' => (new DateTime($this, $this->translate('Date sent'))),
      'date_confirmed' => (new DateTime($this, $this->translate('Date confirmed'))),
      'peppol_response' => (new Text($this, $this->translate('Peppol Response'))),
      'error_message' => (new Text($this, $this->translate('Error message'))),
    ]);
  }

  public function describeTable(): \Hubleto\Framework\Description\Table
  {
    $description = parent::describeTable();
    $description->show(['header', 'fulltextSearch', 'columnSearch', 'moreActionsButton']);
    $description->show(['footer']);

    $description->addFilter('fStatus', [
      'title' => $this->translate('Status'),
      'options' => [
        self::STATUS_PENDING => $this->translate('Pending'),
        self::STATUS_GENERATED => $this->translate('Generated'),
        self::STATUS_SENT => $this->translate('Sent'),
        self::STATUS_CONFIRMED => $this->translate('Confirmed'),
        self::STATUS_FAILED => $this->translate('Failed'),
      ]
    ]);

    return $description;
  }

  public function onBeforeCreate(array $record): array
  {
    if (empty($record['xml_content']) && !empty($record['id_invoice'])) {
      /** @var Invoice */
      $mInvoice = $this->getModel(Invoice::class);
      $invoiceId = (int) $record['id_invoice'];

      $invoice = $mInvoice->record->find($invoiceId);
      if (!$invoice) {
        throw new \Exception($this->translate('Invoice not found'));
      }

      $invoiceData = $mInvoice->getPreviewVars($invoiceId);
      if (isset($invoiceData['hubleto'])) {
        unset($invoiceData['hubleto']);
      }

      if (isset($invoiceData['inbound_outbound']) && $invoiceData['inbound_outbound'] != Invoice::OUTBOUND_INVOICE) {
        throw new \Exception($this->translate('Only outbound invoices can be exported'));
      }

      $generator = new XmlGenerator();
      $record['xml_content'] = $generator->generate($invoiceData);
      $record['status'] = self::STATUS_GENERATED;
      $record['date_generated'] = date('Y-m-d H:i:s');
    }

    return $record;
  }

  public function onAfterCreate(array $savedRecord): array
  {
    // Save Log
    /** @var Log */
    $mLog = $this->getModel(Log::class);
    $mLog->record->recordCreate([
      'id_export' => $savedRecord['id'],
      'action_type' => 'xml_generated',
      'status' => Log::STATUS_SUCCESS,
      'message' => 'XML generated successfully',
      'timestamp' => date('Y-m-d H:i:s'),
    ]);

    return $savedRecord;
  }

  public function createFromInvoice(int $invoiceId): int
  {
    $exportId = $this->record->recordCreate([
      'id_invoice' => $invoiceId,
    ])['id'];

    return (int) $exportId;
  }
}
