<?php
declare(strict_types=1);

namespace App\Db;

use PDO;

final class Db {
  /**
   * Factory: build Db from config array (src/Config/config.php).
   */
  public static function fromConfig(array $config): self
  {
    $dbCfg = $config['db'] ?? $config;
    $host = (string)($dbCfg['host'] ?? '127.0.0.1');
    $port = (int)($dbCfg['port'] ?? 3306);
    $name = (string)($dbCfg['name'] ?? (getenv('DB_NAME') ?: ''));
    $user = (string)($dbCfg['user'] ?? (getenv('DB_USER') ?: ''));
    $pass = (string)($dbCfg['pass'] ?? (getenv('DB_PASS') ?: ''));

    if ($name === '' || $user === '') {
      throw new \RuntimeException('DB config missing name/user.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    $pdo = new \PDO($dsn, $user, $pass, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return new self($pdo);
  }

  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchValue(string $sql, array $params = []) {
    $st = $this->runStatement($sql, $params);
    return $st->fetchColumn();
  }

  public function insert(string $table, array $data): int {
    if (str_starts_with(ltrim($table), 'INSERT') || str_contains($table, ' ')) {
      $this->runStatement($table, $data);
      return (int)$this->pdo->lastInsertId();
    }

    $cols = array_keys($data);
    $sql = "INSERT INTO {$table} (`" . implode('`,`',$cols) . "`) VALUES (:" . implode(',:',$cols) . ")";
    $this->runStatement($sql, $data);
    return (int)$this->pdo->lastInsertId();
  }

  public function select(string $sql, array $params = []): array
  {
    $st = $this->runStatement($sql, $params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public function one(string $sql, array $params = []): ?array
  {
    $st = $this->runStatement($sql, $params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
  }

  public function scalar(string $sql, array $params = [])
  {
    return $this->fetchValue($sql, $params);
  }

  public function execute(string $sql, array $params = []): int
  {
    $st = $this->runStatement($sql, $params);
    return $st->rowCount();
  }

  private function runStatement(string $sql, array $params = []): \PDOStatement
  {
    $st = $this->pdo->prepare($sql);
    try {
      if ($params === []) {
        $st->execute();
      } else {
        $st->execute($params);
      }
    } catch (\PDOException $e) {
      $message = $this->formatPdoException($e, $sql, $params);
      throw new \PDOException($message, (int)$e->getCode(), $e);
    }
    return $st;
  }

  private function formatPdoException(\PDOException $e, string $sql, array $params): string
  {
    $errorInfo = '';
    if (isset($e->errorInfo) && is_array($e->errorInfo)) {
      $errorInfo = implode(' | ', array_map('strval', $e->errorInfo));
    }
    $paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $message = $e->getMessage();
    if ($errorInfo !== '') {
      $message .= " | ErrorInfo: {$errorInfo}";
    }
    $message .= " | SQL: {$sql}";
    if ($params !== []) {
      $message .= " | Params: {$paramsJson}";
    }
    return $message;
  }

  public function tx(callable $fn)
  {
    $attempt = 0;
    $maxRetries = 2;

    while (true) {
      $this->pdo->beginTransaction();
      try {
        $result = $fn($this);
        $this->pdo->commit();
        return $result;
      } catch (\Throwable $e) {
        if ($this->pdo->inTransaction()) {
          $this->pdo->rollBack();
        }

        $attempt++;
        $errorCode = null;
        if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
          $errorCode = (int)$e->errorInfo[1];
        }

        $shouldRetry = $attempt <= $maxRetries && in_array($errorCode, [1205, 1213], true);
        if ($shouldRetry) {
          usleep(100000 * $attempt);
          continue;
        }

        throw $e;
      }
    }
  }

  public function audit(
    ?int $corpId,
    ?int $actorUserId,
    ?int $actorCharacterId,
    string $action,
    ?string $entityTable,
    ?string $entityPk,
    $before,
    $after,
    ?string $ipAddress,
    ?string $userAgent
  ): void {
    $beforeJson = $before !== null ? self::jsonEncode($before) : null;
    $afterJson = $after !== null ? self::jsonEncode($after) : null;
    $ipBin = $ipAddress ? inet_pton($ipAddress) : null;

    $this->execute(
      "INSERT INTO audit_log
        (corp_id, actor_user_id, actor_character_id, action, entity_table, entity_pk, before_json, after_json, ip_address, user_agent)
       VALUES
        (:corp_id, :actor_user_id, :actor_character_id, :action, :entity_table, :entity_pk, :before_json, :after_json, :ip_address, :user_agent)",
      [
        'corp_id' => $corpId,
        'actor_user_id' => $actorUserId,
        'actor_character_id' => $actorCharacterId,
        'action' => $action,
        'entity_table' => $entityTable,
        'entity_pk' => $entityPk,
        'before_json' => $beforeJson,
        'after_json' => $afterJson,
        'ip_address' => $ipBin,
        'user_agent' => $userAgent,
      ]
    );
  }

  public function upsertEntity(int $entityId, string $entityType, string $name, ?array $extraJson = null, ?string $category = null, string $source = 'esi'): void
  {
    $this->execute(
      "INSERT INTO eve_entity
        (entity_id, entity_type, name, category, extra_json, source, last_seen_at)
       VALUES
        (:entity_id, :entity_type, :name, :category, :extra_json, :source, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
        name=VALUES(name),
        category=VALUES(category),
        extra_json=VALUES(extra_json),
        source=VALUES(source),
        last_seen_at=UTC_TIMESTAMP()",
      [
        'entity_id' => $entityId,
        'entity_type' => $entityType,
        'name' => $name,
        'category' => $category,
        'extra_json' => $extraJson !== null ? self::jsonEncode($extraJson) : null,
        'source' => $source,
      ]
    );
  }

  public function esiCacheGet(?int $corpId, string $cacheKeyBin): array
  {
    $row = $this->one(
      "SELECT response_json, etag, expires_at, status_code
         FROM esi_cache
        WHERE corp_id <=> :corp_id AND cache_key = :cache_key
        LIMIT 1",
      [
        'corp_id' => $corpId,
        'cache_key' => $cacheKeyBin,
      ]
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
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(30, $ttlSeconds));
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $responseSha = hash('sha256', $responseJson, true);

    $this->execute(
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
      [
        'corp_id' => $corpId,
        'cache_key' => $cacheKeyBin,
        'http_method' => strtoupper($method),
        'url' => $url,
        'query_json' => $query !== null ? self::jsonEncode($query) : null,
        'body_json' => $body !== null ? self::jsonEncode($body) : null,
        'status_code' => $statusCode,
        'etag' => $etag,
        'last_modified' => $lastModified,
        'expires_at' => $expiresAt,
        'fetched_at' => $fetchedAt,
        'ttl_seconds' => $ttlSeconds,
        'response_json' => $responseJson,
        'response_sha256' => $responseSha,
        'error_text' => $errorText,
      ]
    );
  }

  public static function esiCacheKey(string $method, string $url, ?array $query, ?array $body): string
  {
    $payload = strtoupper($method) . "\n" . $url . "\n";
    $payload .= $query !== null ? self::jsonEncode($query) : '';
    $payload .= "\n";
    $payload .= $body !== null ? self::jsonEncode($body) : '';
    return hash('sha256', $payload, true);
  }

  public static function jsonEncode(
    $value,
    int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
  ): string
  {
    if (($options & JSON_THROW_ON_ERROR) === 0) {
      $options |= JSON_THROW_ON_ERROR;
    }
    return json_encode($value, $options);
  }

  public static function jsonDecode(string $json, $default = null)
  {
    try {
      return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
      if ($default !== null) {
        return $default;
      }
      throw $e;
    }
  }

  public function setConfig(string $key, string $value): void {
    $this->pdo->prepare(
      "INSERT INTO app_config (`key`,`value`) VALUES (?,?)
       ON DUPLICATE KEY UPDATE value=VALUES(value)"
    )->execute([$key,$value]);
  }

  public function getConfig(string $key): ?string {
    return $this->fetchValue("SELECT value FROM app_config WHERE `key`=?", [$key]) ?: null;
  }
}
