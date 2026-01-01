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
    $dbCfg = $config['db'] ?? [];
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

    if ($name === '' || $user === '') {
      throw new \RuntimeException('DB config missing name/user.');
    }
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    return new self($dsn, $user, $pass);
  }

  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchValue(string $sql, array $params = []) {
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
  }

  public function insert(string $table, array $data): int {
    $cols = array_keys($data);
    $sql = "INSERT INTO {$table} (`" . implode('`,`',$cols) . "`) VALUES (:" . implode(',:',$cols) . ")";
    $st = $this->pdo->prepare($sql);
    $st->execute($data);
    return (int)$this->pdo->lastInsertId();
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
