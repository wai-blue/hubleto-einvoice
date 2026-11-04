<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;

class EpostakClient
{
  private Loader $app;
  private ?string $accessToken = null;

  public function __construct(Loader $app)
  {
    $this->app = $app;
  }

  public function preflight(array $payload): array
  {
    return $this->request('POST', '/documents/preflight', $payload);
  }

  public function sendUbl(array $payload, array $headers = []): array
  {
    return $this->request('POST', '/documents/send', $payload, $headers);
  }

  public function documentStatus(string $documentId): array
  {
    return $this->request('GET', '/documents/' . rawurlencode($documentId) . '/status');
  }

  public function documents(string $direction, ?string $cursor = null, int $limit = 500): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $query = ['limit' => max(1, min($limit, 500))];
    if ($cursor !== null && $cursor !== '') {
      $query['since'] = $cursor;
    }

    return $this->request('GET', '/' . $direction . '/documents?' . http_build_query($query));
  }

  public function documentUbl(string $documentId, string $direction): string
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    return $this->requestRaw(
      'GET',
      '/' . $direction . '/documents/' . rawurlencode($documentId) . '/ubl',
      ['Accept: application/xml']
    );
  }

  private function request(string $method, string $path, array $payload = [], array $headers = []): array
  {
    $headers = array_merge([
      'Authorization: Bearer ' . $this->getAccessToken(),
      'Accept: application/json',
    ], $headers);

    return $this->execute($method, $this->getBaseUrl() . $path, $payload, $headers);
  }

  private function requestRaw(string $method, string $path, array $headers = []): string
  {
    $headers = array_merge([
      'Authorization: Bearer ' . $this->getAccessToken(),
    ], $headers);

    $response = $this->execute($method, $this->getBaseUrl() . $path, [], $headers, true);
    return is_string($response) ? $response : '';
  }

  private function getAccessToken(): string
  {
    if ($this->accessToken !== null) {
      return $this->accessToken;
    }

    $clientId = trim($this->app->providers()->providerConfigAsString('clientId', '', 'epostak'));
    $clientSecret = trim($this->app->providers()->providerConfigAsString('clientSecret', '', 'epostak'));

    if ($clientId === '' || $clientSecret === '') {
      throw new \Exception('EPOSTAK OAuth client ID and client secret must be configured in settings.');
    }

    $response = $this->execute(
      'POST',
      $this->getBaseUrl() . '/auth/token',
      [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
      ],
      ['Accept: application/json']
    );

    $accessToken = (string) ($response['access_token'] ?? '');
    if ($accessToken === '') {
      throw new \Exception('EPOSTAK token response did not include access_token.');
    }

    $this->accessToken = $accessToken;
    return $this->accessToken;
  }

  private function getBaseUrl(): string
  {
    $baseUrl = trim($this->app->providers()->providerConfigAsString('baseUrl', '', 'epostak'));
    if ($baseUrl === '') {
      $baseUrl = 'https://dev.epostak.sk/api/v1';
    }

    return rtrim($baseUrl, '/');
  }

  private function execute(
    string $method,
    string $url,
    array $payload = [],
    array $headers = [],
    bool $returnRaw = false,
  ): array|string
  {
    $ch = curl_init($url);
    if ($ch === false) {
      throw new \Exception('Failed to initialize cURL for EPOSTAK request.');
    }

    $body = '';
    if ($method !== 'GET') {
      $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($body === false) {
        throw new \Exception('Failed to encode EPOSTAK payload as JSON.');
      }
      $headers[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
      $error = curl_error($ch);
      curl_close($ch);
      throw new \Exception('EPOSTAK request failed: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
      $decoded = ['raw' => (string) $response];
    }

    if ($httpCode >= 400) {
      $message = $this->extractErrorMessage($decoded, (string) $response);
      throw new \Exception('EPOSTAK HTTP ' . $httpCode . ': ' . $message);
    }

    if ($returnRaw) {
      return (string) $response;
    }

    return $decoded;
  }

  private function extractErrorMessage(array $decoded, string $rawResponse): string
  {
    foreach (['message', 'error', 'errors', 'detail', 'details', 'code'] as $key) {
      if (array_key_exists($key, $decoded)) {
        return $this->stringifyErrorValue($decoded[$key]);
      }
    }

    return $this->stringifyErrorValue($decoded ?: $rawResponse);
  }

  private function stringifyErrorValue(mixed $value): string
  {
    if (is_string($value)) {
      return $value;
    }

    if (is_scalar($value) || $value === null) {
      return (string) $value;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded !== false ? $encoded : 'Unserializable EPOSTAK error response';
  }
}
