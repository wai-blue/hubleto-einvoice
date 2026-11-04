<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Providers;

interface ProviderInterface
{
  public function getCode(): string;

  public function preflight(array $invoiceData, string $xmlContent): array;

  public function canSend(array $preflight): bool;

  public function describePreflight(array $preflight): string;

  public function sendDocument(array $invoiceData, string $xmlContent): array;

  public function syncDocumentStatus(string $transmissionId): array;

  public function listDocuments(string $direction, ?string $cursor = null, int $limit = 500): array;

  public function getDocumentXml(string $transmissionId, string $direction): string;
}
