<?php
declare(strict_types=1);

namespace App\Cache;

final class RedisDiagnostics
{
  private const DEFAULT_TIMEOUT_SECONDS = 0.2;

  public static function isEnabled(array $config): bool
  {
    $driver = strtolower((string)($config['cache']['driver'] ?? 'db'));
    if (!in_array($driver, ['write_through', 'tiered'], true)) {
      return false;
    }

    $host = (string)($config['cache']['redis']['host'] ?? '');
    return $host !== '';
  }

  public static function configSummary(array $config): array
  {
    $redisCfg = $config['cache']['redis'] ?? [];
    return [
      'driver' => strtolower((string)($config['cache']['driver'] ?? 'db')),
      'host' => (string)($redisCfg['host'] ?? ''),
      'port' => (int)($redisCfg['port'] ?? 6379),
      'database' => (int)($redisCfg['database'] ?? 0),
      'prefix' => (string)($redisCfg['prefix'] ?? 'esi_cache:'),
    ];
  }

  public static function ping(array $config, ?float $timeoutSeconds = null): array
  {
    $timeout = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
    try {
      $client = self::clientFromConfig($config, $timeout);
      if ($client === null) {
        return ['status' => 'Unavailable', 'error' => 'Redis host not configured.'];
      }
      $ok = $client->ping();
      return [
        'status' => $ok ? 'OK' : 'Unavailable',
        'error' => $ok ? null : 'Redis ping failed.',
      ];
    } catch (\Throwable $e) {
      return ['status' => 'Unavailable', 'error' => $e->getMessage()];
    }
  }

  public static function touch(array $config, ?float $timeoutSeconds = null): void
  {
    if (!self::isEnabled($config)) {
      return;
    }

    $timeout = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
    try {
      $client = self::clientFromConfig($config, $timeout);
      if ($client === null) {
        return;
      }
      $summary = self::configSummary($config);
      $prefix = (string)($summary['prefix'] ?? 'esi_cache:');
      $key = $prefix . 'diag:ping';
      $client->setex($key, 60, (string)time());
      $client->get($key);
    } catch (\Throwable $e) {
      error_log('[cache] Redis touch failed: ' . $e->getMessage());
    }
  }

  private static function clientFromConfig(array $config, float $timeoutSeconds): ?RedisClientInterface
  {
    $redisCfg = $config['cache']['redis'] ?? [];
    $host = (string)($redisCfg['host'] ?? '');
    if ($host === '') {
      return null;
    }

    $port = (int)($redisCfg['port'] ?? 6379);
    $timeoutCfg = (float)($redisCfg['timeout_seconds'] ?? 1.5);
    $timeout = min($timeoutCfg > 0 ? $timeoutCfg : self::DEFAULT_TIMEOUT_SECONDS, $timeoutSeconds);
    $password = $redisCfg['password'] ?? null;
    $database = (int)($redisCfg['database'] ?? 0);

    return new PhpRedisClient($host, $port, $timeout, $password, $database);
  }
}
