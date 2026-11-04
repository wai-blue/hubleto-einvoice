<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Services\EpostakClient;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;

class EpostakProvider implements ProviderInterface
{
  private const PEPPOL_PROCESS_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
  private const PEPPOL_INVOICE_DOCUMENT_TYPE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';
  private const PEPPOL_CREDIT_NOTE_DOCUMENT_TYPE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

  public function __construct(
    private Loader $app,
  ) {
  }

  public function getCode(): string
  {
    return 'epostak';
  }

  public function preflight(array $invoiceData, string $xmlContent): array
  {
    return $this->getClient()->preflight([
      'senderParticipantId' => $this->resolveSenderPeppolId($invoiceData),
      'receiverParticipantId' => $this->resolveReceiverPeppolId($invoiceData),
      'documentTypeId' => $this->resolveDocumentTypeId($invoiceData),
      'processId' => self::PEPPOL_PROCESS_ID,
      'ublDocument' => $xmlContent,
    ]);
  }

  public function canSend(array $preflight): bool
  {
    return (bool) ($preflight['canSend'] ?? false);
  }

  public function describePreflight(array $preflight): string
  {
    $decision = (string) ($preflight['decision'] ?? 'unknown');
    $errors = $preflight['errors'] ?? [];
    $warnings = $preflight['warnings'] ?? [];

    $parts = ['Preflight decision: ' . $decision];

    if (is_array($errors) && count($errors) > 0) {
      $parts[] = 'errors=' . count($errors);
    }
    if (is_array($warnings) && count($warnings) > 0) {
      $parts[] = 'warnings=' . count($warnings);
    }

    return implode(', ', $parts);
  }

  public function sendDocument(array $invoiceData, string $xmlContent): array
  {
    $receiverPeppolId = $this->resolveReceiverPeppolId($invoiceData);
    $sendPayload = [
      'receiverPeppolId' => $receiverPeppolId,
      'xml' => $xmlContent,
    ];

    $sendResponse = $this->getClient()->sendUbl(
      $sendPayload,
      ['Idempotency-Key: ' . $this->buildIdempotencyKey($sendPayload)]
    );

    $remoteStatus = strtolower((string) ($sendResponse['status'] ?? 'sent'));
    $documentId = trim((string) ($sendResponse['documentId'] ?? $sendResponse['submissionId'] ?? ''));
    $localStatus = $this->mapRemoteStatusToLocalStatus($remoteStatus);

    $message = 'EPOSTAK send status: ' . strtoupper($remoteStatus);
    if ($documentId !== '') {
      $message .= ' (document ID ' . $documentId . ')';
    }
    if ((bool) ($sendResponse['duplicate'] ?? false)) {
      $message .= '. EPOSTAK marked this submission as duplicate.';
    }

    return [
      'receiverPeppolId' => $receiverPeppolId,
      'send' => $sendResponse,
      'localStatus' => $localStatus,
      'statusMessage' => $message,
      'transmissionId' => $documentId,
    ];
  }

  public function syncDocumentStatus(string $transmissionId): array
  {
    $statusResponse = $this->getClient()->documentStatus($transmissionId);
    $remoteStatus = strtolower((string) ($statusResponse['status'] ?? ''));
    $localStatus = $this->mapRemoteStatusToLocalStatus($remoteStatus);

    return [
      'status' => $statusResponse,
      'localStatus' => $localStatus,
      'errorMessage' => $localStatus === Einvoice::STATUS_FAILED
        ? json_encode($statusResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null,
      'logMessage' => 'Remote status synchronized as ' . strtoupper($remoteStatus !== '' ? $remoteStatus : 'unknown'),
    ];
  }

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $response = $this->getClient()->documents($direction, $cursor, $limit);
    $documents = [];

    foreach (($response['documents'] ?? []) as $document) {
      if (!is_array($document) || !in_array($document['kind'] ?? '', ['invoice', 'credit_note'], true)) {
        continue;
      }

      $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
      $remoteStatus = $direction === 'inbound'
        ? 'received'
        : strtolower((string) ($document['transport_status'] ?? $document['business_status'] ?? ''));
      $documentDate = $direction === 'inbound'
        ? ($document['received_at'] ?? $document['created_at'] ?? null)
        : ($document['created_at'] ?? null);

      $documents[] = [
        'direction' => $direction,
        'transmissionId' => trim((string) ($document['id'] ?? '')),
        'invoiceNumber' => trim((string) ($metadata['invoice_number'] ?? '')),
        'localStatus' => $this->mapRemoteStatusToLocalStatus($remoteStatus),
        'dateGenerated' => $documentDate,
        'dateSent' => $direction === 'outbound' ? ($document['sent_at'] ?? null) : null,
        'dateConfirmed' => $direction === 'inbound' ? $documentDate : ($document['delivered_at'] ?? null),
        'errorMessage' => $document['error']['message'] ?? null,
        'remote' => $document,
      ];
    }

    return [
      'documents' => $documents,
      'nextCursor' => $response['next_cursor'] ?? null,
      'hasMore' => (bool) ($response['has_more'] ?? false),
    ];
  }

