<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\App\External\WaiBlue\Einvoice\Services\QvaliaClient;

class QvaliaProvider implements ProviderInterface
{
  public function __construct(
    private Loader $app,
  ) {
  }

  public function getCode(): string
  {
    return 'qvalia';
  }

  public function preflight(array $invoiceData, string $xmlContent): array
  {
    $errors = [];

    if (trim($this->app->providers()->providerConfigAsString('apiKey', '', $this->getCode())) === '') {
      $errors[] = 'Qvalia API key is missing.';
    }
    if (trim($this->app->providers()->providerConfigAsString('accountRegNo', '', $this->getCode())) === '') {
      $errors[] = 'Qvalia AccountRegNo is missing.';
    }

    try {
      $documentType = $this->resolveDocumentType($invoiceData);
    } catch (\Throwable $e) {
      $documentType = null;
      $errors[] = $e->getMessage();
    }

    $document = new \DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $xmlValid = $xmlContent !== '' && $document->loadXML($xmlContent);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if (!$xmlValid) {
      $errors[] = 'Generated UBL XML is invalid.';
    } elseif ($documentType !== null) {
      $expectedRoot = $documentType === 'creditnotes' ? 'CreditNote' : 'Invoice';
      if ($document->documentElement?->localName !== $expectedRoot) {
        $errors[] = "Expected {$expectedRoot} UBL document.";
      }

      $xpath = new \DOMXPath($document);
      $customizationId = trim((string) $xpath->evaluate('string(/*[1]/*[local-name()="CustomizationID"][1])'));
      if (!str_contains($customizationId, 'peppol.eu:2017:poacc:billing:3.0')) {
        $errors[] = 'UBL document is not marked as Peppol BIS Billing 3.0.';
      }
    }

    return [
      'decision' => $errors === [] ? 'sendable' : 'blocked',
      'valid' => $errors === [],
      'canSend' => $errors === [],
      'errors' => $errors,
      'warnings' => [],
      'documentType' => $documentType,
      'accountRegNo' => $this->app->providers()->providerConfigAsString('accountRegNo', '', $this->getCode()),
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
    $documentType = $this->resolveDocumentType($invoiceData);
    $sendResponse = $this->getClient()->sendDocument($documentType, $xmlContent);
    $transmissionId = trim((string) ($sendResponse['data']['integrationId'] ?? ''));
    if ($transmissionId === '') {
      throw new \Exception('Qvalia send response did not include integrationId.');
    }

    $remoteStatus = strtolower((string) ($sendResponse['status'] ?? 'success'));

    return [
      'receiverPeppolId' => $this->resolveReceiverPeppolId($invoiceData),
      'send' => $sendResponse,
      'localStatus' => $this->mapRemoteStatusToLocalStatus($remoteStatus),
      'statusMessage' => 'Qvalia accepted the document (integration ID ' . $transmissionId . ').',
      'transmissionId' => $transmissionId,
    ];
  }

  public function syncDocumentStatus(string $transmissionId): array
  {
    $statusResponse = [];
    foreach (['invoices', 'creditnotes'] as $documentType) {
      try {
        $response = $this->getClient()->documentStatus($documentType, $transmissionId);
      } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Qvalia HTTP 404')) {
          continue;
        }
        throw $e;
      }
      if ($response !== []) {
        $statusResponse = $response;
        break;
      }
    }

    $statusRecord = $this->findStatusRecord($statusResponse, $transmissionId);
    if ($statusRecord === null) {
      $statusRecord = $this->findOutgoingDocumentStatus($transmissionId);
    }
    if ($statusRecord === null) {
      throw new \Exception("Qvalia document was not found for integration ID {$transmissionId}.");
    }

    $remoteStatus = strtolower((string) ($statusRecord['metadata']['status'] ?? $statusRecord['status'] ?? ''));
    $localStatus = $this->mapRemoteStatusToLocalStatus($remoteStatus);

    return [
      'status' => $statusRecord,
      'localStatus' => $localStatus,
      'errorMessage' => $localStatus === Einvoice::STATUS_FAILED
        ? json_encode($statusRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null,
      'logMessage' => 'Remote status synchronized as ' . strtoupper($remoteStatus !== '' ? $remoteStatus : 'unknown'),
    ];
  }

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    [$documentType, $offset] = $this->parseCursor($cursor);
    $qvaliaDirection = $direction === 'inbound' ? 'incoming' : 'outgoing';
    $limit = max(1, min($limit, 500));
    $response = $this->getClient()->documents($documentType, $qvaliaDirection, $offset, $limit);
    $items = is_array($response['data'] ?? null) ? $response['data'] : [];
    $documents = $this->normalizeDocuments($items, $documentType, $direction);
    $nextCursor = null;

    if (count($items) === $limit) {
      $nextCursor = $this->buildCursor($documentType, $offset + count($items));
    } elseif ($documentType === 'invoices') {
      $remaining = $limit - count($items);
      $creditNoteResponse = $this->getClient()->documents('creditnotes', $qvaliaDirection, 0, $remaining);
      $creditNoteItems = is_array($creditNoteResponse['data'] ?? null) ? $creditNoteResponse['data'] : [];
      $documents = array_merge(
        $documents,
        $this->normalizeDocuments($creditNoteItems, 'creditnotes', $direction),
      );
      if (count($creditNoteItems) === $remaining) {
        $nextCursor = $this->buildCursor('creditnotes', count($creditNoteItems));
      }
    }

