<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

use Hubleto\App\External\WaiBlue\Einvoice\Services\FitekClient;
use Hubleto\App\External\WaiBlue\Einvoice\Services\FitekXmlGenerator;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;

class FitekProvider implements ProviderInterface
{
  public function __construct(
    private Loader $app,
  ) {
  }

  public function getCode(): string
  {
    return 'fitek';
  }

  public function preflight(array $invoiceData, string $xmlContent): array
  {
    $errors = [];

    if (trim($this->app->providers()->providerConfigAsString('integratorId', '', $this->getCode())) === '') {
      $errors[] = 'Fitek Integrator ID is missing.';
    }
    if (trim($this->app->providers()->providerConfigAsString('authorizationToken', '', $this->getCode())) === '') {
      $errors[] = 'Fitek authorization token is missing.';
    }

    try {
      $fitekXml = $this->getXmlGenerator()->generate($xmlContent);
      $fitekDocument = new \DOMDocument();
      if (!$fitekDocument->loadXML($fitekXml) || $fitekDocument->documentElement?->localName !== 'E_Invoice') {
        $errors[] = 'Generated Fitek E_Invoice XML is invalid.';
      }
    } catch (\Throwable $e) {
      $errors[] = $e->getMessage();
    }

    return [
      'decision' => $errors === [] ? 'sendable' : 'blocked',
      'valid' => $errors === [],
      'canSend' => $errors === [],
      'errors' => $errors,
      'warnings' => [],
      'documentFormat' => 'Fitek E_Invoice 1.2',
    ];
  }

  public function canSend(array $preflight): bool
  {
    return (bool) ($preflight['canSend'] ?? false);
  }

  public function describePreflight(array $preflight): string
  {
    $decision = (string) ($preflight['decision'] ?? 'unknown');
    $errors = is_array($preflight['errors'] ?? null) ? $preflight['errors'] : [];
    return 'Preflight decision: ' . $decision . ($errors !== [] ? ', errors=' . count($errors) : '');
  }

  public function sendDocument(array $invoiceData, string $xmlContent): array
  {
    $fitekXml = $this->getXmlGenerator()->generate($xmlContent);
    $sendResponse = $this->getClient()->importSalesInvoice($fitekXml);
    $transmissionId = $this->responseValue($sendResponse, [
      'InternalId',
      'internalId',
      'ResponseId',
      'responseId',
    ]);
    if ($transmissionId === '') {
      throw new \Exception('Fitek import response did not include an invoice ID.');
    }

    $remoteStatus = $this->responseValue($sendResponse, ['Status', 'status']);

    return [
      'receiverPeppolId' => $this->resolveReceiverPeppolId($invoiceData),
      'send' => $sendResponse,
      'localStatus' => Einvoice::STATUS_SENT,
      'statusMessage' => 'Fitek accepted the sales invoice into its platform'
        . ($remoteStatus !== '' ? ' (' . $remoteStatus . ')' : '')
        . '. Peppol delivery is not confirmed.',
      'transmissionId' => $transmissionId,
    ];
  }

