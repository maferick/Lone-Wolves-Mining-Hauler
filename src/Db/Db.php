<?php
declare(strict_types=1);

namespace App\Db;

use PDO;

final class Db {
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