  public function getDocumentXml(string $transmissionId, string $direction): string
  {
    return $this->getClient()->documentUbl($transmissionId, $direction);
  }

  private function resolveReceiverPeppolId(array $invoiceData): string
  {
    $customer = $invoiceData['CUSTOMER'] ?? [];
    $explicitPeppolId = trim((string) ($customer['peppol_id'] ?? ''));
    if ($explicitPeppolId !== '') {
      return $explicitPeppolId;
    }

    $identifierSource = (string) ($customer['tax_id'] ?? $customer['vat_id'] ?? '');
    $identifier = preg_replace('/[^0-9]/', '', $identifierSource);
    if (empty($identifier)) {
      throw new \Exception('Customer Tax ID / VAT ID is missing, so receiverPeppolId cannot be built.');
    }

    $scheme = trim($this->app->providers()->providerConfigAsString('receiverScheme', '', $this->getCode()));
    if ($scheme === '') {
      $scheme = '0245';
    }

    return $scheme . ':' . $identifier;
  }

  private function resolveSenderPeppolId(array $invoiceData): string
  {
    $company = $invoiceData['PROFILE']['COMPANY'] ?? [];
    $identifierSource = (string) ($company['tax_id'] ?? $company['vat_id'] ?? '');
    $identifier = preg_replace('/[^0-9]/', '', $identifierSource);
    if (empty($identifier)) {
      throw new \Exception('Supplier Tax ID / VAT ID is missing, so senderParticipantId cannot be built.');
    }

    return '0245:' . $identifier;
  }

  private function resolveDocumentTypeId(array $invoiceData): string
  {
    $type = (int) ($invoiceData['type'] ?? Invoice::TYPE_STANDARD);

    if ($type === Invoice::TYPE_STANDARD) {
      return self::PEPPOL_INVOICE_DOCUMENT_TYPE;
    }
    if ($type === Invoice::TYPE_CREDIT_NOTE) {
      return self::PEPPOL_CREDIT_NOTE_DOCUMENT_TYPE;
    }

    throw new \Exception('Only standard invoices and credit notes are supported for EPOSTAK PEPPOL sending right now.');
  }

  private function mapRemoteStatusToLocalStatus(string $remoteStatus): int
  {
    return match ($remoteStatus) {
      'received', 'delivered', 'accepted', 'acknowledged' => Einvoice::STATUS_CONFIRMED,
      'rejected', 'failed', 'dead', 'undeliverable', 'send_failed', 'validation_failed' => Einvoice::STATUS_FAILED,
      'sent', 'sending', 'queued', 'processing' => Einvoice::STATUS_SENT,
      default => Einvoice::STATUS_SENT,
    };
  }

  private function getClient(): EpostakClient
  {
    return new EpostakClient($this->app);
  }

  private function buildIdempotencyKey(array $payload): string
  {
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
      throw new \Exception('Failed to encode provider payload for idempotency key generation.');
    }

    return $this->uuidV5('6ba7b811-9dad-11d1-80b4-00c04fd430c8', $jsonPayload);
  }

  private function uuidV5(string $namespace, string $name): string
  {
    $namespaceHex = str_replace('-', '', strtolower($namespace));
    $namespaceBytes = '';

    for ($i = 0; $i < strlen($namespaceHex); $i += 2) {
      $namespaceBytes .= chr(hexdec(substr($namespaceHex, $i, 2)));
    }

    $hash = sha1($namespaceBytes . $name);

    return sprintf(
      '%08s-%04s-%04x-%04x-%12s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12)
    );
  }
}
