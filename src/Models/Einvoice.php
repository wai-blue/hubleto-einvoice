<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Models;

use Hubleto\App\Community\Documents\Generator;
use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\InboundInvoiceImporter;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Providers\ProviderInterface;
use Hubleto\App\External\WaiBlue\Einvoice\Services\XmlGenerator;
use Hubleto\Erp\EmailProvider;
use Hubleto\Framework\Db\Column\DateTime;
use Hubleto\Framework\Db\Column\Integer;
use Hubleto\Framework\Db\Column\Lookup;
use Hubleto\Framework\Db\Column\Text;
use Hubleto\Framework\Db\Column\Varchar;
use Hubleto\Framework\Enums\InstalledMigrationEnum;

class Einvoice extends \Hubleto\Erp\Model
{
  const INBOUND = 1;
  const OUTBOUND = 2;

  const STATUS_PENDING = 0;
  const STATUS_GENERATED = 1;
  const STATUS_SENT = 2;
  const STATUS_CONFIRMED = 3;
  const STATUS_FAILED = 4;
  const STATUS_WONT_SEND = 5;

  public string $table = 'einvoices';
  public string $recordManagerClass = RecordManagers\Einvoice::class;
  public ?string $lookupSqlValue = '{%TABLE%}.id';
  public ?string $lookupUrlAdd = 'einvoice/add';
  public ?string $lookupUrlDetail = 'einvoice/{%ID%}';

  public array $relations = [
    'INVOICE' => [self::BELONGS_TO, Invoice::class, 'id_invoice'],
    'LOGS' => [self::HAS_MANY, Log::class, 'id_einvoice'],
  ];