    return [
      'documents' => $documents,
      'nextCursor' => $nextCursor,
      'hasMore' => $nextCursor !== null,
    ];
  }

  public function getDocumentXml(string $transmissionId, string $direction): string
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $qvaliaDirection = $direction === 'inbound' ? 'incoming' : 'outgoing';

    foreach (['invoices', 'creditnotes'] as $documentType) {
      try {
        $xmlContent = $this->getClient()->documentXml($documentType, $qvaliaDirection, $transmissionId);
        if (trim($xmlContent) !== '') {
          return $xmlContent;
        }
      } catch (\Throwable $e) {
        if (!str_contains($e->getMessage(), 'Qvalia HTTP 404')) {
          throw $e;
        }
      }
    }

    throw new \Exception("Qvalia XML was not found for integration ID {$transmissionId}.");
  }

  private function resolveDocumentType(array $invoiceData): string
  {
    return match ((int) ($invoiceData['type'] ?? Invoice::TYPE_STANDARD)) {
      Invoice::TYPE_STANDARD => 'invoices',
      Invoice::TYPE_CREDIT_NOTE => 'creditnotes',
      default => throw new \Exception('Qvalia currently supports standard invoices and credit notes.'),
    };
  }

  private function resolveReceiverPeppolId(array $invoiceData): string
  {
    $customer = $invoiceData['CUSTOMER'] ?? [];
    $explicitPeppolId = trim((string) ($customer['peppol_id'] ?? ''));
    if ($explicitPeppolId !== '') {
      return $explicitPeppolId;
    }

    $identifier = preg_replace('/[^0-9]/', '', (string) ($customer['tax_id'] ?? $customer['vat_id'] ?? ''));
    return $identifier !== '' ? '0245:' . $identifier : '';
  }

  private function mapRemoteStatusToLocalStatus(string $remoteStatus): int
  {
    return match ($remoteStatus) {
      'delivered', 'received', 'accepted', 'acknowledged', 'completed' => Einvoice::STATUS_CONFIRMED,
      'failed', 'rejected', 'error', 'invalid', 'undeliverable' => Einvoice::STATUS_FAILED,
      default => Einvoice::STATUS_SENT,
    };
  }

  private function normalizeDocuments(array $items, string $documentType, string $direction): array
  {
    $rootName = $documentType === 'creditnotes' ? 'CreditNote' : 'Invoice';
    $documents = [];

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $ublDocument = is_array($item[$rootName] ?? null) ? $item[$rootName] : [];
      $transmissionId = trim((string) ($item['integrationId'] ?? ''));
      if ($transmissionId === '') {
        continue;
      }

      $issueDate = $this->ublValue($ublDocument, 'cbc:IssueDate');
      $documents[] = [
        'direction' => $direction,
        'transmissionId' => $transmissionId,
        'invoiceNumber' => $this->ublValue($ublDocument, 'cbc:ID'),
        'localStatus' => $direction === 'inbound' ? Einvoice::STATUS_CONFIRMED : Einvoice::STATUS_SENT,
        'dateGenerated' => $issueDate !== '' ? $issueDate : null,
        'dateSent' => null,
        'dateConfirmed' => null,
        'errorMessage' => null,
        'remote' => $item,
      ];
    }

    return $documents;
  }

  private function ublValue(array $document, string $key): string
  {
    $value = $document[$key][0]['_'] ?? $document[$key][0]['value'] ?? '';
    return trim(is_scalar($value) ? (string) $value : '');
  }

  private function parseCursor(?string $cursor): array
  {
    if ($cursor !== null && preg_match('/^(invoices|creditnotes):(\d+)$/', $cursor, $matches)) {
      return [$matches[1], (int) $matches[2]];
    }

    return ['invoices', 0];
  }

  private function buildCursor(string $documentType, int $offset): string
  {
    return $documentType . ':' . $offset;
  }

  private function findStatusRecord(array $response, string $transmissionId): ?array
  {
    $records = array_is_list($response) ? $response : ($response['data'] ?? []);
    if (!is_array($records)) {
      return null;
    }

    foreach ($records as $record) {
      if (is_array($record) && (string) ($record['uuid'] ?? '') === $transmissionId) {
        return $record;
      }
    }

    return isset($records[0]) && is_array($records[0]) ? $records[0] : null;
  }

  private function findOutgoingDocumentStatus(string $transmissionId): ?array
  {
    foreach (['invoices', 'creditnotes'] as $documentType) {
      $response = $this->getClient()->document($documentType, 'outgoing', $transmissionId);
      $records = is_array($response['data'] ?? null) ? $response['data'] : [];

      foreach ($records as $record) {
        if (!is_array($record) || (string) ($record['integrationId'] ?? '') !== $transmissionId) {
          continue;
        }

        $rootName = $documentType === 'creditnotes' ? 'CreditNote' : 'Invoice';
        $ublDocument = is_array($record[$rootName] ?? null) ? $record[$rootName] : [];

        return [
          'uuid' => $transmissionId,
          'documentId' => $this->ublValue($ublDocument, 'cbc:ID'),
          'metadata' => [
            'status' => 'sent',
            'source' => 'outgoing_transaction',
            'detail' => 'Document exists in Qvalia outgoing transaction log; delivery status is not available.',
          ],
        ];
      }
    }

    return null;
  }

  private function getClient(): QvaliaClient
  {
    return new QvaliaClient($this->app);
  }
}
