<?php
declare(strict_types=1);

namespace App\Cache;

use App\Db\Db;

final class CacheStoreFactory
{
  private static ?CacheStoreInterface $instance = null;

  public static function fromConfig(Db $db, array $config): CacheStoreInterface
  {
    if (self::$instance !== null) {
      return self::$instance;
    }

    $dbStore = new DbCacheStore($db);
    $metrics = CacheMetricsCollector::fromConfig($config);
    CacheMetricsCollector::registerShutdownFlush($db, $config);

    $driver = strtolower((string)($config['cache']['driver'] ?? 'db'));
    $redisStore = null;
    if (in_array($driver, ['write_through', 'tiered'], true)) {
      $redisStore = RedisCacheStore::fromConfig($config);
    }

    self::$instance = new WriteThroughCacheStore($dbStore, $redisStore, $metrics);
    return self::$instance;
  }
}
