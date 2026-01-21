<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Cache/CacheStoreInterface.php';
require_once __DIR__ . '/../src/Cache/CacheTtl.php';
require_once __DIR__ . '/../src/Cache/DbCacheStore.php';
require_once __DIR__ . '/../src/Cache/RedisClientInterface.php';
require_once __DIR__ . '/../src/Cache/RedisCacheStore.php';
require_once __DIR__ . '/../src/Cache/CacheMetricsCollector.php';
require_once __DIR__ . '/../src/Cache/CacheMetricsRepository.php';
require_once __DIR__ . '/../src/Cache/WriteThroughCacheStore.php';

use App\Cache\CacheMetricsCollector;
use App\Cache\CacheTtl;
use App\Cache\DbCacheStore;
use App\Cache\RedisCacheStore;
use App\Cache\RedisClientInterface;
use App\Cache\WriteThroughCacheStore;
use App\Db\Db;

class ArrayRedisClient implements RedisClientInterface
{
  private array $store = [];
  private array $expires = [];

  public function get(string $key): ?string
  {
    if (!array_key_exists($key, $this->store)) {
      return null;
    }
    $expiresAt = $this->expires[$key] ?? null;
    if ($expiresAt !== null && $expiresAt <= time()) {
      unset($this->store[$key], $this->expires[$key]);
      return null;
    }
    return $this->store[$key];
  }

  public function setex(string $key, int $ttlSeconds, string $value): bool
  {
    $this->store[$key] = $value;
    $this->expires[$key] = time() + $ttlSeconds;
    return true;
  }

  public function del(string $key): void
  {
    unset($this->store[$key], $this->expires[$key]);
  }

  public function getExpiry(string $key): ?int
  {
    return $this->expires[$key] ?? null;
  }
}

class FailingRedisClient implements RedisClientInterface
{
  public function get(string $key): ?string
  {
    return null;
  }

  public function setex(string $key, int $ttlSeconds, string $value): bool
  {
    throw new RuntimeException('Redis write failure.');
  }

  public function del(string $key): void
  {
    throw new RuntimeException('Redis delete failure.');
  }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Db($pdo);

$pdo->exec("CREATE TABLE esi_cache (
  cache_id INTEGER PRIMARY KEY AUTOINCREMENT,
  corp_id INTEGER NULL,
  cache_key BLOB NOT NULL,
  http_method TEXT NOT NULL,
  url TEXT NOT NULL,
  query_json TEXT NULL,
  body_json TEXT NULL,
  status_code INTEGER NOT NULL,
  etag TEXT NULL,
  last_modified TEXT NULL,
  expires_at TEXT NOT NULL,
  fetched_at TEXT NOT NULL,
  ttl_seconds INTEGER NOT NULL,
  response_json TEXT NOT NULL,
  response_sha256 BLOB NOT NULL,
  error_text TEXT NULL,
  UNIQUE (corp_id, cache_key)
)");

$config = [
  'cache' => [
    'metrics' => [
      'enabled' => true,
      'flush_interval_seconds' => 60,
    ],
  ],
];
$metrics = CacheMetricsCollector::fromConfig($config);

$resetDb = static function () use ($pdo): void {
  $pdo->exec('DELETE FROM esi_cache');
};

$cacheKey = hash('sha256', 'cache-test', true);

// a) DB write happens even if Redis write fails
$resetDb();
$metrics->reset();
$dbStore = new DbCacheStore($db);
$redisFailStore = new RedisCacheStore(new FailingRedisClient(), 'test:');
$store = new WriteThroughCacheStore($dbStore, $redisFailStore, $metrics);
$store->put(null, $cacheKey, 'GET', 'https://example.test', null, null, 200, 'etag', null, 60, '{"ok":true}');
$row = $db->one('SELECT COUNT(*) AS total FROM esi_cache');
if ((int)($row['total'] ?? 0) !== 1) {
  throw new RuntimeException('Expected DB write even when Redis fails.');
}
$metricSnapshot = $metrics->snapshot();
if (($metricSnapshot['cache_set_total'] ?? 0) !== 1 || ($metricSnapshot['cache_set_redis_fail'] ?? 0) !== 1) {
  throw new RuntimeException('Expected cache_set_total and cache_set_redis_fail to increment.');
}

// b) Redis miss repopulates from DB
$resetDb();
$metrics->reset();
$redisClient = new ArrayRedisClient();
$redisStore = new RedisCacheStore($redisClient, 'test:');
$dbStore->put(null, $cacheKey, 'GET', 'https://example.test', null, null, 200, 'etag', null, 120, '{"ok":true}');
$store = new WriteThroughCacheStore($dbStore, $redisStore, $metrics);
$hit = $store->get(null, $cacheKey);
if (empty($hit['hit'])) {
  throw new RuntimeException('Expected DB hit after Redis miss.');
}
$keyName = 'test:shared:' . bin2hex($cacheKey);
if ($redisClient->get($keyName) === null) {
  throw new RuntimeException('Expected Redis repopulation after DB hit.');
}
$metricSnapshot = $metrics->snapshot();
if (($metricSnapshot['cache_get_total'] ?? 0) !== 1 || ($metricSnapshot['cache_get_hit_db'] ?? 0) !== 1) {
  throw new RuntimeException('Expected cache_get_total and cache_get_hit_db to increment.');
}

// c) TTL aligns to expires_at
$resetDb();
$metrics->reset();
$redisClient = new ArrayRedisClient();
$redisStore = new RedisCacheStore($redisClient, 'test:');
$store = new WriteThroughCacheStore($dbStore, $redisStore, $metrics);
$store->put(null, $cacheKey, 'GET', 'https://example.test', null, null, 200, 'etag', null, 90, '{"ok":true}');
$row = $db->one('SELECT expires_at FROM esi_cache LIMIT 1');
$expiresAt = (string)($row['expires_at'] ?? '');
$expectedTtl = CacheTtl::ttlFromExpiresAt($expiresAt);
$keyName = 'test:shared:' . bin2hex($cacheKey);
$redisExpiry = $redisClient->getExpiry($keyName);
if ($redisExpiry === null) {
  throw new RuntimeException('Expected Redis expiry timestamp to be set.');
}
$actualTtl = $redisExpiry - time();
if ($actualTtl < ($expectedTtl - 1) || $actualTtl > ($expectedTtl + 1)) {
  throw new RuntimeException('Expected Redis TTL to align with DB expires_at.');
}

// d) Metrics counters increment correctly for redis hit/db hit
$resetDb();
$metrics->reset();
$redisClient = new ArrayRedisClient();
$redisStore = new RedisCacheStore($redisClient, 'test:');
$store = new WriteThroughCacheStore($dbStore, $redisStore, $metrics);
$store->put(null, $cacheKey, 'GET', 'https://example.test', null, null, 200, 'etag', null, 120, '{"ok":true}');
$store->get(null, $cacheKey);
$metricSnapshot = $metrics->snapshot();
if (($metricSnapshot['cache_get_total'] ?? 0) !== 1 || ($metricSnapshot['cache_get_hit_redis'] ?? 0) !== 1) {
  throw new RuntimeException('Expected cache_get_total and cache_get_hit_redis to increment.');
}

echo "Cache write-through tests passed.\n";
