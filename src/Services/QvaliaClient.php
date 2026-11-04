<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;

class QvaliaClient
{
  public function __construct(
    private Loader $app,
  ) {
  }

  public function sendDocument(string $documentType, string $xmlContent): array
  {
    return $this->request(
      'POST',
      $this->transactionPath($documentType, 'outgoing'),
      [],
      $xmlContent,
      'application/json',
      'application/xml',
    );
  }

  public function documents(string $documentType, string $direction, int $offset, int $limit): array
  {
    return $this->request('GET', $this->transactionPath($documentType, $direction), [
      'limit' => max(1, min($limit, 500)),
      'offset' => max(0, $offset),
      'includeRead' => 'true',
    ]);
  }

  public function documentStatus(string $documentType, string $integrationId): array
  {
    return $this->request('GET', $this->transactionPath($documentType, 'outgoing') . '/status', [
      'integrationId' => $integrationId,
      'includeRead' => 'true',
      'limit' => 1,
      'offset' => 0,
    ]);
  }

  public function document(string $documentType, string $direction, string $integrationId): array
  {
    return $this->request('GET', $this->transactionPath($documentType, $direction), [
      'integrationId' => $integrationId,
      'includeRead' => 'true',
      'limit' => 1,
      'offset' => 0,
    ]);
  }

  public function documentXml(string $documentType, string $direction, string $integrationId): string
  {
    $response = $this->request(
      'GET',
      $this->transactionPath($documentType, $direction),
      [
        'integrationId' => $integrationId,
        'includeRead' => 'true',
        'limit' => 1,
        'offset' => 0,
      ],
      null,
      'application/xml',
    );

    return is_string($response) ? $response : '';
  }

  private function transactionPath(string $documentType, string $direction): string
  {
    if (!in_array($documentType, ['invoices', 'creditnotes'], true)) {
      throw new \Exception("Unsupported Qvalia document type '{$documentType}'.");
    }
    if (!in_array($direction, ['incoming', 'outgoing'], true)) {
      throw new \Exception("Unsupported Qvalia document direction '{$direction}'.");
    }

    $accountRegNo = trim($this->app->providers()->providerConfigAsString('accountRegNo', '', 'qvalia'));
    if ($accountRegNo === '') {
      throw new \Exception('Qvalia AccountRegNo must be configured in settings.');
    }

    return '/transaction/' . rawurlencode($accountRegNo) . '/' . $documentType . '/' . $direction;
  }

  private function request(
    string $method,
    string $path,
    array $query = [],
    ?string $body = null,
    string $accept = 'application/json',
    ?string $contentType = null,
  ): array|string {
    $apiKey = trim($this->app->providers()->providerConfigAsString('apiKey', '', 'qvalia'));
    if ($apiKey === '') {
      throw new \Exception('Qvalia API key must be configured in settings.');
    }

    $baseUrl = trim($this->app->providers()->providerConfigAsString('baseUrl', '', 'qvalia'));
    if ($baseUrl === '') {
      $baseUrl = 'https://api-qa.qvalia.com';
    }

    $url = rtrim($baseUrl, '/') . $path;
    if ($query !== []) {
      $url .= '?' . http_build_query($query);
    }

    $headers = [
      'Authorization: ' . $apiKey,
      'Accept: ' . $accept,
    ];
    if ($contentType !== null) {
      $headers[] = 'Content-Type: ' . $contentType;
    }

    $ch = curl_init($url);
    if ($ch === false) {
      throw new \Exception('Failed to initialize cURL for Qvalia request.');
    }

    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    if ($response === false) {
      $error = curl_error($ch);
      curl_close($ch);
      throw new \Exception('Qvalia request failed: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204) {
      return $accept === 'application/xml' ? '' : [];
    }

    $decoded = json_decode((string) $response, true);
    if ($httpCode >= 400) {
      throw new \Exception('Qvalia HTTP ' . $httpCode . ': ' . $this->extractErrorMessage($decoded, (string) $response));
    }

    if ($accept === 'application/xml') {
      return (string) $response;
    }
    if (!is_array($decoded)) {
      throw new \Exception('Qvalia returned an invalid JSON response.');
    }

    return $decoded;
  }

  private function extractErrorMessage(mixed $decoded, string $rawResponse): string
  {
    if (is_array($decoded)) {
      foreach (['message', 'error', 'data', 'status'] as $key) {
        if (array_key_exists($key, $decoded)) {
          $value = $decoded[$key];
          return is_string($value)
            ? $value
            : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Unknown error');
        }
      }
    }

    return trim($rawResponse) !== '' ? trim($rawResponse) : 'Unknown error';
  }
}
