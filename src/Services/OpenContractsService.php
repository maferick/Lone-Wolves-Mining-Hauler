<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache\CacheStoreFactory;
use App\Cache\CacheStoreInterface;
use App\Db\Db;

final class OpenContractsService
{
  private const OPEN_STATUSES = [
    'in_queue',
    'posted',
    'submitted',
    'accepted',
  ];
  private const ACTIVE_ASSIGNMENT_STATUSES = [
    'assigned',
    'in_transit',
  ];
  private CacheStoreInterface $cacheStore;
  private int $limitMin;
  private int $limitMax;
  private float $volumeMin;
  private float $volumeMax;
  private float $rewardMin;
  private float $rewardMax;
  private bool $cacheEnabled;
  private int $cacheTtlSeconds;

  public function __construct(private Db $db, private array $config = [])
  {
    $this->cacheStore = CacheStoreFactory::fromConfig($db, $config);
    $openConfig = $config['open_contracts'] ?? [];
    $this->limitMin = (int)($openConfig['limit_min'] ?? 1);
    $this->limitMax = (int)($openConfig['limit_max'] ?? 10);
    $this->volumeMin = (float)($openConfig['volume_min'] ?? 0);
    $this->volumeMax = (float)($openConfig['volume_max'] ?? 100000000);
    $this->rewardMin = (float)($openConfig['reward_min'] ?? 0);
    $this->rewardMax = (float)($openConfig['reward_max'] ?? 100000000000);
    $this->cacheEnabled = (bool)($openConfig['cache_enabled'] ?? true);
    $this->cacheTtlSeconds = (int)($openConfig['cache_ttl_seconds'] ?? 15);
  }

