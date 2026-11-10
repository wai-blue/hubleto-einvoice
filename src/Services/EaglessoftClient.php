<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;

class EaglessoftClient
{
  private ?string $accessToken = null;
  private int $accessTokenExpiresAt = 0;
  private ?array $companyContext = null;

  public function __construct(
    private Loader $app,
  ) {
  }

  public function sendDocument(string $category, string $xmlContent): array
  {
    if (!in_array($category, ['invoice', 'credit_note'], true)) {
      throw new \Exception("Unsupported DocNaut document category '{$category}'.");
    }

    return $this->request(
      'POST',
      $this->baseUrl() . '/api/executor/v1/document_send_to_peppol/' . $category,
      ['document.main' => new \CURLStringFile($xmlContent, 'invoice.xml', 'application/xml')],
      $this->headers(),
    );
  }

  public function graph(string $query, array $variables, bool $withCompanyContext = true): array
  {
    $url = trim($this->app->providers()->providerConfigAsString('graphUrl', '', 'eaglessoft'));
    $response = $this->request('POST', rtrim($url !== '' ? $url : 'https://graph-staging.docnaut.com', '/') . '/v1/graphql', [
      'query' => $query,
      'variables' => $variables === [] ? new \stdClass() : $variables,
    ], array_merge(
      $withCompanyContext ? $this->headers() : ['Authorization: Bearer ' . $this->getAccessToken()],
      ['Content-Type: application/json'],
    ));

    if (!empty($response['errors'])) {
      $messages = array_column($response['errors'], 'message');
      throw new \Exception('DocNaut Graph API: ' . implode('; ', $messages));
    }
    if (!is_array($response['data'] ?? null)) {
      throw new \Exception('DocNaut Graph API did not return data.');
    }

    return $response['data'];
  }

  public function documentXml(string $documentId): string
  {
    $response = $this->request(
      'GET',
      $this->baseUrl() . '/api/management/v1/files/' . rawurlencode($documentId) . '/download/source',
      null,
      $this->headers(),
    );
    $url = $this->downloadUrl($response);
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
      || parse_url($url, PHP_URL_HOST) === null
      || parse_url($url, PHP_URL_USER) !== null
    ) {
      throw new \Exception('DocNaut did not return a valid HTTPS download URL.');
    }

    // The signed storage URL does not need our OAuth token or company headers.
    return $this->execute('GET', $url, null, ['Accept: application/xml']);
  }

  private function headers(): array
  {
    $context = $this->getCompanyContext();
    return [
      'Authorization: Bearer ' . $this->getAccessToken(),
      'X-Company-Id: ' . $context['companyId'],
      'X-Subscription-Id: ' . $context['subscriptionId'],
    ];
  }

  private function getCompanyContext(): array
  {
    if ($this->companyContext !== null) {
      return $this->companyContext;
    }

    // Like the portal, discover the company using only the service client's token.
    $data = $this->graph(<<<'GRAPHQL'
      query ConnectedCompany($limit: Int!) {
        company(where: {is_active: {_eq: true}}, limit: $limit) {
          id
          subscription { id }
        }
      }
      GRAPHQL, ['limit' => 2], false);
    $companies = $data['company'] ?? [];
    if (!is_array($companies) || $companies === []) {
      throw new \Exception('DocNaut did not return an active company for this service client. Check its access in the provider portal.');
    }
    if (count($companies) !== 1) {
      throw new \Exception('DocNaut returned multiple companies. Use a service client assigned to a single company.');
    }
    $companyId = trim((string) ($companies[0]['id'] ?? ''));
    $subscriptionId = trim((string) ($companies[0]['subscription']['id'] ?? ''));
    if ($companyId === '' || $subscriptionId === '') {
      throw new \Exception('DocNaut did not return the company and subscription identifiers.');
    }

    return $this->companyContext = [
      'companyId' => $companyId,
      'subscriptionId' => $subscriptionId,
    ];
  }

  private function downloadUrl(array $response): string
  {
    // Management API leaves Result.data untyped; match the portal's URL resolver.
    $queue = [$response];
    while ($queue !== []) {
      $item = array_shift($queue);
      foreach (['url', 'downloadUrl', 'download_url', 'presignedUrl', 'presigned_url'] as $key) {
        if (is_string($item[$key] ?? null) && trim($item[$key]) !== '') {
          return trim($item[$key]);
        }
      }
      foreach (['data', 'result'] as $key) {
        if (is_array($item[$key] ?? null)) {
          $queue[] = $item[$key];
        }
      }
    }

    return '';
  }

  private function getAccessToken(): string
  {
    if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
      return $this->accessToken;
    }

    $response = $this->request(
      'POST',
      'https://sso.eaglessoft.com/realms/docnaut/protocol/openid-connect/token',
      http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $this->requiredConfig('clientId', 'Client ID'),
        'client_secret' => $this->requiredConfig('clientSecret', 'Client secret'),
      ]),
      ['Content-Type: application/x-www-form-urlencoded'],
    );
    $token = trim((string) ($response['access_token'] ?? ''));
    if ($token === '') {
      throw new \Exception('DocNaut token response did not include access_token.');
    }

    $this->accessToken = $token;
    $this->accessTokenExpiresAt = time() + max(0, (int) ($response['expires_in'] ?? 0) - 30);
    return $token;
  }

  private function requiredConfig(string $key, string $label): string
  {
    $value = trim($this->app->providers()->providerConfigAsString($key, '', 'eaglessoft'));
    if ($value === '') {
      throw new \Exception("DocNaut {$label} must be configured in settings.");
    }

    return $value;
  }

  private function baseUrl(): string
  {
    $url = trim($this->app->providers()->providerConfigAsString('baseUrl', '', 'eaglessoft'));
    return rtrim($url !== '' ? $url : 'https://portal-staging.docnaut.com', '/');
  }

  private function request(string $method, string $url, array|string|null $body, array $headers): array
  {
    $response = $this->execute($method, $url, $body, array_merge(['Accept: application/json'], $headers));
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
      throw new \Exception('DocNaut returned an invalid JSON response.');
    }
    if (($decoded['success'] ?? true) === false) {
      throw new \Exception('DocNaut: ' . $this->errorMessage($decoded));
    }

    return $decoded;
  }

  private function execute(string $method, string $url, array|string|null $body, array $headers): string
  {
    $ch = curl_init($url);
    if ($ch === false) {
      throw new \Exception('Failed to initialize cURL for DocNaut request.');
    }

    try {
      if (is_array($body) && in_array('Content-Type: application/json', $headers, true)) {
        $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      }
      curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      ]);
      if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
      }

      $response = curl_exec($ch);
      if ($response === false) {
        throw new \Exception('DocNaut request failed: ' . curl_error($ch));
      }
      $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($httpCode < 200 || $httpCode >= 300) {
        $decoded = json_decode($response, true);
        throw new \Exception('DocNaut HTTP ' . $httpCode . ': ' . (
          is_array($decoded) ? $this->errorMessage($decoded) : 'Unexpected HTTP response.'
        ));
      }

      return $response;
    } finally {
      curl_close($ch);
    }
  }

  private function errorMessage(array $response): string
  {
    $code = trim((string) ($response['errorCode'] ?? $response['code'] ?? $response['error'] ?? ''));
    $message = trim((string) ($response['error_description'] ?? $response['errorMessage'] ?? $response['message'] ?? 'Request failed.'));
    return ($code !== '' ? '[' . $code . '] ' : '') . $message;
  }
}
