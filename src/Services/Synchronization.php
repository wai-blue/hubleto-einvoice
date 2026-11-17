<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use Hubleto\App\External\WaiBlue\Einvoice\Loader;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;

class Synchronization
{
  public function __construct(private Loader $app)
  {
  }

  public function lastResult(): array
  {
    $result = json_decode($this->app->configAsString('synchronization/' . $this->app->providers()->getProviderName()), true);
    return is_array($result) && ($result['context'] ?? '') === $this->context() ? $result : [];
  }

  public function run(bool $documents = true, bool $statuses = true, string $source = 'manual'): array
  {
    $key = hash('sha256', __DIR__ . '|' . $this->app->env()->projectUrl);
    $lock = fopen(sys_get_temp_dir() . '/hubleto-einvoice-' . $key . '.lock', 'c');
    if (!$lock) throw new \Exception('Cannot acquire the e-Invoice synchronization lock.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
      fclose($lock);
      throw new \Exception('e-Invoice synchronization is already running.');
    }

    $previous = $this->lastResult();
    $state = [
      'source' => $source,
      'context' => $this->context(),
      'status' => 'running',
      'startedAt' => date('Y-m-d H:i:s'),
      'finishedAt' => null,
      'lastDocumentSyncAt' => $previous['lastDocumentSyncAt'] ?? null,
      'lastStatusSyncAt' => $previous['lastStatusSyncAt'] ?? null,
      'nextPosition' => $previous['nextPosition'] ?? null,
      'documents' => null,
      'statuses' => null,
      'errors' => [],
    ];
    try {
      $this->save($state);
      $model = $this->app->getModel(Einvoice::class);
      if ($documents) {
        try {
          $position = $state['nextPosition'] ?? [];
          $state['documents'] = $model->syncProviderDocuments(100, is_array($position) ? $position : []);
          $state['nextPosition'] = $state['documents']['nextPosition'];
          $state['lastDocumentSyncAt'] = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
          $state['errors'][] = $e->getMessage();
        }
      }
      if ($statuses) {
        $state['statuses'] = ['checked' => 0, 'failed' => 0];
        try {
          $records = $model->record->where('provider', $this->app->providers()->getProviderName())
            ->where('status', Einvoice::STATUS_SENT)->whereNotNull('transmission_id')->get();
          foreach ($records as $record) {
            try {
              $model->syncProviderStatus((int) $record->id);
              $state['statuses']['checked']++;
            } catch (\Throwable $e) {
              $state['statuses']['failed']++;
              $state['errors'][] = 'e-Invoice #' . $record->id . ': ' . $e->getMessage();
            }
          }
          $state['lastStatusSyncAt'] = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
          $state['errors'][] = $e->getMessage();
        }
      }
      $state['status'] = $state['errors'] !== [] ? 'error'
        : (!empty($state['documents']['warnings']) ? 'warning' : 'success');
      $state['finishedAt'] = date('Y-m-d H:i:s');
      $this->save($state);
      return $state;
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  private function save(array $state): void
  {
    $key = 'synchronization/' . $this->app->providers()->getProviderName();
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $this->app->saveConfig($key, $json);
  }

  private function context(): string
  {
    $providers = $this->app->providers();
    $name = $providers->getProviderName();
    $identity = [$name];
    foreach ($providers->getProviderDefinitions()[$name]['fields'] ?? [] as $key => $field) {
      if (($field['type'] ?? '') !== 'password') {
        $identity[$key] = $providers->providerConfigAsString($key, (string) ($field['default'] ?? ''));
      }
    }
    return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
  }
}
