<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;

class MrpClient
{
  private ?string $accessToken = null;

  public function __construct(
    private Loader $app,
  ) {
  }

  public function validateDocument(string $xmlContent): array
  {
    return $this->request('POST', '/sapi/document/validate', [
      'payload' => base64_encode($xmlContent),
      'payloadEncoding' => 'base64',
    ]);
  }

  public function discover(string $participantId): array
  {
    return $this->request('GET', '/sapi/discover/' . rawurlencode($participantId));
  }

  public function sendDocument(array $payload, string $idempotencyKey): array
  {
    return $this->request('POST', '/sapi/document/send', $payload, [], [
      'Idempotency-Key: ' . $idempotencyKey,
    ]);
  }

  public function documents(string $direction, ?string $pageToken = null, int $limit = 100): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $query = ['limit' => max(1, min($limit, 100))];
    if ($pageToken !== null && $pageToken !== '') {
      $query['pageToken'] = $pageToken;
    }

    $path = $direction === 'inbound' ? '/sapi/document/receive' : '/sapi/document/sent';
    return $this->request('GET', $path, [], $query);
  }

  public function document(string $documentId, string $direction): array
  {
    if (!in_array($direction, ['inbound', 'outbound'], true)) {
      throw new \Exception("Unsupported document direction '{$direction}'.");
    }

    $path = $direction === 'inbound' ? '/sapi/document/receive/' : '/sapi/document/sent/';
    return $this->request('GET', $path . rawurlencode($documentId), [], ['encoding' => 'xml']);
  }

  private function request(
    string $method,
    string $path,
    array $payload = [],
    array $query = [],
    array $headers = [],
  ): array {
    if ($query !== []) {
      $path .= '?' . http_build_query($query);
    }

    $headers = array_merge([
      'Authorization: Bearer ' . $this->getAccessToken(),
      'Accept: application/json',
      'X-Peppol-Participant-Id: ' . $this->getParticipantId(),
    ], $headers);

    return $this->execute($method, $this->getBaseUrl() . $path, $payload, $headers);
  }

  private function getAccessToken(): string
  {
    if ($this->accessToken !== null) {
      return $this->accessToken;
    }

    $clientId = trim($this->app->providers()->providerConfigAsString('clientId', '', 'mrp'));
    $clientSecret = trim($this->app->providers()->providerConfigAsString('clientSecret', '', 'mrp'));
    if ($clientId === '' || $clientSecret === '') {
      throw new \Exception('MRP Client ID and Client secret must be configured in settings.');
    }

    $response = $this->execute(
      'POST',
      $this->getBaseUrl() . '/sapi/auth/token',
      [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials',
      ],
      ['Accept: application/json'],
    );

    $accessToken = trim((string) ($response['access_token'] ?? ''));
    if ($accessToken === '') {
      throw new \Exception('MRP token response did not include access_token.');
    }

    $this->accessToken = $accessToken;
    return $this->accessToken;
  }

  private function getParticipantId(): string
  {
    $participantId = trim($this->app->providers()->providerConfigAsString('participantId', '', 'mrp'));
    if ($participantId === '') {
      throw new \Exception('MRP Identifier must be configured in settings.');
    }

    return $participantId;
  }

  private function getBaseUrl(): string
  {
    $baseUrl = trim($this->app->providers()->providerConfigAsString('baseUrl', '', 'mrp'));
    return rtrim($baseUrl !== '' ? $baseUrl : 'https://test.ap.mrp.sk', '/');
  }

  private function execute(string $method, string $url, array $payload = [], array $headers = []): array
  {
    $ch = curl_init($url);
    if ($ch === false) {
      throw new \Exception('Failed to initialize cURL for MRP request.');
    }

    if ($method !== 'GET') {
      $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($body === false) {
        throw new \Exception('Failed to encode MRP payload as JSON.');
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
      throw new \Exception('MRP request failed: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    if ($httpCode >= 400) {
      throw new \Exception('MRP HTTP ' . $httpCode . ': ' . $this->extractErrorMessage($decoded, (string) $response));
    }
    if ($httpCode === 204) {
      return [];
    }
    if (!is_array($decoded)) {
      throw new \Exception('MRP returned an invalid JSON response.');
    }

    return $decoded;
  }

  private function extractErrorMessage(mixed $decoded, string $rawResponse): string
  {
    if (is_array($decoded)) {
      $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
      $code = trim((string) ($error['code'] ?? ''));
      $message = trim((string) ($error['message'] ?? ''));
      $correlationId = trim((string) ($error['correlation_id'] ?? $error['correlationId'] ?? ''));

      if ($message !== '') {
        return ($code !== '' ? '[' . $code . '] ' : '')
          . $message
          . ($correlationId !== '' ? ' (correlation ID ' . $correlationId . ')' : '');
      }

      $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($encoded !== false) {
        return $encoded;
      }
    }

    return trim($rawResponse) !== '' ? trim($rawResponse) : 'Unknown error';
  }
}
