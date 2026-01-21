<?php
declare(strict_types=1);

namespace App\Cache;

use App\Db\Db;

final class CacheMetricsCollector
{
  private static ?self $instance = null;
  private static bool $shutdownRegistered = false;

  private array $buffer = [
    'cache_get_total' => 0,
    'cache_get_hit_redis' => 0,
    'cache_get_hit_db' => 0,
    'cache_set_total' => 0,
    'cache_set_redis_fail' => 0,
    'cache_get_db_time_total_ms' => 0.0,
    'cache_get_db_time_count' => 0,
  ];
  private int $lastFlushAt = 0;
  private bool $enabled;
  private int $flushInterval;
  private array $config;

  private function __construct(array $config)
  {
    $this->config = $config;
    $metricsCfg = $config['cache']['metrics'] ?? [];
    $this->enabled = (bool)($metricsCfg['enabled'] ?? true);
    $this->flushInterval = max(10, (int)($metricsCfg['flush_interval_seconds'] ?? 60));
  }

  public static function fromConfig(array $config): self
  {
    if (self::$instance === null) {
      self::$instance = new self($config);
    }
    return self::$instance;
  }

  public function recordGetTotal(): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_get_total']++;
  }

  public function recordGetHitRedis(): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_get_hit_redis']++;
  }

  public function recordGetHitDb(): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_get_hit_db']++;
  }

  public function recordSetTotal(): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_set_total']++;
  }

  public function recordSetRedisFail(): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_set_redis_fail']++;
  }

  public function recordDbQueryTime(float $ms): void
  {
    if (!$this->enabled) {
      return;
    }
    $this->buffer['cache_get_db_time_total_ms'] += $ms;
    $this->buffer['cache_get_db_time_count']++;
  }

  public function flushIfDue(Db $db, bool $force = false): void
  {
    if (!$this->enabled) {
      return;
    }
    $now = time();
    if (!$force && ($now - $this->lastFlushAt) < $this->flushInterval) {
      return;
    }

    $delta = $this->buffer;
    $hasData = array_reduce($delta, static fn($carry, $value) => $carry || ((float)$value !== 0.0), false);
    if (!$hasData) {
      RedisDiagnostics::touch($this->config);
      $this->lastFlushAt = $now;
      return;
    }

    try {
      CacheMetricsRepository::applyDelta($db, $delta);
      RedisDiagnostics::touch($this->config);
      $this->buffer = [
        'cache_get_total' => 0,
        'cache_get_hit_redis' => 0,
        'cache_get_hit_db' => 0,
        'cache_set_total' => 0,
        'cache_set_redis_fail' => 0,
        'cache_get_db_time_total_ms' => 0.0,
        'cache_get_db_time_count' => 0,
      ];
      $this->lastFlushAt = $now;
    } catch (\Throwable $e) {
      error_log('[cache-metrics] flush failed: ' . $e->getMessage());
    }
  }

  public function snapshot(): array
  {
    return $this->buffer;
  }

  public function reset(): void
  {
    $this->buffer = [
      'cache_get_total' => 0,
      'cache_get_hit_redis' => 0,
      'cache_get_hit_db' => 0,
      'cache_set_total' => 0,
      'cache_set_redis_fail' => 0,
      'cache_get_db_time_total_ms' => 0.0,
      'cache_get_db_time_count' => 0,
    ];
    $this->lastFlushAt = 0;
  }

  public static function registerShutdownFlush(Db $db, array $config): void
  {
    if (self::$shutdownRegistered) {
      return;
    }
    self::$shutdownRegistered = true;
    register_shutdown_function(static function () use ($db, $config): void {
      self::fromConfig($config)->flushIfDue($db, false);
    });
  }
}
