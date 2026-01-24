-- Cache metrics aggregation + admin cache indexes

CREATE TABLE IF NOT EXISTS cache_metrics (
  metric_id                   TINYINT UNSIGNED NOT NULL,
  cache_get_total             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_get_hit_redis         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_get_hit_db            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_set_total             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_set_redis_fail        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_get_db_time_total_ms  DOUBLE NOT NULL DEFAULT 0,
  cache_get_db_time_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_cache_corp_fetched ON esi_cache (corp_id, fetched_at);
CREATE INDEX IF NOT EXISTS idx_cache_method ON esi_cache (http_method);
CREATE INDEX IF NOT EXISTS idx_cache_corp_method ON esi_cache (corp_id, http_method);