  public function syncDocumentStatus(string $transmissionId): array
  {
    $metadata = $this->getClient()->salesInvoiceMetadata($transmissionId);

    return [
      'status' => $metadata,
      'localStatus' => Einvoice::STATUS_SENT,
      'errorMessage' => null,
      'logMessage' => 'Fitek sales invoice metadata synchronized. Peppol delivery is not confirmed by this endpoint.',
    ];
  }

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }
    $xmlContent = $direction === 'inbound'
      ? $this->getClient()->exportInvoices()
      : $this->getClient()->exportSalesInvoices();
    $documents = $this->normalizeInvoices($xmlContent, $direction);
    $offset = ctype_digit((string) $cursor) ? (int) $cursor : 0;
    $limit = max(1, min($limit, 500));
    $page = array_slice($documents, $offset, $limit);
    $nextOffset = $offset + count($page);
    $hasMore = $nextOffset < count($documents);

    return [
      'documents' => $page,
      'nextCursor' => $hasMore ? (string) $nextOffset : null,
      'hasMore' => $hasMore,
    ];
  }

  public function getDocumentXml(string $transmissionId, string $direction): string
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    if ($direction === 'outbound') {
      return $this->getClient()->exportSalesInvoices([
        'invoiceGuid' => [$transmissionId],
      ]);
    }

    $queryKey = $this->isUuid($transmissionId) ? 'InternalInvoiceGuids' : 'InternalInvoiceIds';
    $identifier = $queryKey === 'InternalInvoiceIds' ? (int) $transmissionId : $transmissionId;
    return $this->getClient()->exportInvoices([$queryKey => [$identifier]]);
  }

  private function normalizeInvoices(string $xmlContent, string $direction): array
  {
    $document = new \DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xmlContent);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if (!$loaded) {
      throw new \Exception('Fitek returned invalid invoice XML.');
    }

    $xpath = new \DOMXPath($document);
    $documents = [];

    $invoiceQuery = '//*[local-name()="Invoice" and ('
      . '@invoiceGuid or @invoiceGlobUniqId or @invoiceId or ./*[local-name()="InvoiceInformation"]'
      . ')]';
    foreach ($xpath->query($invoiceQuery) ?: [] as $invoiceNode) {
      if (!$invoiceNode instanceof \DOMElement) {
        continue;
      }

      $invoiceNumber = $this->firstValue($xpath, $invoiceNode, [
        './/*[local-name()="InvoiceInformation"]/*[local-name()="InvoiceNumber"][1]',
        './/*[local-name()="InvoiceNumber"][1]',
        './/*[local-name()="ClientInvoiceId"][1]',
      ]);
      $metadata = [];
      $transmissionId = $this->firstValue($xpath, $invoiceNode, [
        '@invoiceGuid',
        '@invoiceGlobUniqId',
        './/*[local-name()="InternalInvoiceGuid"][1]',
        './/*[local-name()="InternalInvoiceId"][1]',
      ]);
      if ($transmissionId === '' && $direction === 'outbound' && $invoiceNumber !== '') {
        $metadata = $this->getClient()->salesInvoiceMetadataByNumber($invoiceNumber);
        $transmissionId = $this->responseValue($metadata, ['InvoiceGlobalId', 'invoiceGlobalId']);
      }
      if ($transmissionId === '' && $direction === 'inbound') {
        $transmissionId = $this->firstValue($xpath, $invoiceNode, ['@invoiceId']);
      }
      if ($transmissionId === '') {
        continue;
      }

      $invoiceDate = $this->firstValue($xpath, $invoiceNode, [
        './/*[local-name()="InvoiceInformation"]/*[local-name()="InvoiceDate"][1]',
        './/*[local-name()="InvoiceDate"][1]',
      ]);
      $metadataDate = $this->responseValue($metadata, ['InvoiceDate', 'invoiceDate']);
      if ($metadataDate !== '') {
        $invoiceDate = $metadataDate;
      }

      $documents[] = [
        'direction' => $direction,
        'transmissionId' => $transmissionId,
        'invoiceNumber' => $invoiceNumber,
        'localStatus' => $direction === 'inbound' ? Einvoice::STATUS_CONFIRMED : Einvoice::STATUS_SENT,
        'dateGenerated' => $invoiceDate !== '' ? $invoiceDate : null,
        'dateSent' => null,
        'dateConfirmed' => null,
        'errorMessage' => null,
        'xmlContent' => (string) $document->saveXML($invoiceNode),
        'remote' => $metadata !== [] ? $metadata : [
          'invoiceGuid' => $transmissionId,
          'invoiceNumber' => $invoiceNumber,
          'invoiceDate' => $invoiceDate,
        ],
      ];
    }

    return $documents;
  }

  private function firstValue(\DOMXPath $xpath, \DOMElement $context, array $queries): string
  {
    foreach ($queries as $query) {
      $value = trim((string) $xpath->evaluate('string(' . $query . ')', $context));
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  private function isUuid(string $value): bool
  {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
  }

  private function getClient(): FitekClient
  {
    return new FitekClient($this->app);
  }

  private function getXmlGenerator(): FitekXmlGenerator
  {
    return new FitekXmlGenerator();
  }

  private function resolveReceiverPeppolId(array $invoiceData): string
  {
    $customer = $invoiceData['CUSTOMER'] ?? [];
    $peppolId = trim((string) ($customer['peppol_id'] ?? ''));
    if ($peppolId !== '') {
      return $peppolId;
    }

    $identifier = (string) preg_replace(
      '/[^a-zA-Z0-9]/',
      '',
      (string) ($customer['tax_id'] ?? $customer['vat_id'] ?? '')
    );
    return $identifier !== '' ? '0245:' . $identifier : '';
  }

  private function responseValue(array $response, array $keys): string
  {
    foreach ($keys as $key) {
      if (isset($response[$key]) && is_scalar($response[$key])) {
        return trim((string) $response[$key]);
      }
    }

    foreach ($response as $value) {
      if (is_array($value)) {
        $match = $this->responseValue($value, $keys);
        if ($match !== '') {
          return $match;
        }
      }
    }

    return '';
  }
}
