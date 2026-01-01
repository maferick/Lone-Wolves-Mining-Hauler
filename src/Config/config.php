<?php
declare(strict_types=1);

/**
 * src/Config/config.php
 *
 * Centralized config loader. Keep secrets out of Git:
 * - Copy env.example to .env and fill values.
 * - Or set environment variables in your container/hosting.
 */
return [
  'app' => [
    'name' => getenv('APP_NAME') ?: 'Corp Hauling',
    'env'  => getenv('APP_ENV') ?: 'dev',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'base_path' => getenv('APP_BASE_PATH') ?: '',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Amsterdam',
    'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
  ],

  'db' => [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'corp_hauling',
    'user' => getenv('DB_USER') ?: 'corp_hauling_app',
    'pass' => getenv('DB_PASS') ?: 'ChangeMe_123!',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'options' => [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
  ],

  'security' => [
    'csrf' => [
      'enabled' => true,
      'key' => getenv('CSRF_KEY') ?: 'change-me-please',
    ],
    'session' => [
      'name' => getenv('SESSION_NAME') ?: 'corp_hauling_session',
      'secure' => (getenv('SESSION_SECURE') ?: '0') === '1',
      'httponly' => true,
      'samesite' => 'Lax',
    ],
  ],

  'esi' => [
    'endpoints' => [
      'esi_base' => getenv('ESI_BASE_URL') ?: 'https://esi.evetech.net',
      'sso_token' => getenv('SSO_TOKEN_URL') ?: 'https://login.eveonline.com/v2/oauth/token',
      'sso_verify' => getenv('SSO_VERIFY_URL') ?: 'https://login.eveonline.com/oauth/verify',
    ],
'user_agent' => getenv('ESI_USER_AGENT') ?: 'CorpHauling/1.0 (admin@example.local)',
    'cache' => [
      'enabled' => true,
      'default_ttl_seconds' => (int)(getenv('ESI_CACHE_TTL') ?: 300),
      'shared_cache' => true, // corp_id NULL in esi_cache
    ],
  ],
];
