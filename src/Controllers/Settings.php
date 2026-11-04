<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Controllers;

class Settings extends \Hubleto\Erp\Controller
{
  public function getBreadcrumbs(): array
  {
    return array_merge(parent::getBreadcrumbs(), [
      ['url' => 'einvoice', 'content' => $this->translate('e-Invoice')],
      ['url' => 'settings/einvoice', 'content' => $this->translate('Providers')],
    ]);
  }

  public function prepareView(): void
  {
    parent::prepareView();

    /** @var \Hubleto\App\External\WaiBlue\Einvoice\Loader $einvoiceApp */
    $einvoiceApp = $this->appManager()->getApp(\Hubleto\App\External\WaiBlue\Einvoice\Loader::class);
    $providers = $einvoiceApp->providers();
    $providerDefinitions = $providers->getProviderDefinitions();
    $settingsChanged = $this->router()->urlParamAsBool('settingsChanged');

    if ($settingsChanged) {
      $providerName = strtolower(trim((string) $this->router()->urlParamAsString('providerName')));
      if (!isset($providerDefinitions[$providerName]) || !($providerDefinitions[$providerName]['available'] ?? false)) {
        throw new \Exception($this->translate('Unsupported e-invoice provider.'));
      }

      $providerConfigs = $providers->getProviderConfigs();
      $currentProviderConfig = $providerConfigs[$providerName] ?? [];
      $providerConfig = $currentProviderConfig;

      foreach ($providerDefinitions[$providerName]['fields'] as $fieldName => $field) {
        $value = trim((string) $this->router()->urlParamAsString($fieldName));
        if (($field['type'] ?? 'text') === 'password' && $value === '') {
          $value = (string) ($currentProviderConfig[$fieldName] ?? '');
        } elseif ($value === '') {
          $value = (string) ($field['default'] ?? '');
        }
        $providerConfig[$fieldName] = $value;
      }

      $providerConfigs[$providerName] = $providerConfig;

      $providerConfigsJson = json_encode($providerConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($providerConfigsJson === false) {
        throw new \Exception($this->translate('Failed to encode provider configuration.'));
      }

      $einvoiceApp->setConfigAsString('providerName', $providerName);
      $einvoiceApp->saveConfig('providerName', $providerName);
      $einvoiceApp->setConfigAsString('providerConfigs', $providerConfigsJson);
      $einvoiceApp->saveConfig('providerConfigs', $providerConfigsJson);

      foreach (['provider', 'baseUrl', 'clientId', 'clientSecret', 'receiverScheme'] as $legacyConfigName) {
        $einvoiceApp->config()->delete($einvoiceApp->getFullConfigPath($legacyConfigName));
      }

      $this->viewParams['settingsSaved'] = true;
    }

    $activeProviderName = $providers->getProviderName();
    $providerName = strtolower(trim((string) $this->router()->urlParamAsString('providerConfig')));
    if (!isset($providerDefinitions[$providerName])) {
      $providerName = $activeProviderName;
    }
    $providerConfigs = $providers->getProviderConfigs();
    $this->viewParams['providerDefinitions'] = $providerDefinitions;
    $this->viewParams['activeProviderName'] = $activeProviderName;
    $this->viewParams['providerName'] = $providerName;
    $this->viewParams['providerDefinition'] = $providerDefinitions[$providerName];
    $this->viewParams['providerConfig'] = $providerConfigs[$providerName] ?? [];
    $this->setView('@Hubleto:App:External:WaiBlue:Einvoice/Settings.twig');
  }
}
