<?php
declare(strict_types=1);

namespace App\Db;

use PDO;
use PDOException;
use Throwable;

/**
 * src/Db/dbfunctions.php
 *
 * Single-entry DB layer: all SQL interactions should run through this file.
 * Goals:
 *  - Centralized PDO creation + transaction wrapper
 *  - Consistent query helpers
 *  - “Name-first” resolution patterns (avoid showing raw IDs in UI)
 *  - ESI cache helpers (esi_cache + eve_entity)
 *
 * Usage:
 *  $db = Db::fromConfig($config['db']);
 *  $rows = $db->select('SELECT ... WHERE id = :id', ['id' => 123]);
 */

final class Db
{
  private PDO $pdo;

  private function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public static function fromConfig(array $dbConfig): self
  {
    $dsn = sprintf(
      'mysql:host=%s;port=%d;dbname=%s;charset=%s',
      $dbConfig['host'],
      (int)$dbConfig['port'],
      $dbConfig['name'],
      $dbConfig['charset'] ?? 'utf8mb4'
    );

    $options = $dbConfig['options'] ?? [];
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);

    // Defensive: ensure strict mode and UTC at connection-level
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    return new self($pdo);
  }

  public function pdo(): PDO
  {
    return $this->pdo;
  }

  // ---------------------------
  // Transaction orchestration
  // ---------------------------

  /**
   * Execute a function inside a DB transaction.
   * Automatically rolls back on exceptions.
   */
  public function tx(callable $fn)
  {
    $this->pdo->beginTransaction();
    try {
      $result = $fn($this);
      $this->pdo->commit();
      return $result;
    } catch (Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  // ---------------------------
  // Query helpers
  // ---------------------------

  public function execute(string $sql, array $params = []): int
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
  }

  public function insert(string $sql, array $params = []): string
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return (string)$this->pdo->lastInsertId();
  }

  public function select(string $sql, array $params = []): array
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
  }

  public function one(string $sql, array $params = []): ?array
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  public function scalar(string $sql, array $params = [])
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false ? null : $val;
  }

  // ---------------------------
  // JSON helpers (safe)
  // ---------------------------

  public static function jsonEncode($value): string
  {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  public static function jsonDecode(?string $json, $default = null)
  {
    if ($json === null || $json === '') return $default;
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

  // ---------------------------
  // ESI Cache (generic)
  // ---------------------------

  /**
   * Compute a stable SHA-256 key for ESI cache entries.
   * Returns binary(32) as raw bytes to store in VARBINARY.
   */
  public static function esiCacheKey(
    string $method,
    string $url,
    ?array $query = null,
    ?array $body = null
  ): string {
    $sig = strtoupper($method) . ' ' . $url . ' ' .
           self::jsonEncode($query ?? []) . ' ' . self::jsonEncode($body ?? []);
    return hash('sha256', $sig, true);
  }

  /**
   * Get cached ESI JSON string if it exists and is not expired.
   * Returns array: ['hit' => bool, 'json' => ?string, 'etag' => ?string]
   */
  public function esiCacheGet(?int $corpId, string $cacheKeyBin): array
  {
    $row = $this->one(
      "SELECT response_json, etag, expires_at
         FROM esi_cache
        WHERE (corp_id <=> :corp_id) AND cache_key = :cache_key
          AND expires_at > UTC_TIMESTAMP()
        LIMIT 1",
      [
        'corp_id' => $corpId,
        'cache_key' => $cacheKeyBin,
      ]
    );

    if (!$row) {
      return ['hit' => false, 'json' => null, 'etag' => null];
    }

    return ['hit' => true, 'json' => $row['response_json'], 'etag' => $row['etag'] ?? null];
  }

  /**
   * Upsert cache record. Store JSON as string in LONGTEXT.
   */
  public function esiCachePut(
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
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $expires = $now->modify('+' . max(1, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s');
    $fetched = $now->format('Y-m-d H:i:s');

    $sha = hash('sha256', $responseJson, true);

    $this->execute(
      "INSERT INTO esi_cache
        (corp_id, cache_key, http_method, url, query_json, body_json, status_code,
         etag, last_modified, expires_at, fetched_at, ttl_seconds, response_json, response_sha256, error_text)
       VALUES
        (:corp_id, :cache_key, :http_method, :url, :query_json, :body_json, :status_code,
         :etag, :last_modified, :expires_at, :fetched_at, :ttl_seconds, :response_json, :response_sha256, :error_text)
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
      [
        'corp_id' => $corpId,
        'cache_key' => $cacheKeyBin,
        'http_method' => strtoupper($method),
        'url' => $url,
        'query_json' => $query ? self::jsonEncode($query) : null,
        'body_json' => $body ? self::jsonEncode($body) : null,
        'status_code' => $statusCode,
        'etag' => $etag,
        'last_modified' => $lastModified,
        'expires_at' => $expires,
        'fetched_at' => $fetched,
        'ttl_seconds' => $ttlSeconds,
        'response_json' => $responseJson,
        'response_sha256' => $sha,
        'error_text' => $errorText,
      ]
    );
  }

  public function esiCachePurgeExpired(): int
  {
    return $this->execute("DELETE FROM esi_cache WHERE expires_at <= UTC_TIMESTAMP()");
  }

  // ---------------------------
  // “Name-first” entity resolution
  // ---------------------------

  /**
   * Upsert a known entity name in eve_entity.
   * Use this after successful ESI name/lookup calls.
   */
  public function upsertEntity(
    int $entityId,
    string $entityType,
    string $name,
    ?array $extra = null,
    string $source = 'esi'
  ): void {
    $this->execute(
      "INSERT INTO eve_entity (entity_id, entity_type, name, extra_json, source, last_seen_at)
       VALUES (:id, :type, :name, :extra, :source, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
         name=VALUES(name),
         extra_json=VALUES(extra_json),
         source=VALUES(source),
         last_seen_at=UTC_TIMESTAMP()",
      [
        'id' => $entityId,
        'type' => $entityType,
        'name' => $name,
        'extra' => $extra ? self::jsonEncode($extra) : null,
        'source' => $source,
      ]
    );
  }

  /**
   * Return a name for an entity ID if we have it; otherwise returns null.
   * UI should prefer calling this and falling back to a placeholder.
   */
  public function getEntityName(int $entityId, string $entityType): ?string
  {
    $row = $this->one(
      "SELECT name FROM eve_entity WHERE entity_id = :id AND entity_type = :type LIMIT 1",
      ['id' => $entityId, 'type' => $entityType]
    );
    return $row['name'] ?? null;
  }

  /**
   * Batch get names for IDs. Returns map[id] => name.
   */
  public function getEntityNames(array $ids, string $entityType): array
  {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) === 0) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->pdo->prepare(
      "SELECT entity_id, name
         FROM eve_entity
        WHERE entity_type = ?
          AND entity_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$entityType], $ids));

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[(int)$row['entity_id']] = $row['name'];
    }
    return $out;
  }

  // ---------------------------
  // Audit logging (one-liner)
  // ---------------------------

  public function audit(
    ?int $corpId,
    ?int $actorUserId,
    ?int $actorCharacterId,
    string $action,
    ?string $entityTable = null,
    $entityPk = null,
    $before = null,
    $after = null,
    ?string $ipAddress = null,
    ?string $userAgent = null
  ): void {
    $ipBin = null;
    if ($ipAddress) {
      // inet_pton handles both IPv4 & IPv6
      $ipBin = @inet_pton($ipAddress) ?: null;
    }

    $this->execute(
      "INSERT INTO audit_log
        (corp_id, actor_user_id, actor_character_id, action, entity_table, entity_pk,
         before_json, after_json, ip_address, user_agent)
       VALUES
        (:corp_id, :actor_user_id, :actor_character_id, :action, :entity_table, :entity_pk,
         :before_json, :after_json, :ip_address, :user_agent)",
      [
        'corp_id' => $corpId,
        'actor_user_id' => $actorUserId,
        'actor_character_id' => $actorCharacterId,
        'action' => $action,
        'entity_table' => $entityTable,
        'entity_pk' => $entityPk !== null ? (string)$entityPk : null,
        'before_json' => $before !== null ? self::jsonEncode($before) : null,
        'after_json' => $after !== null ? self::jsonEncode($after) : null,
        'ip_address' => $ipBin,
        'user_agent' => $userAgent,
      ]
    );
  }
}
