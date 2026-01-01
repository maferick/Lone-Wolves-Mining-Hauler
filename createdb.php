<?php
declare(strict_types=1);

/**
 * createdb.php
 *
 * Bootstrap script to create the MariaDB database + app user using `.env`.
 *
 * What it does:
 *  - Reads .env (or environment variables)
 *  - Connects to MySQL server (no db selected)
 *  - Creates DB (DB_NAME) with utf8mb4
 *  - Creates user (DB_USER) with password (DB_PASS)
 *  - Grants privileges on DB_NAME.*
 *  - OPTIONAL: imports SQL files if you pass --import=/path/to/sql/or/dir
 *
 * Usage:
 *   php createdb.php
 *   php createdb.php --import=../db
 *   php createdb.php --import=../db/001_schema.sql
 *
 * Notes:
 * - This is for dev/staging automation. Tighten GRANTs for production.
 * - Requires: PHP PDO + pdo_mysql extension.
 */

function loadDotEnv(string $path): void {
  if (!file_exists($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (!str_contains($line, '=')) continue;

    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    $v = trim($v, "\"'");

    if ($k !== '' && getenv($k) === false) {
      putenv($k . '=' . $v);
      $_ENV[$k] = $v;
    }
  }
}

function env(string $key, $default = null) {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}

function arg(string $name): ?string {
  global $argv;
  foreach ($argv as $a) {
    if (str_starts_with($a, $name . '=')) return substr($a, strlen($name) + 1);
  }
  return null;
}

loadDotEnv(__DIR__ . '/.env');

$dbHost = (string)env('DB_HOST', '127.0.0.1');
$dbPort = (int)env('DB_PORT', 3306);
$dbName = (string)env('DB_NAME', 'corp_hauling');
$dbUser = (string)env('DB_USER', 'corp_hauling_app');
$dbPass = (string)env('DB_PASS', 'ChangeMe_123!');

$rootUser = (string)env('DB_ROOT_USER', 'root');
$rootPass = (string)env('DB_ROOT_PASS', '');
$rootHost = (string)env('DB_ROOT_HOST', 'localhost'); // default localhost for unix_socket auth
$allowedHost = (string)env('DB_APP_HOST', '%');    // where app user may connect from

$importPath = arg('--import'); // optional

echo "== Corp Hauling DB Bootstrap ==\n";
echo "Server: {$dbHost}:{$dbPort}\n";
echo "Database: {$dbName}\n";
echo "App user: {$dbUser}@{$allowedHost}\n\n";

$dsnHost = $rootHost ?: $dbHost;
$dsn = "mysql:host={$dsnHost};port={$dbPort};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $rootUser, $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  // Create DB
  $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  echo "[OK] Database ensured\n";

  // Create user
  // MariaDB supports CREATE USER IF NOT EXISTS
  $pdo->exec("CREATE USER IF NOT EXISTS `{$dbUser}`@`{$allowedHost}` IDENTIFIED BY " . $pdo->quote($dbPass));
  echo "[OK] User ensured\n";

  // Grant privileges (dev baseline)
  $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, EXECUTE ON `{$dbName}`.* TO `{$dbUser}`@`{$allowedHost}`");
  $pdo->exec("FLUSH PRIVILEGES");
  echo "[OK] Grants ensured\n";

  if ($importPath) {
    $files = [];

    if (is_dir($importPath)) {
      // Import all *.sql in lexical order
      $dir = rtrim($importPath, '/');
      foreach (glob($dir . '/*.sql') as $f) {
        $bn = basename($f);
        // EXCLUDE drop scripts by default (safety). Import drop scripts only with --include-drop=1
        if (preg_match('/drop/i', $bn)) continue;
        $files[] = $f;
      }
      sort($files, SORT_STRING);
      // Optional include drop scripts
      $includeDrop = arg('--include-drop');
      if ($includeDrop === '1') {
        foreach (glob($dir . '/*.sql') as $f) {
          $bn = basename($f);
          if (!preg_match('/drop/i', $bn)) continue;
          $files[] = $f;
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
      }
    } elseif (is_file($importPath)) {
      $files[] = $importPath;
    } else {
      throw new RuntimeException("Import path not found: {$importPath}");
    }

    if (count($files) === 0) {
      echo "[WARN] No .sql files found to import.\n";
    } else {
      echo "\n-- Importing SQL --\n";
      $pdo->exec("USE `{$dbName}`");
      $pdo->exec("SET NAMES utf8mb4");
      $pdo->exec("SET time_zone = '+00:00'");

      foreach ($files as $file) {
        echo "Import: {$file}\n";
        $sql = file_get_contents($file);
        if ($sql === false) throw new RuntimeException("Failed reading: {$file}");
        // Simple splitter: execute as one batch (works for typical schema files).
        // If your host restricts multi statements, split further.
        $pdo->exec($sql);
      }
      echo "[OK] Import complete\n";
    }
  }

  echo "\nDone.\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, "\n[ERROR] " . $e->getMessage() . "\n");
  fwrite(STDERR, "Tip: set DB_ROOT_USER/DB_ROOT_PASS in .env if root password is required.\n");
  exit(1);
}
