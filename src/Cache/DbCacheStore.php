<?php
declare(strict_types=1);

namespace App\Cache;

use App\Db\Db;

final class DbCacheStore implements CacheStoreInterface
{
  public function __construct(private Db $db)
  {
  }

  public function get(?int $corpId, string $cacheKeyBin): array
  {
    [$where, $params] = $this->buildScopeClause($corpId, $cacheKeyBin);
    $row = $this->db->one(
      "SELECT response_json, etag, expires_at, status_code
         FROM esi_cache
        WHERE {$where}
        LIMIT 1",
      $params
    );

    if (!$row) {
      return ['hit' => false, 'json' => null, 'etag' => null];
    }

    return [
      'hit' => true,
      'json' => $row['response_json'],
      'etag' => $row['etag'],
      'expires_at' => $row['expires_at'],
      'status_code' => $row['status_code'],
    ];
  }

  public function put(
    ?int $corpId,
    string $cacheKeyBin,
    string $method,
    string $url,
    ?array $query,
    ?array $body,
    int $statusCode,
    ?string $etag,
    ?string $lastModified,
    int $ttlSeconds,
    string $responseJson,
    ?string $errorText = null
  ): void {
    $expiresAt = CacheTtl::expiresAt($ttlSeconds);
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $responseSha = hash('sha256', $responseJson, true);

    $payload = [
      'corp_id' => $corpId,
      'cache_key' => $cacheKeyBin,
      'http_method' => strtoupper($method),
      'url' => $url,
      'query_json' => $query !== null ? Db::jsonEncode($query) : null,
      'body_json' => $body !== null ? Db::jsonEncode($body) : null,
      'status_code' => $statusCode,
      'etag' => $etag,
      'last_modified' => $lastModified,
      'expires_at' => $expiresAt,
      'fetched_at' => $fetchedAt,
      'ttl_seconds' => $ttlSeconds,
      'response_json' => $responseJson,
      'response_sha256' => $responseSha,
      'error_text' => $errorText,
    ];

    if ($this->db->driverName() === 'sqlite') {
      $this->db->execute(
        "INSERT INTO esi_cache
          (corp_id, cache_key, http_method, url, query_json, body_json, status_code, etag, last_modified,
           expires_at, fetched_at, ttl_seconds, response_json, response_sha256, error_text)
         VALUES
          (:corp_id, :cache_key, :http_method, :url, :query_json, :body_json, :status_code, :etag, :last_modified,
           :expires_at, :fetched_at, :ttl_seconds, :response_json, :response_sha256, :error_text)
         ON CONFLICT(corp_id, cache_key) DO UPDATE SET
          status_code=excluded.status_code,
          etag=excluded.etag,
          last_modified=excluded.last_modified,
          expires_at=excluded.expires_at,
          fetched_at=excluded.fetched_at,
          ttl_seconds=excluded.ttl_seconds,
          response_json=excluded.response_json,
          response_sha256=excluded.response_sha256,
          error_text=excluded.error_text",
        $payload
      );
      return;
    }

    $this->db->execute(
      "INSERT INTO esi_cache
        (corp_id, cache_key, http_method, url, query_json, body_json, status_code, etag, last_modified,
         expires_at, fetched_at, ttl_seconds, response_json, response_sha256, error_text)
       VALUES
        (:corp_id, :cache_key, :http_method, :url, :query_json, :body_json, :status_code, :etag, :last_modified,
         :expires_at, :fetched_at, :ttl_seconds, :response_json, :response_sha256, :error_text)
       ON DUPLICATE KEY UPDATE
        status_code=VALUES(status_code),
        etag=VALUES(etag),
        last_modified=VALUES(last_modified),
        expires_at=VALUES(expires_at),
        fetched_at=VALUES(fetched_at),
        ttl_seconds=VALUES(ttl_seconds),
        response_json=VALUES(response_json),
        response_sha256=VALUES(response_sha256),
        error_text=VALUES(error_text)",
      $payload
    );
  }

  /**
   * @return array{0: string, 1: array}
   */
  private function buildScopeClause(?int $corpId, string $cacheKeyBin): array
  {
    if ($corpId === null) {
      return [
        'corp_id IS NULL AND cache_key = :cache_key',
        ['cache_key' => $cacheKeyBin],
      ];
    }

    return [
      'corp_id = :corp_id AND cache_key = :cache_key',
      ['corp_id' => $corpId, 'cache_key' => $cacheKeyBin],
    ];
  }
}
