<?php

namespace Hubleto\App\External\WaiBlue\Einvoice;

class Providers
{
  public function __construct(
    private Loader $app,
  ) {
  }

  public function getProviderDefinitions(): array
  {
    return [
      'epostak' => [
        'name' => 'EPOSTAK',
        'description' => 'Peppol access point for sending and receiving electronic invoices.',
        'logoUrl' => 'https://epostak.sk/email-logo.png',
        'available' => true,
        'providerClass' => Providers\EpostakProvider::class,
        'fields' => [
          'baseUrl' => ['label' => 'API base URL', 'type' => 'text', 'default' => ''],
          'clientId' => ['label' => 'OAuth client ID', 'type' => 'text', 'default' => ''],
          'clientSecret' => ['label' => 'OAuth client secret', 'type' => 'password', 'default' => ''],
          'receiverScheme' => ['label' => 'Default receiver Peppol scheme', 'type' => 'text', 'default' => ''],
        ],
      ],
      'qvalia' => [
        'name' => 'Qvalia',
        'description' => 'Peppol access point for sending and receiving electronic invoices.',
        'logoUrl' => 'https://qvalia.com/app/uploads/2024/08/Logo-Icon-Indigo-transparent.png',
        'available' => true,
        'providerClass' => Providers\QvaliaProvider::class,
        'fields' => [
          'baseUrl' => ['label' => 'API base URL', 'type' => 'text', 'default' => ''],
          'apiKey' => ['label' => 'API key', 'type' => 'password', 'default' => ''],
          'accountRegNo' => ['label' => 'AccountRegNo', 'type' => 'text', 'default' => ''],
        ],
      ],
      'fitek' => [
        'name' => 'Fitek',
        'description' => 'Electronic invoice exchange through the FitekIN platform.',
        'logoUrl' => 'https://fitek.com/wp-content/uploads/2025/04/logo-fitek-dark.svg',
        'available' => true,
        'providerClass' => Providers\FitekProvider::class,
        'fields' => [
          'baseUrl' => ['label' => 'API base URL', 'type' => 'text', 'default' => 'https://fitekin.fitek.com'],
          'integratorId' => ['label' => 'Integrator ID', 'type' => 'text', 'default' => ''],
          'authorizationToken' => ['label' => 'Authorization token', 'type' => 'password', 'default' => ''],
        ],
      ],
      'mrp' => [
        'name' => 'MRP Digitálny poštár',
        'description' => 'Electronic invoice exchange through the MRP Peppol access point.',
        'logoUrl' => 'https://mrp.sk/images/MRP/logo/logo.svg',
        'available' => true,
        'providerClass' => Providers\MrpProvider::class,
        'fields' => [
          'baseUrl' => ['label' => 'API base URL', 'type' => 'text', 'default' => 'https://test.ap.mrp.sk'],
          'clientId' => ['label' => 'Client ID', 'type' => 'text', 'default' => ''],
          'clientSecret' => ['label' => 'Client secret', 'type' => 'password', 'default' => ''],
          'participantId' => ['label' => 'Identifier', 'type' => 'text', 'default' => ''],
        ],
      ],
    ];
  }

  public function getProviderName(): string
  {
    $providerName = strtolower(trim($this->app->configAsString('providerName')));
    if ($providerName === '') {
      $providerName = strtolower(trim($this->app->configAsString('provider', 'epostak')));
    }

    return isset($this->getProviderDefinitions()[$providerName]) ? $providerName : 'epostak';
  }

  public function getProviderConfigs(): array
  {
    $providerConfigs = json_decode($this->app->configAsString('providerConfigs'), true);
    if (is_array($providerConfigs)) {
      return array_intersect_key($providerConfigs, $this->getProviderDefinitions());
    }

    $legacyConfig = [
      'baseUrl' => $this->app->configAsString('baseUrl'),
      'clientId' => $this->app->configAsString('clientId'),
      'clientSecret' => $this->app->configAsString('clientSecret'),
      'receiverScheme' => $this->app->configAsString('receiverScheme'),
    ];

    return array_filter($legacyConfig, fn ($value) => $value !== '') === []
      ? []
      : ['epostak' => $legacyConfig];
  }

  public function providerConfigAsString(
    string $configName,
    string $defaultValue = '',
    ?string $providerName = null,
  ): string {
    $providerConfigs = $this->getProviderConfigs();
    $providerName = strtolower(trim((string) ($providerName ?? $this->getProviderName())));
    return (string) ($providerConfigs[$providerName][$configName] ?? $defaultValue);
  }

  public function saveProviderConfigValue(string $configName, string $value): void
  {
    $providerConfigs = $this->getProviderConfigs();
    $providerConfigs[$this->getProviderName()][$configName] = $value;
    $providerConfigsJson = json_encode($providerConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($providerConfigsJson === false) {
      throw new \Exception($this->app->translate('Failed to encode provider configuration.'));
    }

    $this->app->setConfigAsString('providerConfigs', $providerConfigsJson);
    $this->app->saveConfig('providerConfigs', $providerConfigsJson);
  }
}
