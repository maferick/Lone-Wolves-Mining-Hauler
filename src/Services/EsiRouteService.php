<?php
declare(strict_types=1);

namespace App\Services;

final class EsiRouteService
{
  private EsiClient $client;
  private array $config;

  public function __construct(EsiClient $client, array $config = [])
  {
    $this->client = $client;
    $this->config = $config;
  }

  public function fetchRouteSystemIds(
    int $fromSystemId,
    int $toSystemId,
    string $mode,
    array $avoidSystemIds,
    ?int $corpId = null
  ): array {
    $mode = $this->normalizeMode($mode);
    $path = sprintf('latest/route/%d/%d/', $fromSystemId, $toSystemId);
    $query = ['flag' => $mode];
    $avoid = array_values(array_filter(array_unique(array_map('intval', $avoidSystemIds))));
    if (!empty($avoid)) {
      $query['avoid'] = $avoid;
    }

    $ttl = (int)($this->config['esi']['cache']['route_ttl_seconds'] ?? $this->config['esi']['cache']['default_ttl_seconds'] ?? 300);

    $response = $this->client->get($path, $query, $corpId, $ttl);
    if ($response['status'] === 404) {
      return [];
    }
    if (!$response['ok']) {
      $status = (int)($response['status'] ?? 0);
      throw new \RuntimeException('ESI route request failed (HTTP ' . $status . ').');
    }

    $json = $response['json'];
    if (!is_array($json)) {
      throw new \RuntimeException('ESI route response was invalid.');
    }

    return array_map('intval', $json);
  }

  private function normalizeMode(string $mode): string
  {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['shortest', 'secure', 'insecure'], true)) {
      return 'shortest';
    }
    return $mode;
  }
}
