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
    'endpoints' => [
      'esi_base' => getenv('ESI_BASE_URL') ?: 'https://esi.evetech.net',
      'sso_token' => getenv('SSO_TOKEN_URL') ?: 'https://login.eveonline.com/v2/oauth/token',
      'sso_verify' => getenv('SSO_VERIFY_URL') ?: 'https://login.eveonline.com/oauth/verify',
    ],
  ],

  'security' => [
    'csrf_key' => getenv('CSRF_KEY') ?: 'change-me-please',
    'session_name' => getenv('SESSION_NAME') ?: 'corp_hauling_session',
    'session_secure' => (getenv('SESSION_SECURE') ?: '0') === '1',
    'api_key' => getenv('API_KEY') ?: '',
  ],
];
