<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;

class FitekClient
{
  public function __construct(
    private Loader $app,
  ) {
  }

  public function exportInvoices(array $queryParams = []): string
  {
    return $this->request(
      '/ExportApi/Invoices.v3',
      $this->jsonBody($this->authenticatedPayload([
        'queryParams' => $queryParams === [] ? (object) [] : $queryParams,
      ])),
      'application/json',
      'application/xml',
    );
  }

  public function importSalesInvoice(string $eInvoiceXml): array
  {
    $source = new \DOMDocument();
    if (!$source->loadXML($eInvoiceXml) || $source->documentElement?->localName !== 'E_Invoice') {
      throw new \Exception('Invalid Fitek E_Invoice XML.');
    }

    $requestId = $this->uuidV4();
    [$integratorId, $authorizationToken] = $this->credentials();
    $request = new \DOMDocument('1.0', 'UTF-8');
    $request->formatOutput = true;
    $root = $request->appendChild($request->createElement('SalesInvoiceImportRequest'));
    $root->appendChild($request->createElement('RequestId', $requestId));
    $root->appendChild($request->createElement('IntegratorId', $integratorId));
    $root->appendChild($request->createElement('AuthorizationToken', $authorizationToken));
    $payload = $root->appendChild($request->createElement('Payload'));
    $invoice = $payload->appendChild($request->createElement('Invoice'));
    $invoice->appendChild($request->importNode($source->documentElement, true));

    $response = $this->request(
      '/DataExchangeWebApiCore/SalesInvoice/ImportSalesInvoice.v3',
      (string) $request->saveXML(),
      'application/xml',
      'application/json',
    );

    return $this->decodeResponse($response);
  }

  public function exportSalesInvoices(array $filters = []): string
  {
    return $this->request(
      '/DataExchangeWebApiCore/SalesInvoice/ExportSalesInvoice.v3',
      $this->jsonBody($this->authenticatedPayload([
        'payload' => $filters === [] ? (object) [] : $filters,
        'queryParams' => (object) [],
      ])),
      'application/json',
      'application/xml',
    );
  }

  public function salesInvoiceMetadata(string $invoiceGlobalId): array
  {
    return $this->salesInvoiceMetadataRequest(['invoiceGlobalId' => $invoiceGlobalId]);
  }

  public function salesInvoiceMetadataByNumber(string $invoiceNumber): array
  {
    return $this->salesInvoiceMetadataRequest(['number' => $invoiceNumber]);
  }

  private function salesInvoiceMetadataRequest(array $payload): array
  {
    $response = $this->request(
      '/DataExchangeWebApiCore/SalesInvoice/ExportSalesInvoiceMetadata.v3',
      $this->jsonBody($this->authenticatedPayload([
        'payload' => $payload,
        'queryParams' => (object) [],
      ])),
      'application/json',
      'application/json',
    );

    return $this->decodeResponse($response);
  }

  private function authenticatedPayload(array $payload): array
  {
    [$integratorId, $authorizationToken] = $this->credentials();

    return [
      'requestId' => $this->uuidV4(),
      'integratorId' => $integratorId,
      'authorizationToken' => $authorizationToken,
      ...$payload,
    ];
  }

  private function credentials(): array
  {
    $integratorId = trim($this->app->providers()->providerConfigAsString('integratorId', '', 'fitek'));
    $authorizationToken = trim($this->app->providers()->providerConfigAsString('authorizationToken', '', 'fitek'));
    if ($integratorId === '' || $authorizationToken === '') {
      throw new \Exception('Fitek Integrator ID and authorization token must be configured in settings.');
    }
    return [$integratorId, $authorizationToken];
  }

  private function jsonBody(array $payload): string
  {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
      throw new \Exception('Failed to encode Fitek request.');
    }
    return $body;
  }

  private function request(string $path, string $body, string $contentType, string $accept): string
  {
    $baseUrl = trim($this->app->providers()->providerConfigAsString('baseUrl', '', 'fitek'));
    if ($baseUrl === '') {
      $baseUrl = 'https://fitekin.fitek.com';
    }

    $ch = curl_init(rtrim($baseUrl, '/') . $path);
    if ($ch === false) {
      throw new \Exception('Failed to initialize cURL for Fitek request.');
    }

    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_HTTPHEADER => [
        'Accept: ' . $accept,
        'Content-Type: ' . $contentType,
      ],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
      $error = curl_error($ch);
      curl_close($ch);
      throw new \Exception('Fitek request failed: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
      throw new \Exception('Fitek HTTP ' . $httpCode . ': ' . $this->extractErrorMessage((string) $response));
    }

    if (trim((string) $response) === '') {
      throw new \Exception('Fitek returned an empty response.');
    }

    return (string) $response;
  }

  private function decodeResponse(string $response): array
  {
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
      $xml = simplexml_load_string($response);
      $decoded = $xml === false ? null : json_decode(json_encode($xml), true);
    }
    if (!is_array($decoded)) {
      throw new \Exception('Fitek returned an unsupported response.');
    }
    $decoded = $this->removeAuthorizationToken($decoded);
    $status = strtolower((string) ($decoded['Status'] ?? $decoded['status'] ?? ''));
    $errorMessages = $decoded['ErrorMessages'] ?? $decoded['errorMessages'] ?? [];
    if (in_array($status, ['error', 'failed', 'failure'], true) || (is_array($errorMessages) && $errorMessages !== [])) {
      throw new \Exception('Fitek: ' . $this->extractErrorMessage($response));
    }
    return $decoded;
  }

  private function removeAuthorizationToken(array $response): array
  {
    foreach ($response as $key => $value) {
      if (strcasecmp((string) $key, 'AuthorizationToken') === 0) {
        unset($response[$key]);
      } elseif (is_array($value)) {
        $response[$key] = $this->removeAuthorizationToken($value);
      }
    }
    return $response;
  }

  private function extractErrorMessage(string $response): string
  {
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
      foreach (['GeneralErrorMessage', 'generalErrorMessage', 'GeneralMessage', 'generalMessage', 'message', 'error'] as $key) {
        if (!empty($decoded[$key])) {
          return is_scalar($decoded[$key])
            ? (string) $decoded[$key]
            : (json_encode($decoded[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Unknown error');
        }
      }
      $errorMessages = $decoded['ErrorMessages'] ?? $decoded['errorMessages'] ?? [];
      if (is_array($errorMessages) && $errorMessages !== []) {
        return implode('; ', array_map('strval', $errorMessages));
      }
    }

    $document = new \DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = trim($response) !== '' && $document->loadXML($response);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    if ($loaded) {
      $xpath = new \DOMXPath($document);
      foreach (['GeneralErrorMessage', 'GeneralMessage', 'Message', 'Error'] as $elementName) {
        $message = trim((string) $xpath->evaluate(
          'string(//*[local-name()="' . $elementName . '"][1])'
        ));
        if ($message !== '') {
          return $message;
        }
      }
    }

    $sanitized = preg_replace(
      [
        '/("authorizationToken"\s*:\s*")[^"]*(")/i',
        '/(<AuthorizationToken>)[^<]*(<\/AuthorizationToken>)/i',
      ],
      '$1[redacted]$2',
      $response,
    );
    $message = trim(strip_tags((string) $sanitized));
    return $message !== '' ? $message : 'Unknown error';
  }

  private function uuidV4(): string
  {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
      '%s-%s-%s-%s-%s',
      substr($hex, 0, 8),
      substr($hex, 8, 4),
      substr($hex, 12, 4),
      substr($hex, 16, 4),
      substr($hex, 20, 12),
    );
  }
}
