<?php
declare(strict_types=1);

namespace App\Cache;

use App\Db\Db;

final class CacheMetricsRepository
{
  public static function applyDelta(Db $db, array $delta): void
  {
    $payload = [
      'metric_id' => 1,
      'cache_get_total' => (int)($delta['cache_get_total'] ?? 0),
      'cache_get_hit_redis' => (int)($delta['cache_get_hit_redis'] ?? 0),
      'cache_get_hit_db' => (int)($delta['cache_get_hit_db'] ?? 0),
      'cache_set_total' => (int)($delta['cache_set_total'] ?? 0),
      'cache_set_redis_fail' => (int)($delta['cache_set_redis_fail'] ?? 0),
      'cache_get_db_time_total_ms' => (float)($delta['cache_get_db_time_total_ms'] ?? 0),
      'cache_get_db_time_count' => (int)($delta['cache_get_db_time_count'] ?? 0),
    ];

    $db->execute(
      "INSERT INTO cache_metrics
        (metric_id, cache_get_total, cache_get_hit_redis, cache_get_hit_db, cache_set_total, cache_set_redis_fail,
         cache_get_db_time_total_ms, cache_get_db_time_count)
       VALUES
        (:metric_id, :cache_get_total, :cache_get_hit_redis, :cache_get_hit_db, :cache_set_total, :cache_set_redis_fail,
         :cache_get_db_time_total_ms, :cache_get_db_time_count)
       ON DUPLICATE KEY UPDATE
        cache_get_total = cache_get_total + VALUES(cache_get_total),
        cache_get_hit_redis = cache_get_hit_redis + VALUES(cache_get_hit_redis),
        cache_get_hit_db = cache_get_hit_db + VALUES(cache_get_hit_db),
        cache_set_total = cache_set_total + VALUES(cache_set_total),
        cache_set_redis_fail = cache_set_redis_fail + VALUES(cache_set_redis_fail),
        cache_get_db_time_total_ms = cache_get_db_time_total_ms + VALUES(cache_get_db_time_total_ms),
        cache_get_db_time_count = cache_get_db_time_count + VALUES(cache_get_db_time_count),
        updated_at = UTC_TIMESTAMP()",
      $payload
    );
  }

  public static function getCurrent(Db $db): array
  {
    $row = $db->one(
      "SELECT cache_get_total, cache_get_hit_redis, cache_get_hit_db, cache_set_total, cache_set_redis_fail,
              cache_get_db_time_total_ms, cache_get_db_time_count, updated_at
         FROM cache_metrics
        WHERE metric_id = 1
        LIMIT 1"
    );

    if (!$row) {
      return [
        'cache_get_total' => 0,
        'cache_get_hit_redis' => 0,
        'cache_get_hit_db' => 0,
        'cache_set_total' => 0,
        'cache_set_redis_fail' => 0,
        'cache_get_db_time_total_ms' => 0.0,
        'cache_get_db_time_count' => 0,
        'updated_at' => null,
      ];
    }

    return $row;
  }

  public static function reset(Db $db): void
  {
    $db->execute(
      "INSERT INTO cache_metrics
        (metric_id, cache_get_total, cache_get_hit_redis, cache_get_hit_db, cache_set_total, cache_set_redis_fail,
         cache_get_db_time_total_ms, cache_get_db_time_count)
       VALUES (1, 0, 0, 0, 0, 0, 0, 0)
       ON DUPLICATE KEY UPDATE
        cache_get_total = 0,
        cache_get_hit_redis = 0,
        cache_get_hit_db = 0,
        cache_set_total = 0,
        cache_set_redis_fail = 0,
        cache_get_db_time_total_ms = 0,
        cache_get_db_time_count = 0,
        updated_at = UTC_TIMESTAMP()"
    );
  }
}