  public function describeColumns(): array
  {
    return array_merge(parent::describeColumns(), [
      'id_invoice' => (new Lookup($this, $this->translate('Invoice'), Invoice::class))->setDefaultVisible(),
      'inbound_outbound' => (new Integer($this, $this->translate('Inbound / outbound')))->setEnumValues([
        self::INBOUND => $this->translate('Inbound'),
        self::OUTBOUND => $this->translate('Outbound'),
      ])->setEnumCssClasses([
        self::INBOUND => 'badge-success',
        self::OUTBOUND => 'badge-info',
      ])->setDefaultValue(self::OUTBOUND)->setDefaultVisible(),
      'provider' => (new Varchar($this, $this->translate('Provider')))->setDefaultVisible(),
      'invoice_number' => (new Varchar($this, $this->translate('Invoice number')))->setDefaultVisible(),
      'xml_content' => (new Text($this, $this->translate('XML Content'))),
      'status' => (new Integer($this, $this->translate('Status')))->setEnumValues([
        self::STATUS_PENDING => $this->translate('Pending'),
        self::STATUS_GENERATED => $this->translate('Waiting to be sent'),
        self::STATUS_SENT => $this->translate('Sent'),
        self::STATUS_CONFIRMED => $this->translate('Confirmed'),
        self::STATUS_FAILED => $this->translate('Failed'),
        self::STATUS_WONT_SEND => $this->translate('Will not be sent'),
      ])->setEnumCssClasses([
        self::STATUS_PENDING => '',
        self::STATUS_GENERATED => 'badge-warning',
        self::STATUS_SENT => 'badge-info',
        self::STATUS_CONFIRMED => 'badge-success',
        self::STATUS_FAILED => 'badge-danger',
        self::STATUS_WONT_SEND => '',
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
    $description->setPermissions(false, null, false, false);
    $description->ui['addButtonText'] = $this->translate('Add e-invoice');
    $description->show(['header', 'fulltextSearch', 'columnSearch', 'moreActionsButton']);
    $description->show(['footer']);

    $description->addFilter('fStatus', [
      'title' => $this->translate('Status'),
      'options' => [
        self::STATUS_PENDING => $this->translate('Pending'),
        self::STATUS_GENERATED => $this->translate('Waiting to be sent'),
        self::STATUS_SENT => $this->translate('Sent'),
        self::STATUS_CONFIRMED => $this->translate('Confirmed'),
        self::STATUS_FAILED => $this->translate('Failed'),
        self::STATUS_WONT_SEND => $this->translate('Will not be sent'),
      ]
    ]);

    $description->addFilter('fInboundOutbound', [
      'direction' => 'horizontal',
      'options' => [
        self::INBOUND => $this->translate('Inbound'),
        self::OUTBOUND => $this->translate('Outbound'),
      ],
    ]);

    return $description;
  }

  public function describeForm(): \Hubleto\Framework\Description\Form
  {
    $description = parent::describeForm();
    $description->ui['readonly'] = true;
    $description->ui['showSaveButton'] = false;
    $description->ui['showCopyButton'] = false;
    $description->ui['showDeleteButton'] = false;
    foreach (['canCreate', 'canUpdate', 'canDelete'] as $permission) {
      $description->permissions[$permission] = false;
    }
    return $description;
  }

  public function getPermissions(array $record): array
  {
    $permissions = parent::getPermissions($record);
    return [false, $permissions[1], false, false];
  }

  public function onBeforeCreate(array $record): array
  {
    $record['inbound_outbound'] = (int) ($record['inbound_outbound'] ?? self::OUTBOUND);

    if (empty($record['xml_content']) && !empty($record['id_invoice'])) {
      /** @var Invoice */
      $mInvoice = $this->getModel(Invoice::class);
      $invoiceId = (int) $record['id_invoice'];

      $invoice = $mInvoice->record->find($invoiceId);
      if (!$invoice) {
        throw new \Exception($this->translate('Invoice not found'));
      }

      /** @var Generator $generator */
      $generator = $this->getService(Generator::class);
      $invoiceData = $generator->getPreviewVars(Invoice::class, $invoiceId);
      if (isset($invoiceData['hubleto'])) {
        unset($invoiceData['hubleto']);
      }

      if (isset($invoiceData['inbound_outbound']) && $invoiceData['inbound_outbound'] != Invoice::OUTBOUND_INVOICE) {
        throw new \Exception($this->translate('Only outbound invoices can be sent as e-invoices'));
      }

      $generator = new XmlGenerator();
      $record['xml_content'] = $generator->generate($invoiceData);
      $record['status'] = self::STATUS_GENERATED;
      $record['date_generated'] = date('Y-m-d H:i:s');
      $record['invoice_number'] = (string) ($invoice->number ?? '');
      $record['provider'] = $this->getProvider()->getCode();
    }

    return parent::onBeforeCreate($record);
  }

  public function getPendingMigrations(InstalledMigrationEnum $configKey): array
  {
    $configName = $configKey->toString();
    $newPath = 'models/' . $this->fullName . '/' . $configName;
    if ($this->config()->getAsInteger($newPath, -1) === -1) {
      $legacyPath = 'models/' . __NAMESPACE__ . '\\Export/' . $configName;
      $legacyVersion = $this->config()->getAsInteger($legacyPath, -1);
      if ($legacyVersion >= 0) {
        $this->config()->save($newPath, $legacyVersion);
      }
    }

    return parent::getPendingMigrations($configKey);
  }

  public function onAfterCreate(array $savedRecord): array
  {
    // Save Log
    /** @var Log */
    $mLog = $this->getModel(Log::class);
    $imported = !empty($savedRecord['transmission_id']);
    $mLog->record->recordCreate([
      'id_einvoice' => $savedRecord['id'],
      'action_type' => $imported ? 'provider_import' : 'xml_generated',
      'status' => $imported ? Log::STATUS_INFO : Log::STATUS_SUCCESS,
      'message' => $imported ? 'e-Invoice imported from provider' : 'XML generated successfully',
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

  public function syncFromInvoice(int $invoiceId): ?int
  {
    /** @var Invoice $mInvoice */
    $mInvoice = $this->getModel(Invoice::class);
    $invoice = $mInvoice->record->find($invoiceId);
    if (!$invoice || (int) $invoice->inbound_outbound !== Invoice::OUTBOUND_INVOICE) {
      return null;
    }

    $exports = $this->record->where('id_invoice', $invoiceId)->get();
    if ($exports->isEmpty()) {
      return $this->createFromInvoice($invoiceId);
    }

    $invoiceData = $this->loadInvoiceDataForExport($invoiceId);
    $xmlContent = (new XmlGenerator())->generate($invoiceData);
    $providerCode = $this->getProvider()->getCode();
    $invoiceNumber = (string) ($invoice->number ?? '');

    foreach ($exports as $export) {
      if ($this->isRemotelyTracked($export)) {
        continue;
      }

      if (
        (string) $export->xml_content === $xmlContent
        && (string) $export->invoice_number === $invoiceNumber
        && (string) $export->provider === $providerCode
      ) {
        continue;
      }

      $export->update([
        'xml_content' => $xmlContent,
        'status' => (int) $export->status === self::STATUS_WONT_SEND
          ? self::STATUS_WONT_SEND
          : self::STATUS_GENERATED,
        'date_generated' => date('Y-m-d H:i:s'),
        'provider' => $providerCode,
        'invoice_number' => $invoiceNumber,
        'error_message' => null,
      ]);
    }

    return (int) $exports->first()->id;
  }

  public function onBeforeDelete(int $id): int
  {
    throw new \Hubleto\Framework\Exceptions\RecordSaveException(
      $this->translate('e-Invoices are read-only and cannot be deleted. Keep the record for tracking.')
    );
  }

  public function sendToProvider(int $exportId): array
  {
    $export = $this->record->find($exportId);
    if (!$export) {
      throw new \Exception($this->translate('e-Invoice not found'));
    }
    if (empty($export->xml_content)) {
      throw new \Exception($this->translate('XML content is missing'));
    }
    if ($this->isRemotelyTracked($export)) {
      throw new \Exception('This e-invoice was already sent. Use Sync remote status instead of sending it again.');
    }
    if ((int) $export->status === self::STATUS_WONT_SEND) {
      throw new \Exception('This e-invoice is marked as Will not be sent.');
    }

    $invoiceData = $this->loadInvoiceDataForExport((int) $export->id_invoice);
    $xmlContent = (new XmlGenerator())->generate($invoiceData);
    if ($xmlContent === '') {
      throw new \Exception('Failed to generate UBL XML for sending.');
    }

    if ($xmlContent !== (string) $export->xml_content) {
      $this->record->find($exportId)?->update([
        'xml_content' => $xmlContent,
        'date_generated' => date('Y-m-d H:i:s'),
      ]);
    }

    $provider = $this->getProvider();
    $preflight = $provider->preflight($invoiceData, $xmlContent);

    $this->appendResponsePayload((int) $export->id, 'preflight', $preflight);
    $this->createLogRecord(
      (int) $export->id,
      'preflight',
      $provider->canSend($preflight) ? Log::STATUS_SUCCESS : Log::STATUS_ERROR,
      $provider->describePreflight($preflight)
    );

    if (!$provider->canSend($preflight)) {
      $message = $provider->describePreflight($preflight);
      $this->record->find($exportId)?->update([
        'status' => self::STATUS_FAILED,
        'error_message' => $message,
      ]);
      throw new \Exception($message);
    }

    try {
      $providerResult = $provider->sendDocument($invoiceData, $xmlContent);
    } catch (\Throwable $e) {
      $this->record->find($exportId)?->update([
        'status' => self::STATUS_FAILED,
        'error_message' => $e->getMessage(),
      ]);

      $this->createLogRecord(
        $exportId,
        'send',
        Log::STATUS_ERROR,
        $e->getMessage()
      );

      throw $e;
    }

    $sendResponse = $providerResult['send'] ?? [];
    $transmissionId = (string) ($providerResult['transmissionId'] ?? '');
    $mappedStatus = (int) ($providerResult['localStatus'] ?? self::STATUS_SENT);
    $isFailure = $mappedStatus === self::STATUS_FAILED;
    $statusMessage = (string) ($providerResult['statusMessage'] ?? 'Remote send finished.');

    $this->record->find($exportId)?->update([
      'status' => $mappedStatus,
      'provider' => $provider->getCode(),
      'invoice_number' => (string) ($invoiceData['number'] ?? $export->invoice_number ?? ''),
      'transmission_id' => $transmissionId,
      'date_sent' => !$isFailure ? date('Y-m-d H:i:s') : null,
      'peppol_response' => $this->mergeResponsePayload(
        (string) ($this->record->find($exportId)?->peppol_response ?? ''),
        ['send' => $sendResponse]
      ),
      'error_message' => $isFailure ? $statusMessage : null,
    ]);

    $this->createLogRecord(
      $exportId,
      'send',
      $isFailure ? Log::STATUS_ERROR : Log::STATUS_SUCCESS,
      $statusMessage
    );

    if ($isFailure) {
      throw new \Exception($statusMessage);
    }

    return [
      'receiverPeppolId' => $providerResult['receiverPeppolId'] ?? null,
      'preflight' => $preflight,
      'send' => $sendResponse,
    ];
  }

  public function syncProviderStatus(int $exportId): array
  {
    $export = $this->record->find($exportId);
    if (!$export) {
      throw new \Exception($this->translate('e-Invoice not found'));
    }

    $documentId = trim((string) ($export->transmission_id ?? ''));
    if ($documentId === '') {
      throw new \Exception($this->translate('Transmission ID is missing. Send the e-invoice first.'));
    }

    $provider = $this->getProvider((string) ($export->provider ?? ''));
    $providerResult = $provider->syncDocumentStatus($documentId);
    $statusResponse = $providerResult['status'] ?? [];
    $mappedStatus = (int) ($providerResult['localStatus'] ?? self::STATUS_SENT);

    $updates = [
      'status' => $mappedStatus,
      'peppol_response' => $this->mergeResponsePayload($export->peppol_response ?? '', ['status' => $statusResponse]),
      'error_message' => $providerResult['errorMessage'] ?? null,
    ];

    if ($mappedStatus === self::STATUS_CONFIRMED && empty($export->date_confirmed)) {
      $updates['date_confirmed'] = date('Y-m-d H:i:s');
    }

    $this->record->find($exportId)?->update($updates);
    $this->createLogRecord(
      $exportId,
      'status_sync',
      $mappedStatus === self::STATUS_FAILED ? Log::STATUS_ERROR : Log::STATUS_INFO,
      (string) ($providerResult['logMessage'] ?? 'Remote status synchronized')
    );

    return $statusResponse;
  }

  public function syncProviderDocuments(int $batchLimit = 100, array $position = []): array
  {
    $provider = $this->getProvider();
    $batchLimit = max(1, min($batchLimit, 100));
    $summary = [
      'found' => 0,
      'imported' => 0,
      'updated' => 0,
      'linked' => 0,
      'unchanged' => 0,
      'skipped' => 0,
      'warnings' => [],
      'hubletoInvoicesCreated' => 0,
      'hubletoInvoicesLinked' => 0,
      'autoImportFailed' => 0,
      'notificationsSent' => 0,
      'batchLimit' => $batchLimit,
      'cycleCompleted' => false,
    ];

    $direction = in_array($position['direction'] ?? null, ['inbound', 'outbound'], true)
      ? $position['direction']
      : 'inbound';
    $cursor = trim((string) ($position['cursor'] ?? '')) ?: null;
    $remaining = $batchLimit;

    while ($remaining > 0) {
      $page = $provider->listDocuments($direction, $cursor, $remaining);
      $documents = is_array($page['documents'] ?? null) ? $page['documents'] : [];
      if (count($documents) > $remaining) {
        $documents = array_slice($documents, 0, $remaining);
      }

      foreach ($documents as $document) {
        if (!is_array($document)) {
          continue;
        }

        $summary['found']++;
        $remaining--;
        try {
          $this->reconcileProviderDocument($provider, $document, $summary);
        } catch (\Throwable $e) {
          $summary['skipped']++;
          $summary['warnings'][] = 'A provider document could not be synchronized: ' . $e->getMessage();
        }
      }

      $nextCursor = trim((string) ($page['nextCursor'] ?? ''));
      $hasMore = (bool) ($page['hasMore'] ?? false);
      if ($hasMore && ($documents === [] || $nextCursor === '' || $nextCursor === $cursor)) {
        $summary['warnings'][] = "Provider returned an invalid {$direction} pagination cursor; synchronization stopped.";
        $direction = 'inbound';
        $cursor = null;
        break;
      }

      if ($hasMore) {
        $cursor = $nextCursor;
        continue;
      }

      if ($direction === 'inbound') {
        $direction = 'outbound';
        $cursor = null;
      } else {
        $direction = 'inbound';
        $cursor = null;
        $summary['cycleCompleted'] = true;
        break;
      }
    }

    $summary['nextPosition'] = [
      'direction' => $direction,
      'cursor' => $cursor,
    ];

    return $summary;
  }

  private function loadInvoiceDataForExport(int $invoiceId): array
  {
    /** @var Invoice $mInvoice */
    $mInvoice = $this->getModel(Invoice::class);

    $invoice = $mInvoice->record->find($invoiceId);
    if (!$invoice) {
      throw new \Exception($this->translate('Invoice not found'));
    }

    /** @var Generator $generator */
    $generator = $this->getService(Generator::class);
    $invoiceData = $generator->getPreviewVars(Invoice::class, $invoiceId);
    unset($invoiceData['hubleto']);

    if (($invoiceData['inbound_outbound'] ?? null) != Invoice::OUTBOUND_INVOICE) {
      throw new \Exception($this->translate('Only outbound invoices can be sent as e-invoices'));
    }

    return $invoiceData;
  }

  private function getProvider(?string $providerCode = null): ProviderInterface
  {
    $app = $this->getEinvoiceApp();
    $providerCode = strtolower(trim((string) ($providerCode ?? $app->providers()->getProviderName())));
    $providerClass = $app->providers()->getProviderDefinitions()[$providerCode]['providerClass'] ?? null;
    if (!is_string($providerClass) || !class_exists($providerClass)) {
      throw new \Exception("Unsupported e-invoice provider '{$providerCode}'.");
    }

    $provider = new $providerClass($app);
    if (!$provider instanceof ProviderInterface) {
      throw new \Exception("Invalid e-invoice provider '{$providerCode}'.");
    }

    return $provider;
  }

  private function reconcileProviderDocument(
    ProviderInterface $provider,
    array $document,
    array &$summary,
  ): void {
    $direction = ($document['direction'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound';
    $inboundOutbound = $direction === 'inbound' ? self::INBOUND : self::OUTBOUND;
    $transmissionId = trim((string) ($document['transmissionId'] ?? ''));
    $invoiceNumber = trim((string) ($document['invoiceNumber'] ?? ''));

    if ($transmissionId === '') {
      $summary['skipped']++;
      $summary['warnings'][] = 'A provider document was skipped because its transmission ID is missing.';
      return;
    }

    $export = $this->record
      ->where('provider', $provider->getCode())
      ->where('transmission_id', $transmissionId)
      ->first();

    $xmlContent = (string) ($export->xml_content ?? '');
    if ($xmlContent === '') {
      $xmlContent = (string) ($document['xmlContent'] ?? '');
    }
    if ($invoiceNumber === '' && $xmlContent !== '') {
      $invoiceNumber = $this->extractInvoiceNumberFromXml($xmlContent);
    }

    $invoice = null;
    if ($inboundOutbound === self::OUTBOUND && $invoiceNumber !== '') {
      /** @var Invoice $mInvoice */
      $mInvoice = $this->getModel(Invoice::class);
      $invoice = $mInvoice->record->where('number', $invoiceNumber)->first();
    }

    if (!$export && $invoice) {
      $candidate = $this->record
        ->where('id_invoice', (int) $invoice->id)
        ->orderByDesc('id')
        ->first();

      if ($candidate && empty($candidate->transmission_id)) {
        $export = $candidate;
      }
    }

    $updates = [
      'id_invoice' => $invoice ? (int) $invoice->id : ($export->id_invoice ?? null),
      'inbound_outbound' => $inboundOutbound,
      'provider' => $provider->getCode(),
      'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : ($export->invoice_number ?? ''),
      'transmission_id' => $transmissionId,
      'xml_content' => $xmlContent,
      'status' => (int) ($document['localStatus'] ?? self::STATUS_SENT),
      'peppol_response' => $this->mergeResponsePayload(
        (string) ($export->peppol_response ?? ''),
        ['providerSync' => $document['remote'] ?? $document]
      ),
      'error_message' => $document['errorMessage'] ?? null,
    ];

    foreach ([
      'date_generated' => $document['dateGenerated'] ?? null,
      'date_sent' => $document['dateSent'] ?? null,
      'date_confirmed' => $document['dateConfirmed'] ?? null,
    ] as $column => $value) {
      $date = $this->normalizeProviderDate($value);
      if ($date !== null && (empty($export) || empty($export->{$column}))) {
        $updates[$column] = $date;
      }
    }

    $recipient = trim($this->getEinvoiceApp()->providers()->providerConfigAsString('notificationEmail'));
    if ($inboundOutbound === self::INBOUND && $recipient !== '' && $updates['status'] !== self::STATUS_FAILED
      && (!$export || (int) $export->status === self::STATUS_FAILED)) {
      $payload = json_decode($updates['peppol_response'], true) ?: [];
      if (empty($payload['inboundNotification'])) {
        $updates['peppol_response'] = $this->mergeResponsePayload($updates['peppol_response'], [
          'inboundNotification' => ['status' => 'pending'],
        ]);
      }
    }

    if ($export) {
      $wasLinked = empty($export->id_invoice) && $invoice;
      $changed = false;
      foreach ($updates as $column => $value) {
        if ((string) ($export->{$column} ?? '') !== (string) ($value ?? '')) {
          $changed = true;
          break;
        }
      }

      if ($changed) {
        $export->update($updates);
        $this->createLogRecord(
          (int) $export->id,
          'provider_sync',
          Log::STATUS_INFO,
          'e-Invoice synchronized from ' . $provider->getCode()
        );
        $summary['updated']++;
      } else {
        $summary['unchanged']++;
      }

      if ($wasLinked) {
        $summary['linked']++;
      }
      $this->processInboundDocument((int) $export->id, $summary);
      return;
    }

    $savedRecord = $this->record->recordCreate($updates);
    $summary['imported']++;
    if (!empty($savedRecord['id_invoice'])) {
      $summary['linked']++;
    }
    $this->processInboundDocument((int) $savedRecord['id'], $summary);
  }

  private function getEinvoiceApp(): Loader
  {
    return $this->appManager()->getApp(Loader::class);
  }

  private function processInboundDocument(int $id, array &$summary): void
  {
    $export = $this->record->find($id);
    if (!$export || (int) $export->inbound_outbound !== self::INBOUND
      || (int) $export->status === self::STATUS_FAILED) return;

    $app = $this->getEinvoiceApp();
    if (empty($export->id_invoice) && $app->providers()->providerConfigAsString('autoCreateInvoices', '0') === '1') {
      try {
        $result = (new InboundInvoiceImporter($app))->import($id);
        $summary[$result['created'] ? 'hubletoInvoicesCreated' : 'hubletoInvoicesLinked']++;
      } catch (\Throwable $e) {
        $summary['autoImportFailed']++;
        $summary['warnings'][] = 'Hubleto invoice for e-Invoice #' . $id . ' could not be created: ' . $e->getMessage();
        $this->createLogRecord($id, 'hubleto_invoice_import', Log::STATUS_ERROR, $e->getMessage());
      }
    }

    $export = $this->record->find($id);
    $payload = json_decode((string) $export->peppol_response, true) ?: [];
    $recipient = trim($app->providers()->providerConfigAsString('notificationEmail'));
    if ($recipient === '' || ($payload['inboundNotification']['status'] ?? '') !== 'pending') return;
    try {
      if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \Exception('Invalid notification email address.');
      $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $url = rtrim($this->env()->projectUrl, '/') . '/einvoice/' . $id;
      $body = '<p>' . $escape($this->translate('A new inbound e-Invoice was received.')) . '</p>'
        . '<p>' . $escape((string) $export->invoice_number) . '</p>'
        . '<p><a href="' . $escape($url) . '">' . $escape($this->translate('Open e-Invoice')) . '</a></p>';
      /** @var EmailProvider $emailProvider */
      $emailProvider = $this->getService(EmailProvider::class);
      if (!$emailProvider->send($recipient, $this->translate('New inbound e-Invoice'), $body)) {
        throw new \Exception('The email provider did not confirm sending the notification.');
      }
      $export->update(['peppol_response' => $this->mergeResponsePayload((string) $export->peppol_response, [
        'inboundNotification' => ['status' => 'sent', 'sentAt' => date('Y-m-d H:i:s')],
      ])]);
      $summary['notificationsSent']++;
      $this->createLogRecord($id, 'inbound_notification', Log::STATUS_SUCCESS, 'Inbound invoice notification sent.');
    } catch (\Throwable $e) {
      $summary['warnings'][] = 'Notification for e-Invoice #' . $id . ' could not be sent: ' . $e->getMessage();
      $this->createLogRecord($id, 'inbound_notification', Log::STATUS_ERROR, $e->getMessage());
    }
  }

  private function normalizeProviderDate(mixed $value): ?string
  {
    $timestamp = strtotime(trim((string) $value));
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
  }

  private function extractInvoiceNumberFromXml(string $xmlContent): string
  {
    $document = new \DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xmlContent);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    if (!$loaded) {
      return '';
    }

    $xpath = new \DOMXPath($document);
    return trim((string) $xpath->evaluate('string(/*[1]/*[local-name()="ID"][1])'));
  }

  private function appendResponsePayload(int $exportId, string $key, array $payload): void
  {
    $export = $this->record->find($exportId);
    if (!$export) {
      return;
    }

    $export->update([
      'peppol_response' => $this->mergeResponsePayload($export->peppol_response ?? '', [$key => $payload]),
    ]);
  }

  private function mergeResponsePayload(string $currentPayload, array $nextPayload): string
  {
    $decoded = json_decode($currentPayload, true);
    if (!is_array($decoded)) {
      $decoded = [];
    }

    foreach ($nextPayload as $key => $value) {
      $decoded[$key] = $value;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  private function createLogRecord(int $einvoiceId, string $actionType, int $status, string $message): void
  {
    /** @var Log $mLog */
    $mLog = $this->getModel(Log::class);
    $mLog->record->recordCreate([
      'id_einvoice' => $einvoiceId,
      'action_type' => $actionType,
      'status' => $status,
      'message' => $message,
      'timestamp' => date('Y-m-d H:i:s'),
    ]);
  }

  private function isRemotelyTracked(mixed $export): bool
  {
    return !empty($export->transmission_id) || in_array(
      (int) ($export->status ?? self::STATUS_PENDING),
      [self::STATUS_SENT, self::STATUS_CONFIRMED],
      true,
    );
  }
}
