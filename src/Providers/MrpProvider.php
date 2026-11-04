<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\App\External\WaiBlue\Einvoice\Services\MrpClient;

class MrpProvider implements ProviderInterface
{
  private const PEPPOL_PROCESS_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
  private const PEPPOL_INVOICE_DOCUMENT_TYPE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';
  private const PEPPOL_CREDIT_NOTE_DOCUMENT_TYPE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

  private ?MrpClient $client = null;

  public function __construct(
    private Loader $app,
  ) {
  }

  public function getCode(): string
  {
    return 'mrp';
  }

  public function preflight(array $invoiceData, string $xmlContent): array
  {
    $errors = [];
    $warnings = [];
    $validation = [];
    $discovery = [];

    foreach (['clientId' => 'Client ID', 'clientSecret' => 'Client secret', 'participantId' => 'Identifier'] as $field => $label) {
      if (trim($this->app->providers()->providerConfigAsString($field, '', $this->getCode())) === '') {
        $errors[] = 'MRP ' . $label . ' is missing.';
      }
    }

    try {
      $receiverParticipantId = $this->resolveReceiverPeppolId($invoiceData);
      $documentTypeId = $this->resolveDocumentTypeId($invoiceData);
    } catch (\Throwable $e) {
      $errors[] = $e->getMessage();
    }

    if ($errors === []) {
      try {
        $validation = $this->getClient()->validateDocument($xmlContent);
        foreach ($this->validationMessages($validation['errors'] ?? []) as $message) {
          $errors[] = $message;
        }
        foreach ($this->validationMessages($validation['warnings'] ?? []) as $message) {
          $warnings[] = $message;
        }
        if (!(bool) ($validation['valid'] ?? false) && $errors === []) {
          $errors[] = 'MRP validation failed without returning a validation message.';
        }
      } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }

    if ($errors === []) {
      try {
        $discovery = $this->getClient()->discover($receiverParticipantId);
        if (!(bool) ($discovery['registered'] ?? false)) {
          $errors[] = "Recipient {$receiverParticipantId} is not registered in the Peppol network.";
        } elseif (!$this->supportsDocumentType($discovery, $documentTypeId)) {
          $errors[] = "Recipient {$receiverParticipantId} does not accept this Peppol document type.";
        }
      } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }

    return [
      'decision' => $errors === [] ? 'sendable' : 'blocked',
      'valid' => $errors === [],
      'canSend' => $errors === [],
      'errors' => $errors,
      'warnings' => $warnings,
      'validation' => $validation,
      'discovery' => $discovery,
      'senderParticipantId' => $this->resolveSenderPeppolId(),
      'receiverParticipantId' => $receiverParticipantId ?? null,
      'documentTypeId' => $documentTypeId ?? null,
      'processId' => self::PEPPOL_PROCESS_ID,
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
    $warnings = is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [];

    return 'Preflight decision: ' . $decision
      . ($errors !== [] ? ', errors=' . count($errors) : '')
      . ($warnings !== [] ? ', warnings=' . count($warnings) : '');
  }

  public function sendDocument(array $invoiceData, string $xmlContent): array
  {
    $senderParticipantId = $this->resolveSenderPeppolId();
    $receiverParticipantId = $this->resolveReceiverPeppolId($invoiceData);
    $payload = [
      'metadata' => [
        'documentId' => (string) ($invoiceData['number'] ?? ''),
        'documentTypeId' => $this->resolveDocumentTypeId($invoiceData),
        'processId' => self::PEPPOL_PROCESS_ID,
        'senderParticipantId' => $senderParticipantId,
        'receiverParticipantId' => $receiverParticipantId,
        'creationDateTime' => $this->creationDateTime($invoiceData),
      ],
      'payload' => base64_encode($xmlContent),
      'payloadEncoding' => 'base64',
      'payloadFormat' => 'xml',
      'checksum' => hash('sha256', $xmlContent),
    ];

    $sendResponse = $this->getClient()->sendDocument($payload, $this->buildIdempotencyKey($payload));
    $documentId = trim((string) ($sendResponse['documentId'] ?? $sendResponse['providerDocumentId'] ?? ''));
    if ($documentId === '') {
      throw new \Exception('MRP send response did not include documentId.');
    }

    $remoteStatus = strtoupper(trim((string) ($sendResponse['status'] ?? 'ACCEPTED')));
    return [
      'receiverPeppolId' => $receiverParticipantId,
      'send' => $sendResponse,
      'localStatus' => $this->mapRemoteStatusToLocalStatus($remoteStatus),
      'statusMessage' => 'MRP send status: ' . $remoteStatus . ' (document ID ' . $documentId . ')',
      'transmissionId' => $documentId,
    ];
  }

  public function syncDocumentStatus(string $transmissionId): array
  {
    $detail = $this->getClient()->document($transmissionId, 'outbound');
    $remoteStatus = $this->remoteStatus($detail);
    $localStatus = $this->mapRemoteStatusToLocalStatus($remoteStatus);

    return [
      'status' => $detail,
      'localStatus' => $localStatus,
      'errorMessage' => $localStatus === Einvoice::STATUS_FAILED ? $this->failureMessage($detail) : null,
      'logMessage' => 'MRP status synchronized as ' . ($remoteStatus !== '' ? $remoteStatus : 'UNKNOWN'),
    ];
  }

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $response = $this->getClient()->documents($direction, $cursor, min($limit, 100));
    $documents = [];

    foreach (($response['documents'] ?? []) as $item) {
      if (!is_array($item)) {
        continue;
      }

      $documentId = trim((string) ($item['documentId'] ?? ''));
      if ($documentId === '') {
        continue;
      }

      $detail = $this->getClient()->document($documentId, $direction);
      $metadata = is_array($detail['metadata'] ?? null) ? $detail['metadata'] : $item;
      $xmlContent = $this->payload($detail);
      $remoteStatus = $direction === 'inbound' ? 'RECEIVED' : $this->remoteStatus($detail + $item);
      $delivery = is_array($detail['delivery'] ?? null) ? $detail['delivery'] : [];
      $localStatus = $direction === 'inbound'
        ? Einvoice::STATUS_CONFIRMED
        : $this->mapRemoteStatusToLocalStatus($remoteStatus);

      $documents[] = [
        'direction' => $direction,
        'transmissionId' => $documentId,
        'invoiceNumber' => $this->invoiceNumber($xmlContent),
        'localStatus' => $localStatus,
        'dateGenerated' => $metadata['creationDateTime'] ?? null,
        'dateSent' => $direction === 'outbound'
          ? ($delivery['sentAt'] ?? $metadata['creationDateTime'] ?? null)
          : null,
        'dateConfirmed' => $direction === 'inbound'
          ? ($metadata['creationDateTime'] ?? null)
          : ($delivery['deliveredAt'] ?? $item['deliveredAt'] ?? null),
        'errorMessage' => $localStatus === Einvoice::STATUS_FAILED ? $this->failureMessage($detail + $item) : null,
        'xmlContent' => $xmlContent,
        'remote' => array_merge($item, [
          'status' => $remoteStatus,
          'delivery' => $delivery,
        ]),
      ];
    }

    $nextCursor = trim((string) ($response['nextPageToken'] ?? ''));
    return [
      'documents' => $documents,
      'nextCursor' => $nextCursor !== '' ? $nextCursor : null,
      'hasMore' => $nextCursor !== '',
    ];
  }

