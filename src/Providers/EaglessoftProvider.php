<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\App\External\WaiBlue\Einvoice\Services\EaglessoftClient;

class EaglessoftProvider implements ProviderInterface
{
  private ?EaglessoftClient $client = null;

  public function __construct(
    private Loader $app,
  ) {
  }

  public function getCode(): string
  {
    return 'eaglessoft';
  }

  public function preflight(array $invoiceData, string $xmlContent): array
  {
    $errors = [];
    foreach (['clientId' => 'Client ID', 'clientSecret' => 'Client secret'] as $key => $label) {
      if (trim($this->app->providers()->providerConfigAsString($key, '', $this->getCode())) === '') {
        $errors[] = "DocNaut {$label} is missing.";
      }
    }

    try {
      $category = $this->documentCategory($invoiceData);
      $document = $this->parseXml($xmlContent);
      $root = $category === 'credit_note' ? 'CreditNote' : 'Invoice';
      if ($document->documentElement?->localName !== $root) {
        $errors[] = "Expected {$root} UBL document.";
      }
      $xpath = new \DOMXPath($document);
      $customizationId = $xpath->evaluate('string(/*/*[local-name()="CustomizationID"])');
      if ($customizationId !== 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0') {
        $errors[] = 'UBL document is not marked as Peppol BIS Billing 3.0.';
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
    ];
  }

  public function canSend(array $preflight): bool
  {
    return (bool) ($preflight['canSend'] ?? false);
  }

  public function describePreflight(array $preflight): string
  {
    return 'Preflight decision: ' . ($preflight['decision'] ?? 'unknown')
      . (!empty($preflight['errors']) ? ': ' . implode('; ', $preflight['errors']) : '');
  }

  public function sendDocument(array $invoiceData, string $xmlContent): array
  {
    $response = $this->getClient()->sendDocument($this->documentCategory($invoiceData), $xmlContent);
    $traceId = trim((string) ($response['data']['traceId'] ?? ''));
    if ($traceId === '') {
      throw new \Exception('DocNaut send response did not include traceId.');
    }

    return [
      'send' => $response,
      'transmissionId' => $traceId,
      'localStatus' => Einvoice::STATUS_SENT,
      'statusMessage' => 'DocNaut accepted the document for processing (trace ID ' . $traceId . ').',
    ];
  }

  public function syncDocumentStatus(string $transmissionId): array
  {
    $transaction = $this->transaction($transmissionId);
    $document = $transaction['document'] ?? [];
    $status = $this->localStatus($document, $transaction);
    return [
      'status' => $transaction,
      'localStatus' => $status,
      'errorMessage' => $status === Einvoice::STATUS_FAILED ? $this->failureMessage($document) : null,
      'logMessage' => 'DocNaut status: ' . ($document['flow_status'] ?? $transaction['transaction_status'] ?? 'inprogress')
        . ', receiver: ' . ($document['receiver_status'] ?? 'undefined'),
    ];
  }

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    $remoteDirection = $this->remoteDirection($direction);
    if ($cursor !== null && !ctype_digit($cursor)) {
      throw new \Exception('Invalid DocNaut synchronization cursor.');
    }
    $offset = (int) ($cursor ?? 0);
    $limit = max(1, min($limit, 100));
    $data = $this->getClient()->graph(<<<'GRAPHQL'
      query Invoices($where: documents_bool_exp!, $limit: Int!, $offset: Int!) {
        documents(where: $where, limit: $limit, offset: $offset, order_by: [{created_at: asc}, {id: asc}]) {
          id document_number document_date received_at flow_status receiver_status direction
          transactions(limit: 1, order_by: [{created_at: asc}, {id: asc}]) { id transaction_status }
        }
        documents_aggregate(where: $where) { aggregate { count } }
      }
      GRAPHQL, [
      'where' => [
        'direction' => ['_eq' => $remoteDirection],
        'document_category' => ['_in' => ['invoice', 'credit_note']],
      ],
      'limit' => $limit,
      'offset' => $offset,
    ]);
    $items = $data['documents'] ?? [];
    $documents = [];
    foreach ($items as $item) {
      // Keep the original trace ID across restarts, matching the ID returned on send.
      $transaction = $item['transactions'][0] ?? [];
      $traceId = trim((string) ($transaction['id'] ?? ''));
      if ($traceId === '') {
        throw new \Exception('DocNaut document has no transaction yet. Try synchronization again later.');
      }
      $status = $this->localStatus($item);
      $documents[] = [
        'direction' => $direction,
        'transmissionId' => $traceId,
        'invoiceNumber' => (string) ($item['document_number'] ?? ''),
        'localStatus' => $status,
        'dateGenerated' => $this->timestamp($item['document_date'] ?? null),
        'dateSent' => $direction === 'outbound' ? $this->timestamp($item['received_at'] ?? null) : null,
        'dateConfirmed' => null,
        'errorMessage' => $status === Einvoice::STATUS_FAILED ? $this->failureMessage($item) : null,
        'remote' => $item,
      ];
    }

    $nextOffset = $offset + count($items);
    $hasMore = $nextOffset < (int) ($data['documents_aggregate']['aggregate']['count'] ?? $nextOffset);
    return [
      'documents' => $documents,
      'nextCursor' => $hasMore ? (string) $nextOffset : null,
      'hasMore' => $hasMore,
    ];
  }

