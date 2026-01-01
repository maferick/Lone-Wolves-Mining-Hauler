<?php
declare(strict_types=1);

/**
 * src/bootstrap.php
 *
 * This file is the canonical load order for every endpoint.
 * Keep it lean and deterministic for Codex and CI.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // controlled by config['app']['debug']

// Composer (optional later). Keep it here so we can switch to PSR-4 cleanly.
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
  require_once $composerAutoload;
}

// Minimal PSR-4-ish autoloader (until composer is added)
spl_autoload_register(function (string $class): void {
  $prefix = 'App\\';
  $baseDir = __DIR__ . '/';
  if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
  $relative = substr($class, strlen($prefix));
  $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
  if (file_exists($file)) require_once $file;
});

// Load .env if present (simple parser)
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (str_starts_with(trim($line), '#')) continue;
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

$config = require __DIR__ . '/Config/config.php';

// Debug switch
if (($config['app']['debug'] ?? false) === true) {
  ini_set('display_errors', '1');
}

// Set timezone
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// Base path support (deploy under a subdirectory like /hauling)
//
// Priority order:
//  1) APP_BASE_PATH (explicit)   e.g. /hauling
//  2) APP_BASE_URL path         e.g. http://host/hauling/
//  3) SCRIPT_NAME heuristic     e.g. /hauling/public/index.php → /hauling
//  4) SCRIPT_NAME dirname       e.g. /hauling/index.php → /hauling
$basePath = (string)($config['app']['base_path'] ?? '');
$basePath = rtrim($basePath, '/');

if ($basePath === '') {
  $baseUrl = (string)($config['app']['base_url'] ?? '');
  if ($baseUrl !== '') {
    $p = parse_url($baseUrl, PHP_URL_PATH);
    $basePath = is_string($p) ? rtrim($p, '/') : '';
  }
}

if ($basePath === '') {
  $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  // common case when document root points to /hauling/public but URL is /hauling/*
  // SCRIPT_NAME may be /hauling/public/index.php or /public/index.php
  if ($script !== '') {
    $guess = preg_replace('#/public/index\.php$#', '', $script);
    $guess = preg_replace('#/index\.php$#', '', (string)$guess);
    $guess = rtrim((string)$guess, '/');
    $basePath = $guess;
  }
}

if ($basePath === '' || $basePath === '.') {
  $basePath = '';
}

$config['app']['base_path'] = $basePath;


// Session hardening
$session = $config['security']['session'] ?? [];
session_name($session['name'] ?? 'corp_hauling_session');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => (bool)($session['secure'] ?? false),
  'httponly' => (bool)($session['httponly'] ?? true),
  'samesite' => $session['samesite'] ?? 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

use App\Db\Db;
use App\Auth\Auth;
use App\Services\EsiService;

$db = null;
$health = [
  'ok' => true,
  'db' => false,
  'time_utc' => gmdate('c'),
  'env' => $config['app']['env'] ?? 'dev',
];

try {
  $db = Db::fromConfig($config['db']);
  $health['db'] = true;
} catch (Throwable $e) {
  $health['ok'] = false;
  $health['db_error'] = $e->getMessage();
}

$authCtx = Auth::context($db);

// Service container (lightweight)
$services = [];

if ($db !== null) {
  $services = [
    'esi' => new \App\Services\EsiService($db, $config),
    'sso_login' => new \App\Services\EveSsoLoginService($db, $config),
    'esi_client' => new \App\Services\EsiClient($db, $config),
    'eve_public' => new \App\Services\EvePublicDataService($db, $config, new \App\Services\EsiClient($db, $config)),
  ];
} else {
  $services = [
    'esi' => new \App\Services\EsiServiceStub(),
  ];
}