  public function getDocumentXml(string $transmissionId, string $direction): string
  {
    return $this->payload($this->getClient()->document($transmissionId, $direction));
  }

  private function getClient(): MrpClient
  {
    return $this->client ??= new MrpClient($this->app);
  }

  private function resolveSenderPeppolId(): string
  {
    return trim($this->app->providers()->providerConfigAsString('participantId', '', $this->getCode()));
  }

  private function resolveReceiverPeppolId(array $invoiceData): string
  {
    $customer = $invoiceData['CUSTOMER'] ?? [];
    $peppolId = trim((string) ($customer['peppol_id'] ?? ''));
    if ($peppolId !== '') {
      return $peppolId;
    }

    $identifier = preg_replace('/[^0-9]/', '', (string) ($customer['tax_id'] ?? $customer['vat_id'] ?? ''));
    if ($identifier === '') {
      throw new \Exception('Customer Tax ID / VAT ID is missing, so receiver Peppol ID cannot be built.');
    }

    return '0245:' . $identifier;
  }

  private function resolveDocumentTypeId(array $invoiceData): string
  {
    return match ((int) ($invoiceData['type'] ?? Invoice::TYPE_STANDARD)) {
      Invoice::TYPE_STANDARD => self::PEPPOL_INVOICE_DOCUMENT_TYPE,
      Invoice::TYPE_CREDIT_NOTE => self::PEPPOL_CREDIT_NOTE_DOCUMENT_TYPE,
      default => throw new \Exception('MRP currently supports standard invoices and credit notes.'),
    };
  }

