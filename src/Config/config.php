<?php
declare(strict_types=1);

/**
 * src/Config/config.php
 *
 * Central config map (env-driven).
 * Keep this file syntactically simple to avoid deploy-time surprises.
 */

return [
  'app' => [
    'name' => getenv('APP_NAME') ?: 'Corp Hauling',
    'env' => getenv('APP_ENV') ?: 'dev',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'base_path' => getenv('APP_BASE_PATH') ?: '',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Amsterdam',
    'debug' => (getenv('APP_DEBUG') ?: '0') === '1',
  ],

  'db' => [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'corp_hauling',
    'user' => getenv('DB_USER') ?: 'corp_hauling_app',
    'pass' => getenv('DB_PASS') ?: '',
  ],

  'esi' => [
    'user_agent' => getenv('ESI_USER_AGENT') ?: 'CorpHauling/1.0',
    'cache' => [
      'enabled' => true,
      'default_ttl_seconds' => (int)(getenv('ESI_CACHE_TTL') ?: 300),
    ],
    'rate_limit' => [
      'enabled' => (getenv('ESI_RATE_LIMIT_ENABLED') ?: '1') === '1',
      'min_error_remain' => (int)(getenv('ESI_RATE_LIMIT_MIN_REMAIN') ?: 5),
      'sleep_buffer_seconds' => (int)(getenv('ESI_RATE_LIMIT_BUFFER') ?: 1),
      'max_sleep_seconds' => (int)(getenv('ESI_RATE_LIMIT_MAX_SLEEP') ?: 60),
      'retry_statuses' => [420, 429],
    ],
    'endpoints' => [
      'esi_base' => getenv('ESI_BASE_URL') ?: 'https://esi.evetech.net',
      'sso_token' => getenv('SSO_TOKEN_URL') ?: 'https://login.eveonline.com/v2/oauth/token',
      'sso_verify' => getenv('SSO_VERIFY_URL') ?: 'https://login.eveonline.com/oauth/verify',
    ],
  ],
  'sde' => [
    'enabled' => (getenv('SDE_ENABLED') ?: '0') === '1',
    'path' => getenv('SDE_PATH') ?: '',
  ],

  'sso' => [
    'scopes' => array_values(array_filter(explode(' ', getenv('EVE_SCOPES') ?: 'esi-contracts.read_corporation_contracts.v1 esi-corporations.read_structures.v1 esi-universe.read_structures.v1'))),
  ],

  'security' => [
    'csrf_key' => getenv('CSRF_KEY') ?: 'change-me-please',
    'session' => [
      'name' => getenv('SESSION_NAME') ?: 'corp_hauling_session',
      'secure' => (getenv('SESSION_SECURE') ?: '0') === '1',
      'httponly' => true,
      'samesite' => 'Lax',
    ],
    'api_key' => getenv('API_KEY') ?: '',
  ],
];