  public function getDocumentXml(string $transmissionId, string $direction): string
  {
    $transaction = $this->transaction($transmissionId);
    $document = $transaction['document'] ?? [];
    if (($document['direction'] ?? '') !== $this->remoteDirection($direction)) {
      throw new \Exception('DocNaut document direction does not match the requested invoice.');
    }
    $xmlContent = $this->getClient()->documentXml((string) $document['id']);
    $this->parseXml($xmlContent);
    return $xmlContent;
  }

  private function transaction(string $traceId): array
  {
    $data = $this->getClient()->graph(<<<'GRAPHQL'
      query InvoiceStatus($id: uuid!) {
        transactions_by_pk(id: $id) {
          id transaction_status
          document {
            id direction flow_status receiver_status
            transactions(where: {is_passive: {_eq: false}}, limit: 1) {
              process_logs(where: {log_type: {_ilike: "error"}}, order_by: {timestamp: desc}, limit: 1) {
                error_code message
              }
            }
          }
        }
      }
      GRAPHQL, ['id' => $traceId]);
    $transaction = $data['transactions_by_pk'] ?? null;
    if (!is_array($transaction)) {
      throw new \Exception("DocNaut transaction {$traceId} is not available yet. Try synchronization again later.");
    }

    return $transaction;
  }

  private function localStatus(array $document, array $transaction = []): int
  {
    $flow = $document['flow_status'] ?? $transaction['transaction_status'] ?? '';
    $receiver = $document['receiver_status'] ?? '';
    if ($flow === 'failed' || $receiver === 'unsuccessful') {
      return Einvoice::STATUS_FAILED;
    }

    return $flow === 'completed' || in_array($receiver, ['successful', 'unconfirmed'], true)
      ? Einvoice::STATUS_CONFIRMED
      : Einvoice::STATUS_SENT;
  }

  private function failureMessage(array $document): string
  {
    $log = $document['transactions'][0]['process_logs'][0] ?? [];
    if (!empty($log['message'])) {
      return (!empty($log['error_code']) ? '[' . $log['error_code'] . '] ' : '') . $log['message'];
    }

    return ($document['receiver_status'] ?? '') === 'unsuccessful'
      ? 'DocNaut: the receiver rejected the document.'
      : 'DocNaut document processing failed. Check the transaction in the provider portal.';
  }

  private function parseXml(string $xmlContent): \DOMDocument
  {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
      $valid = $xmlContent !== '' && $document->loadXML($xmlContent, LIBXML_NONET);
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    $root = $document->documentElement;
    if (!$valid || $document->doctype !== null || !in_array($root?->localName, ['Invoice', 'CreditNote'], true)
      || $root->namespaceURI !== 'urn:oasis:names:specification:ubl:schema:xsd:' . $root->localName . '-2'
    ) {
      throw new \Exception('Expected a UBL Invoice or CreditNote XML document from DocNaut.');
    }

    return $document;
  }

  private function documentCategory(array $invoiceData): string
  {
    return match ((int) ($invoiceData['type'] ?? Invoice::TYPE_STANDARD)) {
      Invoice::TYPE_STANDARD => 'invoice',
      Invoice::TYPE_CREDIT_NOTE => 'credit_note',
      default => throw new \Exception('DocNaut currently supports standard invoices and credit notes.'),
    };
  }

  private function remoteDirection(string $direction): string
  {
    return match ($direction) {
      'inbound' => 'incoming',
      'outbound' => 'outgoing',
      default => throw new \Exception("Unsupported document direction '{$direction}'."),
    };
  }

  private function timestamp(mixed $value): ?string
  {
    return is_numeric($value) ? gmdate('Y-m-d H:i:s', (int) $value) : null;
  }

  private function getClient(): EaglessoftClient
  {
    return $this->client ??= new EaglessoftClient($this->app);
  }
}