  private function supportsDocumentType(array $discovery, string $documentTypeId): bool
  {
    foreach (($discovery['documentTypes'] ?? []) as $documentType) {
      $candidate = is_array($documentType)
        ? (string) ($documentType['documentTypeId'] ?? '')
        : (string) $documentType;
      $candidate = rawurldecode($candidate);
      if ($candidate === $documentTypeId || str_contains($candidate, $documentTypeId)) {
        return true;
      }
    }

    return false;
  }

  private function validationMessages(mixed $messages): array
  {
    if (!is_array($messages)) {
      return [];
    }

    $result = [];
    foreach ($messages as $message) {
      if (is_string($message)) {
        $result[] = $message;
      } elseif (is_array($message)) {
        $ruleId = trim((string) ($message['ruleId'] ?? ''));
        $text = trim((string) ($message['message'] ?? 'Validation error'));
        $result[] = ($ruleId !== '' ? '[' . $ruleId . '] ' : '') . $text;
      }
    }

    return $result;
  }

  private function remoteStatus(array $response): string
  {
    $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
    $delivery = is_array($response['delivery'] ?? null) ? $response['delivery'] : [];
    return strtoupper(trim((string) (
      $delivery['state']
      ?? $response['deliveryState']
      ?? $response['status']
      ?? $metadata['status']
      ?? ''
    )));
  }

  private function mapRemoteStatusToLocalStatus(string $remoteStatus): int
  {
    return match (strtoupper($remoteStatus)) {
      'DELIVERED', 'RECEIVED' => Einvoice::STATUS_CONFIRMED,
      'FAILED' => Einvoice::STATUS_FAILED,
      default => Einvoice::STATUS_SENT,
    };
  }

  private function failureMessage(array $response): string
  {
    $delivery = is_array($response['delivery'] ?? null) ? $response['delivery'] : [];
    $failure = is_array($delivery['failureReason'] ?? null) ? $delivery['failureReason'] : [];
    $code = trim((string) ($failure['code'] ?? $response['failureCode'] ?? ''));
    $message = trim((string) ($failure['message'] ?? 'MRP document delivery failed.'));
    return ($code !== '' ? '[' . $code . '] ' : '') . $message;
  }

  private function payload(array $detail): string
  {
    $payload = (string) ($detail['payload'] ?? '');
    if (($detail['payloadEncoding'] ?? 'xml') === 'base64') {
      $decoded = base64_decode($payload, true);
      return $decoded !== false ? $decoded : '';
    }

    return $payload;
  }

  private function invoiceNumber(string $xmlContent): string
  {
    if ($xmlContent === '') {
      return '';
    }

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

  private function creationDateTime(array $invoiceData): string
  {
    $issueDate = trim((string) ($invoiceData['date_issue'] ?? ''));
    $timestamp = $issueDate !== '' ? strtotime($issueDate . ' 00:00:00 UTC') : false;
    return $timestamp !== false ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : gmdate('Y-m-d\TH:i:s\Z');
  }

  private function buildIdempotencyKey(array $payload): string
  {
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
      throw new \Exception('Failed to encode MRP payload for idempotency key generation.');
    }

    $namespaceHex = str_replace('-', '', '6ba7b811-9dad-11d1-80b4-00c04fd430c8');
    $namespaceBytes = '';
    for ($i = 0; $i < strlen($namespaceHex); $i += 2) {
      $namespaceBytes .= chr(hexdec(substr($namespaceHex, $i, 2)));
    }
    $hash = sha1($namespaceBytes . $jsonPayload);

    return sprintf(
      '%08s-%04s-%04x-%04x-%12s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12),
    );
  }
}