  public function listOpenContracts(int $corpId, array $filters = [], int $limit = 10, int $offset = 0): array
  {
    $limit = max($this->limitMin, min($this->limitMax, $limit));
    $offset = max(0, $offset);
    $filters = $this->normalizeFilters($filters);

    $cacheKey = Db::esiCacheKey('GET', 'open_contracts', [
      'corp_id' => $corpId,
      'filters' => $filters,
      'limit' => $limit,
      'offset' => $offset,
    ], null);
    if ($this->cacheEnabled && $this->cacheTtlSeconds > 0) {
      $cached = $this->cacheStore->get($corpId, $cacheKey);
      if (!empty($cached['hit']) && !empty($cached['json'])) {
        $expiresAt = $cached['expires_at'] ?? null;
        $statusCode = isset($cached['status_code']) ? (int)$cached['status_code'] : 0;
        if ($expiresAt && $statusCode > 0 && $statusCode < 400) {
          $expiresTs = strtotime((string)$expiresAt);
          if ($expiresTs !== false && $expiresTs > time()) {
            $decoded = Db::jsonDecode($cached['json'], []);
            if (is_array($decoded)) {
              return $decoded;
            }
          }
        }
      }
    }

    $params = [
      'cid' => $corpId,
      'limit' => $limit,
      'offset' => $offset,
    ];

    $statusPlaceholders = [];
    foreach (self::OPEN_STATUSES as $index => $status) {
      $key = 'status' . $index;
      $statusPlaceholders[] = ':' . $key;
      $params[$key] = $status;
    }

    $conditions = [
      'r.corp_id = :cid',
      'r.status IN (' . implode(', ', $statusPlaceholders) . ')',
      'a.assignment_id IS NULL',
    ];

    if (!empty($filters['priority'])) {
      $conditions[] = '(r.route_profile = :priority OR r.route_policy = :priority)';
      $params['priority'] = $filters['priority'];
    }

    if (isset($filters['min_volume'])) {
      $conditions[] = 'r.volume_m3 >= :min_volume';
      $params['min_volume'] = (float)$filters['min_volume'];
    }

    if (isset($filters['max_volume'])) {
      $conditions[] = 'r.volume_m3 <= :max_volume';
      $params['max_volume'] = (float)$filters['max_volume'];
    }

    if (isset($filters['min_reward'])) {
      $conditions[] = 'r.reward_isk >= :min_reward';
      $params['min_reward'] = (float)$filters['min_reward'];
    }

    if (!empty($filters['pickup_system'])) {
      $conditions[] = 'LOWER(COALESCE(fs.system_name, fs_station.system_name, fs_structure.system_name, \'\')) = :pickup_system';
      $params['pickup_system'] = $filters['pickup_system'];
    }

    if (!empty($filters['drop_system'])) {
      $conditions[] = 'LOWER(COALESCE(ts.system_name, ts_station.system_name, ts_structure.system_name, \'\')) = :drop_system';
      $params['drop_system'] = $filters['drop_system'];
    }

    $rows = $this->db->select(
      "SELECT r.request_id,
              r.request_key,
              r.status,
              r.title,
              r.volume_m3,
              r.collateral_isk,
              r.reward_isk,
              r.route_profile,
              r.route_policy,
              r.date_expired,
              r.created_at,
              r.from_location_type,
              r.to_location_type,
              fs.system_name AS from_system_name,
              ts.system_name AS to_system_name,
              f_station.station_name AS from_station_name,
              t_station.station_name AS to_station_name,
              f_structure.structure_name AS from_structure_name,
              t_structure.structure_name AS to_structure_name,
              fs_station.system_name AS from_station_system,
              ts_station.system_name AS to_station_system,
              fs_structure.system_name AS from_structure_system,
              ts_structure.system_name AS to_structure_system
         FROM haul_request r
         LEFT JOIN haul_assignment a
           ON a.request_id = r.request_id
          AND a.status IN ('" . implode("','", self::ACTIVE_ASSIGNMENT_STATUSES) . "')
         LEFT JOIN eve_system fs
           ON fs.system_id = r.from_location_id
          AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts
           ON ts.system_id = r.to_location_id
          AND r.to_location_type = 'system'
         LEFT JOIN eve_station f_station
           ON f_station.station_id = r.from_location_id
          AND r.from_location_type = 'station'
         LEFT JOIN eve_station t_station
           ON t_station.station_id = r.to_location_id
          AND r.to_location_type = 'station'
         LEFT JOIN eve_structure f_structure
           ON f_structure.structure_id = r.from_location_id
          AND r.from_location_type = 'structure'
         LEFT JOIN eve_structure t_structure
           ON t_structure.structure_id = r.to_location_id
          AND r.to_location_type = 'structure'
         LEFT JOIN eve_system fs_station
           ON fs_station.system_id = f_station.system_id
         LEFT JOIN eve_system ts_station
           ON ts_station.system_id = t_station.system_id
         LEFT JOIN eve_system fs_structure
           ON fs_structure.system_id = f_structure.system_id
         LEFT JOIN eve_system ts_structure
           ON ts_structure.system_id = t_structure.system_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY
          CASE LOWER(COALESCE(r.route_profile, r.route_policy, 'normal'))
            WHEN 'high' THEN 2
            WHEN 'normal' THEN 1
            ELSE 0
          END DESC,
          r.created_at ASC,
          r.request_id ASC
        LIMIT :limit OFFSET :offset",
      $params
    );

    $results = [];
    foreach ($rows as $row) {
      $fromType = (string)($row['from_location_type'] ?? '');
      $toType = (string)($row['to_location_type'] ?? '');

      $pickupSystem = (string)($row['from_system_name']
        ?? $row['from_station_system']
        ?? $row['from_structure_system']
        ?? '');
      $dropSystem = (string)($row['to_system_name']
        ?? $row['to_station_system']
        ?? $row['to_structure_system']
        ?? '');
      $pickupStation = $fromType === 'system'
        ? ''
        : (string)($row['from_station_name'] ?? $row['from_structure_name'] ?? '');
      $dropStation = $toType === 'system'
        ? ''
        : (string)($row['to_station_name'] ?? $row['to_structure_name'] ?? '');

      $requestKey = (string)($row['request_key'] ?? '');
      $results[] = [
        'id' => (int)($row['request_id'] ?? 0),
        'code' => $requestKey,
        'pickup_system' => $pickupSystem,
        'pickup_station' => $pickupStation,
        'drop_system' => $dropSystem,
        'drop_station' => $dropStation,
        'volume_m3' => (float)($row['volume_m3'] ?? 0),
        'collateral_isk' => (float)($row['collateral_isk'] ?? 0),
        'reward_isk' => (float)($row['reward_isk'] ?? 0),
        'priority' => (string)($row['route_profile'] ?? $row['route_policy'] ?? 'normal'),
        'risk_tier' => null,
        'expires_at' => (string)($row['date_expired'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'portal_url' => $this->buildRequestUrl($requestKey),
      ];
    }

    if ($this->cacheEnabled && $this->cacheTtlSeconds > 0) {
      $payload = Db::jsonEncode($results);
      $this->cacheStore->put(
        $corpId,
        $cacheKey,
        'GET',
        'open_contracts',
        [
          'corp_id' => $corpId,
          'filters' => $filters,
          'limit' => $limit,
          'offset' => $offset,
        ],
        null,
        200,
        null,
        null,
        $this->cacheTtlSeconds,
        $payload
      );
    }

    return $results;
  }

  private function normalizeFilters(array $filters): array
  {
    $normalized = [];
    if (!empty($filters['priority'])) {
      $normalized['priority'] = strtolower($this->normalizeText((string)$filters['priority']));
    }
    if (isset($filters['min_volume'])) {
      $normalized['min_volume'] = $this->clampNumber((float)$filters['min_volume'], $this->volumeMin, $this->volumeMax);
    }
    if (isset($filters['max_volume'])) {
      $normalized['max_volume'] = $this->clampNumber((float)$filters['max_volume'], $this->volumeMin, $this->volumeMax);
    }
    if (isset($filters['min_reward'])) {
      $normalized['min_reward'] = $this->clampNumber((float)$filters['min_reward'], $this->rewardMin, $this->rewardMax);
    }
    if (!empty($filters['pickup_system'])) {
      $normalized['pickup_system'] = strtolower($this->normalizeText((string)$filters['pickup_system']));
    }
    if (!empty($filters['drop_system'])) {
      $normalized['drop_system'] = strtolower($this->normalizeText((string)$filters['drop_system']));
    }
    if (isset($filters['risk'])) {
      $normalized['risk'] = strtolower($this->normalizeText((string)$filters['risk']));
    }

    if (isset($normalized['min_volume'], $normalized['max_volume']) && $normalized['min_volume'] > $normalized['max_volume']) {
      $normalized['max_volume'] = $normalized['min_volume'];
    }

    return array_filter($normalized, static fn($value) => $value !== '' && $value !== null);
  }

  private function normalizeText(string $value): string
  {
    $value = trim($value);
    return preg_replace('/\s+/', ' ', $value) ?? $value;
  }

  private function clampNumber(float $value, float $min, float $max): float
  {
    return max($min, min($max, $value));
  }

  private function buildRequestUrl(string $requestKey): string
  {
    $requestKey = trim($requestKey);
    if ($requestKey === '') {
      return '';
    }

    $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
    $basePath = rtrim((string)($this->config['app']['base_path'] ?? ''), '/');
    $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
    $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
    $path = ($pathPrefix ?: '') . '/request?request_key=' . urlencode($requestKey);

    return $baseUrl !== '' ? $baseUrl . $path : $path;
  }
}
