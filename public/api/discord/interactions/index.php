<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  api_send_json(['ok' => true]);
}

require_once __DIR__ . '/../../bootstrap.php';

use App\Cache\CacheStoreFactory;
use App\Db\Db;
use App\Services\AuthzService;

$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
$body = file_get_contents('php://input') ?: '';

if ($signature === '' || $timestamp === '') {
  api_send_json(['ok' => false, 'error' => 'signature_missing'], 401);
}

$publicKey = (string)($config['discord']['public_key'] ?? '');
$payloadGuess = json_decode($body, true);
if (is_array($payloadGuess)) {
  $guildIdGuess = (string)($payloadGuess['guild_id'] ?? '');
  $discordEventsGuess = $services['discord_events'] ?? null;
  if ($discordEventsGuess instanceof \App\Services\DiscordEventService) {
    $corpIdGuess = $discordEventsGuess->resolveCorpId($guildIdGuess);
    if ($corpIdGuess) {
      $configGuess = $discordEventsGuess->loadConfig($corpIdGuess);
      if (!empty($configGuess['public_key'])) {
        $publicKey = (string)$configGuess['public_key'];
      }
    }
  }
}
if ($publicKey === '') {
  api_send_json(['ok' => false, 'error' => 'discord_public_key_missing'], 500);
}

$signatureBin = @hex2bin($signature);
$publicKeyBin = @hex2bin($publicKey);
if ($signatureBin === false || $publicKeyBin === false) {
  api_send_json(['ok' => false, 'error' => 'signature_invalid'], 401);
}

$verified = false;
if (function_exists('sodium_crypto_sign_verify_detached')) {
  $verified = sodium_crypto_sign_verify_detached($signatureBin, $timestamp . $body, $publicKeyBin);
}
if (!$verified) {
  api_send_json(['ok' => false, 'error' => 'signature_invalid'], 401);
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
  api_send_json(['ok' => false, 'error' => 'invalid_payload'], 400);
}

$type = (int)($payload['type'] ?? 0);
if ($type === 1) {
  api_send_json(['type' => 1]);
}

if (!in_array($type, [2, 3], true)) {
  api_send_json([
    'type' => 4,
    'data' => [
      'content' => 'Unsupported interaction.',
      'flags' => 64,
    ],
  ]);
}

$commandName = (string)($payload['data']['name'] ?? '');
$options = $payload['data']['options'] ?? [];

$deferResponse = static function (bool $ephemeral): void {
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'type' => 5,
    'data' => [
      'flags' => $ephemeral ? 64 : 0,
    ],
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
  } else {
    @ob_flush();
    @flush();
  }
};

$discordEvents = $services['discord_events'] ?? null;
$corpId = null;
if ($discordEvents instanceof \App\Services\DiscordEventService) {
  $guildId = (string)($payload['guild_id'] ?? '');
  $corpId = $discordEvents->resolveCorpId($guildId);
}

$configRow = $discordEvents && $corpId ? $discordEvents->loadConfig($corpId) : [];
$ephemeral = !empty($configRow['commands_ephemeral_default']);

$getDiscordUserId = static function (array $payload): string {
  return (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
};

$openConfig = $config['open_contracts'] ?? [];
$openLimitMin = (int)($openConfig['limit_min'] ?? 1);
$openLimitMax = (int)($openConfig['limit_max'] ?? 10);
$openVolumeMin = (float)($openConfig['volume_min'] ?? 0);
$openVolumeMax = (float)($openConfig['volume_max'] ?? 100000000);
$openRewardMin = (float)($openConfig['reward_min'] ?? 0);
$openRewardMax = (float)($openConfig['reward_max'] ?? 100000000000);
$openCooldownSeconds = (int)($openConfig['cooldown_seconds'] ?? 3);
$openCacheStore = $db ? CacheStoreFactory::fromConfig($db, $config) : null;

$normalizeOpenText = static function (?string $value): string {
  $value = trim((string)$value);
  return preg_replace('/\s+/', ' ', $value) ?? $value;
};

$clampOpenNumber = static function (?float $value, float $min, float $max): ?float {
  if ($value === null) {
    return null;
  }
  return max($min, min($max, $value));
};

$parseFilters = static function (array $input) use ($normalizeOpenText, $clampOpenNumber, $openVolumeMin, $openVolumeMax, $openRewardMin, $openRewardMax): array {
  $filters = [];
  if (isset($input['priority'])) {
    $filters['priority'] = strtolower($normalizeOpenText((string)$input['priority']));
  }
  if (isset($input['min_volume'])) {
    $filters['min_volume'] = $clampOpenNumber((float)$input['min_volume'], $openVolumeMin, $openVolumeMax);
  }
  if (isset($input['max_volume'])) {
    $filters['max_volume'] = $clampOpenNumber((float)$input['max_volume'], $openVolumeMin, $openVolumeMax);
  }
  if (isset($input['min_reward'])) {
    $filters['min_reward'] = $clampOpenNumber((float)$input['min_reward'], $openRewardMin, $openRewardMax);
  }
  if (isset($input['pickup_system'])) {
    $filters['pickup_system'] = strtolower($normalizeOpenText((string)$input['pickup_system']));
  }
  if (isset($input['drop_system'])) {
    $filters['drop_system'] = strtolower($normalizeOpenText((string)$input['drop_system']));
  }
  if (isset($input['risk'])) {
    $filters['risk'] = strtolower($normalizeOpenText((string)$input['risk']));
  }
  if (isset($filters['min_volume'], $filters['max_volume']) && $filters['min_volume'] > $filters['max_volume']) {
    $filters['max_volume'] = $filters['min_volume'];
  }
  return array_filter($filters, static fn($value) => $value !== '' && $value !== null);
};

$buildFilterSummary = static function (array $filters): string {
  if ($filters === []) {
    return 'None';
  }
  $parts = [];
  foreach ($filters as $key => $value) {
    $parts[] = $key . '=' . $value;
  }
  return implode(', ', $parts);
};

$buildFilterQuery = static function (array $filters): string {
  if ($filters === []) {
    return '';
  }
  $encoded = [];
  foreach ($filters as $key => $value) {
    $encoded[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
  }
  return implode('&', $encoded);
};

$parseFilterQuery = static function (string $query): array {
  if ($query === '') {
    return [];
  }
  $parsed = [];
  parse_str($query, $parsed);
  return is_array($parsed) ? $parsed : [];
};

$formatOpenContractLine = static function (array $contract): string {
  $id = (int)($contract['id'] ?? 0);
  $pickup = trim((string)($contract['pickup_system'] ?? ''));
  $drop = trim((string)($contract['drop_system'] ?? ''));
  $pickupStation = trim((string)($contract['pickup_station'] ?? ''));
  $dropStation = trim((string)($contract['drop_station'] ?? ''));
  $route = trim($pickup . ' → ' . $drop);
  $stations = [];
  if ($pickupStation !== '') {
    $stations[] = $pickupStation;
  }
  if ($dropStation !== '') {
    $stations[] = $dropStation;
  }
  $stationLine = $stations ? ' (' . implode(' → ', $stations) . ')' : '';
  $volume = number_format((float)($contract['volume_m3'] ?? 0), 0);
  $reward = number_format((float)($contract['reward_isk'] ?? 0), 2);
  $priority = (string)($contract['priority'] ?? 'normal');
  $line = sprintf('#%d • %s%s • %s m³ • %s ISK • %s', $id, $route, $stationLine, $volume, $reward, $priority);
  $link = trim((string)($contract['portal_url'] ?? ''));
  if ($link !== '') {
    $line .= "\n" . $link;
  }
  return $line;
};

$buildOpenContractsMessage = static function (array $contracts, int $offset, int $limit, array $filters) use ($buildFilterSummary, $buildFilterQuery, $formatOpenContractLine): array {
  $count = count($contracts);
  $start = $count > 0 ? $offset + 1 : 0;
  $end = $count > 0 ? $offset + $count : 0;
  $summary = $contracts === [] ? 'No open contracts found for these filters.' : implode("\n\n", array_map($formatOpenContractLine, $contracts));
  $filterSummary = $buildFilterSummary($filters);

  $embed = [
    'title' => 'Open Contracts',
    'description' => $summary,
    'footer' => [
      'text' => sprintf('Showing %d-%d • Filters: %s', $start, $end, $filterSummary),
    ],
  ];

  $query = $buildFilterQuery($filters);
  $baseId = $query !== '' ? ':' . $query : '';
  $components = [];

  $navRow = [
    'type' => 1,
    'components' => [],
  ];
  $prevOffset = max(0, $offset - $limit);
  $navRow['components'][] = [
    'type' => 2,
    'style' => 2,
    'label' => 'Prev',
    'custom_id' => 'open:' . $prevOffset . ':' . $limit . $baseId,
    'disabled' => $offset <= 0,
  ];
  $navRow['components'][] = [
    'type' => 2,
    'style' => 2,
    'label' => 'Refresh',
    'custom_id' => 'open:' . $offset . ':' . $limit . $baseId,
  ];
  $navRow['components'][] = [
    'type' => 2,
    'style' => 2,
    'label' => 'Next',
    'custom_id' => 'open:' . ($offset + $limit) . ':' . $limit . $baseId,
    'disabled' => count($contracts) < $limit,
  ];
  $components[] = $navRow;

  $linkButtons = [];
  foreach ($contracts as $contract) {
    $url = trim((string)($contract['portal_url'] ?? ''));
    if ($url === '') {
      continue;
    }
    $label = '#' . (string)($contract['id'] ?? '');
    $linkButtons[] = [
      'type' => 2,
      'style' => 5,
      'label' => 'Open ' . $label,
      'url' => $url,
    ];
    if (count($linkButtons) >= 5) {
      break;
    }
  }
  if ($linkButtons !== []) {
    $components[] = [
      'type' => 1,
      'components' => $linkButtons,
    ];
  }

  return [
    'content' => $contracts === [] ? 'No open contracts found for these filters.' : 'Open contracts available.',
    'embeds' => [$embed],
    'components' => $components,
  ];
};

$buildOpenContractsErrorMessage = static function (string $title, string $description): array {
  return [
    'content' => '',
    'embeds' => [[
      'title' => $title,
      'description' => $description,
    ]],
    'components' => [],
  ];
};

$enforceOpenCooldown = static function (string $discordUserId) use ($openCooldownSeconds, $openCacheStore, $corpId): bool {
  if ($openCooldownSeconds <= 0 || !$openCacheStore || $discordUserId === '' || $corpId === null) {
    return true;
  }
  $cacheKey = Db::esiCacheKey('POST', 'discord.open.cooldown', [
    'discord_user_id' => $discordUserId,
    'corp_id' => $corpId,
  ], null);
  $cached = $openCacheStore->get($corpId, $cacheKey);
  if (!empty($cached['hit'])) {
    $expiresAt = $cached['expires_at'] ?? null;
    if ($expiresAt) {
      $expiresTs = strtotime((string)$expiresAt);
      if ($expiresTs !== false && $expiresTs > time()) {
        return false;
      }
    }
  }
  $openCacheStore->put(
    $corpId,
    $cacheKey,
    'POST',
    'discord.open.cooldown',
    ['discord_user_id' => $discordUserId, 'corp_id' => $corpId],
    null,
    200,
    null,
    null,
    $openCooldownSeconds,
    Db::jsonEncode(['cooldown' => true])
  );
  return true;
};

$logOpenContracts = static function (array $context): void {
  error_log('[discord-open] ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

$apiBase = rtrim((string)($config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
$interactionToken = trim((string)($payload['token'] ?? ''));
$applicationId = trim((string)($payload['application_id'] ?? $configRow['application_id'] ?? $config['discord']['application_id'] ?? ''));
$followupUrl = ($applicationId !== '' && $interactionToken !== '')
  ? $apiBase . '/webhooks/' . $applicationId . '/' . $interactionToken
  : '';

$sendFollowup = static function (array $message) use ($followupUrl): void {
  if ($followupUrl === '') {
    return;
  }

  $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($payload === false) {
    return;
  }

  $ch = curl_init($followupUrl);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_exec($ch);
  curl_close($ch);
};

$sendFollowupMessage = static function (string $content, bool $ephemeral, array $embeds = []) use ($sendFollowup): void {
  $data = [
    'content' => $content,
    'flags' => $ephemeral ? 64 : 0,
  ];
  if ($embeds !== []) {
    $data['embeds'] = $embeds;
  }
  $sendFollowup($data);
};

if ($corpId === null || $corpId <= 0) {
  $sendFollowupMessage('Discord configuration missing. Please contact an admin.', true);
  exit;
}

$optionMap = [];
if (is_array($options)) {
  foreach ($options as $option) {
    if (!is_array($option)) {
      continue;
    }
    if (isset($option['name'])) {
      $optionMap[(string)$option['name']] = $option['value'] ?? null;
    }
  }
}

$parseNumber = static function ($value): float {
  $value = is_string($value) ? $value : (string)$value;
  $value = str_replace([',', ' '], '', $value);
  $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
  return (float)$value;
};

$baseUrl = trim((string)($config['app']['base_url'] ?? ''));
$portalRoot = '';
if ($baseUrl !== '') {
  $parts = parse_url($baseUrl);
  if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
    $portalRoot = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $portalRoot .= ':' . $parts['port'];
    }
    $portalRoot .= '/';
  } else {
    $portalRoot = rtrim($baseUrl, '/') . '/';
  }
}
if ($portalRoot === '') {
  $portalRoot = 'https://lonewolves.online/';
}
$portalLinkLine = $portalRoot !== '' ? "\nPortal: {$portalRoot}" : '';
$dashboardUrl = $portalRoot;
$buildRequestUrl = static function (string $requestKey) use ($config): string {
  $requestKey = trim($requestKey);
  if ($requestKey === '') {
    return '';
  }
  $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
  $basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
  $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
  $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
  $path = ($pathPrefix ?: '') . '/request?request_key=' . urlencode($requestKey);
  return $baseUrl !== '' ? $baseUrl . $path : $path;
};
$onboardingMessage = implode("\n", [
  '🔒 Identity verification required',
  '',
  'Capsuleer, your Discord identity is not yet linked to the Lone Wolves Mining logistics network.',
  'To proceed, you must establish a secure link between your Discord account and your hauling profile.',
  '',
  '🧭 How to link your account',
  '',
  'Open the Lone Wolves Mining portal:',
  '👉 https://lonewolves.online/',
  '',
  'Log in and navigate to My Contracts',
  'Generate a Discord link code',
  'Return here and run the following command in this server:',
  '',
  '/link <your-code>',
  '',
  'Once completed, your identity will be fully synchronized.',
  '',
  '📦 Why this matters',
  '',
  'Linking your account allows:',
  'Automatic association of hauling requests and contracts',
  'Accurate dispatch notifications',
  'Proper access control based on corp roles',
  '',
  'No link means no clearance.',
  '',
  'Lone Wolves Mining',
  'Logistics Command · Identity Verification',
]);
$deniedMessage = 'Your linked account does not have the required portal rights.' . $portalLinkLine;

$requireLinkedUser = static function (string $discordUserId) use ($db, $corpId, $sendFollowupMessage, $onboardingMessage, $ephemeral): ?int {
  $link = $db->one(
    "SELECT l.user_id
       FROM discord_user_link l
       JOIN app_user u ON u.user_id = l.user_id
      WHERE l.discord_user_id = :did
        AND u.corp_id = :cid
      LIMIT 1",
    ['cid' => $corpId, 'did' => $discordUserId]
  );
  if (!$link) {
    $sendFollowupMessage($onboardingMessage, $ephemeral);
    return null;
  }
  $db->execute(
    "UPDATE discord_user_link SET last_seen_at = UTC_TIMESTAMP() WHERE user_id = :uid",
    ['uid' => (int)$link['user_id']]
  );
  return (int)$link['user_id'];
};

$lookupLinkedUserId = static function (string $discordUserId) use ($db, $corpId): ?int {
  $link = $db->one(
    "SELECT l.user_id
       FROM discord_user_link l
       JOIN app_user u ON u.user_id = l.user_id
      WHERE l.discord_user_id = :did
        AND u.corp_id = :cid
      LIMIT 1",
    ['cid' => $corpId, 'did' => $discordUserId]
  );
  if (!$link) {
    return null;
  }
  $db->execute(
    "UPDATE discord_user_link SET last_seen_at = UTC_TIMESTAMP() WHERE user_id = :uid",
    ['uid' => (int)$link['user_id']]
  );
  return (int)$link['user_id'];
};

$userHasRight = static function (int $userId, string $permKey) use ($db): bool {
  $authz = new AuthzService($db);
  if (!$authz->isAccessGrantedUserId($userId)) {
    return false;
  }
  $row = $db->one(
    "SELECT 1
       FROM user_role ur
       JOIN role_permission rp ON rp.role_id = ur.role_id AND rp.allow = 1
       JOIN permission p ON p.perm_id = rp.perm_id
      WHERE ur.user_id = :uid AND p.perm_key = :perm
      LIMIT 1",
    ['uid' => $userId, 'perm' => $permKey]
  );
  return (bool)$row;
};

$openContractsService = $services['open_contracts'] ?? null;

if ($type === 3) {
  $customId = (string)($payload['data']['custom_id'] ?? '');
  $parts = explode(':', $customId, 4);
  if (count($parts) >= 3 && $parts[0] === 'open') {
    $startedAt = microtime(true);
    $error = null;
    $offset = max(0, (int)($parts[1] ?? 0));
    $limit = max($openLimitMin, min($openLimitMax, (int)($parts[2] ?? 5)));
    $filterQuery = $parts[3] ?? '';
    $filters = $parseFilters($parseFilterQuery($filterQuery));

    if (!$openContractsService instanceof \App\Services\OpenContractsService) {
      $error = 'service_unavailable';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => $getDiscordUserId($payload),
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      api_send_json([
        'type' => 7,
        'data' => $buildOpenContractsErrorMessage('Service unavailable', 'Open contracts service unavailable.'),
      ]);
    }

    $discordUserId = $getDiscordUserId($payload);
    if ($discordUserId === '') {
      $error = 'discord_user_missing';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => '',
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      api_send_json([
        'type' => 7,
        'data' => $buildOpenContractsErrorMessage('Unable to resolve user', 'Unable to resolve Discord user.'),
      ]);
    }
    $linkedUserId = $lookupLinkedUserId($discordUserId);
    if ($linkedUserId === null) {
      $error = 'link_required';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      api_send_json([
        'type' => 4,
        'data' => [
          'embeds' => [[
            'title' => 'Link required',
            'description' => 'You must /link first to view open contracts.',
          ]],
          'flags' => 64,
        ],
      ]);
    }
    if (!$userHasRight($linkedUserId, 'hauling.member')) {
      $error = 'permission_denied';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      api_send_json([
        'type' => 7,
        'data' => $buildOpenContractsErrorMessage('Access denied', $deniedMessage),
      ]);
    }
    if (!$enforceOpenCooldown($discordUserId)) {
      $error = 'rate_limited';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      api_send_json([
        'type' => 7,
        'data' => $buildOpenContractsErrorMessage('Slow down', 'Please wait a moment before refreshing open contracts again.'),
      ]);
    }

    try {
      $contracts = $openContractsService->listOpenContracts($corpId, $filters, $limit, $offset);
    } catch (Throwable $e) {
      $error = 'open_contracts_error';
      $logOpenContracts([
        'command' => 'open',
        'event' => 'component',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $e->getMessage(),
      ]);
      api_send_json([
        'type' => 7,
        'data' => $buildOpenContractsErrorMessage('Error', 'Unable to load open contracts right now.'),
      ]);
    }
    $message = $buildOpenContractsMessage($contracts, $offset, $limit, $filters);
    $logOpenContracts([
      'command' => 'open',
      'event' => 'component',
      'discord_user_id' => $discordUserId,
      'corp_id' => $corpId,
      'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
      'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
      'result_count' => count($contracts),
      'error' => $error,
    ]);

    api_send_json([
      'type' => 7,
      'data' => $message,
    ]);
  }

  api_send_json([
    'type' => 4,
    'data' => [
      'content' => 'Unknown action.',
      'flags' => 64,
    ],
  ]);
}

$deferResponse(true);
ignore_user_abort(true);
set_time_limit(0);

switch ($commandName) {
  case 'open':
    $startedAt = microtime(true);
    $error = null;
    $emptyFiltersHash = hash('sha256', json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!$openContractsService instanceof \App\Services\OpenContractsService) {
      $error = 'service_unavailable';
      $sendFollowup($buildOpenContractsErrorMessage('Service unavailable', 'Open contracts service unavailable.') + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => $getDiscordUserId($payload),
        'corp_id' => $corpId,
        'filters_hash' => $emptyFiltersHash,
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      exit;
    }
    $discordUserId = $getDiscordUserId($payload);
    if ($discordUserId === '') {
      $error = 'discord_user_missing';
      $sendFollowup($buildOpenContractsErrorMessage('Unable to resolve user', 'Unable to resolve Discord user.') + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => '',
        'corp_id' => $corpId,
        'filters_hash' => $emptyFiltersHash,
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      exit;
    }
    $linkedUserId = $lookupLinkedUserId($discordUserId);
    if ($linkedUserId === null) {
      $error = 'link_required';
      $sendFollowup($buildOpenContractsErrorMessage('Link required', 'You must /link first to view open contracts.') + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => $emptyFiltersHash,
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      exit;
    }
    if (!$userHasRight($linkedUserId, 'hauling.member')) {
      $error = 'permission_denied';
      $sendFollowup($buildOpenContractsErrorMessage('Access denied', $deniedMessage) + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => $emptyFiltersHash,
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      exit;
    }
    if (!$enforceOpenCooldown($discordUserId)) {
      $error = 'rate_limited';
      $sendFollowup($buildOpenContractsErrorMessage('Slow down', 'Please wait a moment before refreshing open contracts again.') + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => $emptyFiltersHash,
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $error,
      ]);
      exit;
    }

    $filters = $parseFilters([
      'priority' => $optionMap['priority'] ?? null,
      'min_volume' => isset($optionMap['min_volume']) ? $parseNumber($optionMap['min_volume']) : null,
      'max_volume' => isset($optionMap['max_volume']) ? $parseNumber($optionMap['max_volume']) : null,
      'min_reward' => isset($optionMap['min_reward']) ? $parseNumber($optionMap['min_reward']) : null,
      'pickup_system' => $optionMap['pickup_system'] ?? null,
      'drop_system' => $optionMap['drop_system'] ?? null,
      'risk' => $optionMap['risk'] ?? null,
    ]);

    $limit = max($openLimitMin, min($openLimitMax, (int)($optionMap['limit'] ?? 5)));
    $page = max(1, (int)($optionMap['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    try {
      $contracts = $openContractsService->listOpenContracts($corpId, $filters, $limit, $offset);
    } catch (Throwable $e) {
      $error = 'open_contracts_error';
      $sendFollowup($buildOpenContractsErrorMessage('Error', 'Unable to load open contracts right now.') + ['flags' => $ephemeral ? 64 : 0]);
      $logOpenContracts([
        'command' => 'open',
        'event' => 'slash',
        'discord_user_id' => $discordUserId,
        'corp_id' => $corpId,
        'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
        'result_count' => 0,
        'error' => $e->getMessage(),
      ]);
      exit;
    }
    $message = $buildOpenContractsMessage($contracts, $offset, $limit, $filters);
    $sendFollowup($message + ['flags' => $ephemeral ? 64 : 0]);
    $logOpenContracts([
      'command' => 'open',
      'event' => 'slash',
      'discord_user_id' => $discordUserId,
      'corp_id' => $corpId,
      'filters_hash' => hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
      'latency_ms' => (int)((microtime(true) - $startedAt) * 1000),
      'result_count' => count($contracts),
      'error' => $error,
    ]);
    break;

  case 'request':
    $discordUserId = (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
    if ($discordUserId === '') {
      $sendFollowupMessage('Unable to resolve Discord user.', $ephemeral);
      exit;
    }
    $linkedUserId = $requireLinkedUser($discordUserId);
    if ($linkedUserId === null) {
      exit;
    }
    if (!$userHasRight($linkedUserId, 'hauling.member')) {
      $sendFollowupMessage($deniedMessage, $ephemeral);
      exit;
    }

    $requestKey = trim((string)($optionMap['id'] ?? ''));
    if ($requestKey === '') {
      $sendFollowupMessage('Request id or code required.', $ephemeral);
      exit;
    }

    $requestRow = null;
    if (ctype_digit($requestKey)) {
      $requestRow = $db->one(
        "SELECT * FROM v_haul_request_display WHERE corp_id = :cid AND request_id = :rid LIMIT 1",
        ['cid' => $corpId, 'rid' => (int)$requestKey]
      );
    }
    if (!$requestRow) {
      $requestRow = $db->one(
        "SELECT * FROM v_haul_request_display WHERE corp_id = :cid AND request_key = :rkey LIMIT 1",
        ['cid' => $corpId, 'rkey' => $requestKey]
      );
    }

    if (!$requestRow) {
      $sendFollowupMessage('Request not found.', $ephemeral);
      exit;
    }

    $from = (string)($requestRow['from_name'] ?? '');
    $to = (string)($requestRow['to_name'] ?? '');
    $routeSummary = trim($from . ' → ' . $to);
    $requestId = (int)($requestRow['request_id'] ?? 0);
    $requestKeyValue = (string)($requestRow['request_key'] ?? '');
    $requestUrl = $buildRequestUrl($requestKeyValue);

    $embed = [
      'title' => 'Request #' . $requestId,
      'description' => $routeSummary,
      'fields' => [
        [
          'name' => 'Status',
          'value' => (string)($requestRow['status'] ?? 'unknown'),
          'inline' => true,
        ],
        [
          'name' => 'Reward',
          'value' => number_format((float)($requestRow['reward_isk'] ?? 0), 2) . ' ISK',
          'inline' => true,
        ],
        [
          'name' => 'Collateral',
          'value' => number_format((float)($requestRow['collateral_isk'] ?? 0), 2) . ' ISK',
          'inline' => true,
        ],
        [
          'name' => 'Volume',
          'value' => number_format((float)($requestRow['volume_m3'] ?? 0), 0) . ' m³',
          'inline' => true,
        ],
      ],
    ];

    if ($requestUrl !== '') {
      $embed['fields'][] = [
        'name' => 'Request Link',
        'value' => $requestUrl,
        'inline' => false,
      ];
    }

    $lifecycle = (string)($requestRow['contract_lifecycle'] ?? '');
    $status = (string)($requestRow['status'] ?? '');
    if (in_array($status, ['requested', 'awaiting_contract'], true) || $lifecycle === 'AWAITING_CONTRACT') {
      $embed['fields'][] = [
        'name' => 'Next Action',
        'value' => $requestUrl !== '' ? 'Create contract instructions: ' . $requestUrl : 'Create contract instructions on the hauling portal.',
        'inline' => false,
      ];
    }

    $sendFollowupMessage('Request details:', $ephemeral, [$embed]);
    break;

  case 'myrequests':
    $discordUserId = (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
    if ($discordUserId === '') {
      $sendFollowupMessage('Unable to resolve Discord user.', $ephemeral);
      exit;
    }

    $linkedUserId = $requireLinkedUser($discordUserId);
    if ($linkedUserId === null) {
      exit;
    }
    if (!$userHasRight($linkedUserId, 'hauling.member')) {
      $sendFollowupMessage($deniedMessage, $ephemeral);
      exit;
    }

    $rows = $db->select(
      "SELECT v.request_id, v.status, v.from_name, v.to_name, v.reward_isk, v.request_key, r.created_at
         FROM haul_request r
         JOIN v_haul_request_display v ON v.request_id = r.request_id
        WHERE r.corp_id = :cid AND r.requester_user_id = :uid
        ORDER BY r.created_at DESC
        LIMIT 5",
      [
        'cid' => $corpId,
        'uid' => $linkedUserId,
      ]
    );

    if ($rows === []) {
      $sendFollowupMessage('No recent requests found.', $ephemeral);
      exit;
    }

    $lines = [];
    foreach ($rows as $row) {
      $route = trim((string)($row['from_name'] ?? '') . ' → ' . (string)($row['to_name'] ?? ''));
      $lines[] = sprintf(
        '#%d • %s • %s',
        (int)$row['request_id'],
        $route,
        (string)($row['status'] ?? 'unknown')
      );
    }

    $sendFollowupMessage("Recent requests:\n" . implode("\n", $lines), $ephemeral);
    break;

  case 'link':
    $linkEphemeral = true;
    $discordUserId = (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
    if ($discordUserId === '') {
      $sendFollowupMessage('Unable to resolve Discord user.' . $portalLinkLine, $linkEphemeral);
      exit;
    }

    $userPayload = $payload['member']['user'] ?? $payload['user'] ?? [];
    $discordUsername = '';
    if (is_array($userPayload)) {
      $discordUsername = trim((string)($userPayload['global_name'] ?? ''));
      if ($discordUsername === '') {
        $discordUsername = trim((string)($userPayload['username'] ?? ''));
      }
      $discriminator = trim((string)($userPayload['discriminator'] ?? ''));
      if ($discordUsername !== '' && $discriminator !== '' && $discriminator !== '0') {
        $discordUsername .= '#' . $discriminator;
      }
    }

    $code = strtoupper(trim((string)($optionMap['code'] ?? '')));
    if ($code === '') {
      $sendFollowupMessage('Link code required. Generate one in the portal first.' . $portalLinkLine, $linkEphemeral);
      exit;
    }

    try {
      $result = $db->tx(static function (Db $db) use ($code, $discordUserId, $discordUsername, $corpId): array {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $codeRow = $db->one(
          "SELECT code, user_id, expires_at, used_at, created_at
             FROM discord_link_code
            WHERE code = :code
            LIMIT 1
            FOR UPDATE",
          ['code' => $code]
        );
        if (!$codeRow) {
          return [
            'ok' => false,
            'error' => 'invalid_code',
            'log' => [
              'code_id' => $code,
              'created_at' => null,
              'expires_at' => null,
              'used_at' => null,
              'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
              'decision' => 'invalid_code',
              'discord_user_id' => $discordUserId,
            ],
          ];
        }

        if (!empty($codeRow['used_at'])) {
          return [
            'ok' => false,
            'error' => 'code_used',
            'log' => [
              'code_id' => (string)$codeRow['code'],
              'created_at' => $codeRow['created_at'],
              'expires_at' => $codeRow['expires_at'],
              'used_at' => $codeRow['used_at'],
              'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
              'decision' => 'already_used',
              'discord_user_id' => $discordUserId,
            ],
          ];
        }

        $expiresAt = DateTimeImmutable::createFromFormat(
          'Y-m-d H:i:s',
          (string)$codeRow['expires_at'],
          new DateTimeZone('UTC')
        );
        if (!$expiresAt) {
          $expiresAt = new DateTimeImmutable((string)$codeRow['expires_at'], new DateTimeZone('UTC'));
        }
        $expiryThreshold = $nowUtc->modify('-5 seconds');
        if ($expiresAt <= $expiryThreshold) {
          return [
            'ok' => false,
            'error' => 'code_expired',
            'log' => [
              'code_id' => (string)$codeRow['code'],
              'created_at' => $codeRow['created_at'],
              'expires_at' => $codeRow['expires_at'],
              'used_at' => $codeRow['used_at'],
              'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
              'decision' => 'expired',
              'discord_user_id' => $discordUserId,
            ],
          ];
        }

        $userRow = $db->one(
          "SELECT user_id, corp_id FROM app_user WHERE user_id = :uid LIMIT 1",
          ['uid' => (int)$codeRow['user_id']]
        );
        if (!$userRow || (int)$userRow['corp_id'] !== $corpId) {
          return [
            'ok' => false,
            'error' => 'code_not_valid',
            'log' => [
              'code_id' => (string)$codeRow['code'],
              'created_at' => $codeRow['created_at'],
              'expires_at' => $codeRow['expires_at'],
              'used_at' => $codeRow['used_at'],
              'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
              'decision' => 'invalid_corp',
              'discord_user_id' => $discordUserId,
            ],
          ];
        }

        $existing = $db->one(
          "SELECT user_id FROM discord_user_link WHERE discord_user_id = :did LIMIT 1",
          ['did' => $discordUserId]
        );
        if ($existing && (int)$existing['user_id'] !== (int)$codeRow['user_id']) {
          return [
            'ok' => false,
            'error' => 'discord_already_linked',
            'log' => [
              'code_id' => (string)$codeRow['code'],
              'created_at' => $codeRow['created_at'],
              'expires_at' => $codeRow['expires_at'],
              'used_at' => $codeRow['used_at'],
              'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
              'decision' => 'discord_already_linked',
              'discord_user_id' => $discordUserId,
            ],
          ];
        }

        $db->execute(
          "INSERT INTO discord_user_link
            (user_id, discord_user_id, discord_username, linked_at, last_seen_at)
           VALUES
            (:uid, :did, :uname, UTC_TIMESTAMP(), UTC_TIMESTAMP())
           ON DUPLICATE KEY UPDATE
            discord_user_id = VALUES(discord_user_id),
            discord_username = VALUES(discord_username),
            linked_at = UTC_TIMESTAMP(),
            last_seen_at = UTC_TIMESTAMP()",
          [
            'uid' => (int)$codeRow['user_id'],
            'did' => $discordUserId,
            'uname' => $discordUsername !== '' ? $discordUsername : null,
          ]
        );

        $db->execute(
          "UPDATE discord_link_code
              SET used_at = UTC_TIMESTAMP(),
                  used_by_discord_user_id = :did
            WHERE code = :code",
          [
            'did' => $discordUserId,
            'code' => $code,
          ]
        );

        return [
          'ok' => true,
          'user_id' => (int)$codeRow['user_id'],
          'log' => [
            'code_id' => (string)$codeRow['code'],
            'created_at' => $codeRow['created_at'],
            'expires_at' => $codeRow['expires_at'],
            'used_at' => $codeRow['used_at'],
            'now_utc' => $nowUtc->format('Y-m-d H:i:s'),
            'decision' => 'linked',
            'discord_user_id' => $discordUserId,
          ],
        ];
      });
    } catch (Throwable $e) {
      $sendFollowupMessage('Unable to link your account right now.' . $portalLinkLine, $linkEphemeral);
      exit;
    }

    if (!empty($result['log']) && is_array($result['log'])) {
      error_log('[discord-link] ' . json_encode($result['log'], JSON_UNESCAPED_SLASHES));
    }

    if (!empty($result['ok'])) {
      if (!empty($services['discord_events']) && !empty($result['user_id'])) {
        $services['discord_events']->enqueueRoleSyncUser($corpId, (int)$result['user_id'], $discordUserId);
      }
      $rightsSourceLegacy = (string)($configRow['rights_source'] ?? 'portal');
      $rightsSourceMember = (string)($configRow['rights_source_member'] ?? $rightsSourceLegacy);
      $rightsSourceHauler = (string)($configRow['rights_source_hauler'] ?? $rightsSourceLegacy);
      $portalPermsToSync = [];
      if ($rightsSourceMember === 'discord') {
        $portalPermsToSync[] = 'hauling.member';
      }
      if ($rightsSourceHauler === 'discord') {
        $portalPermsToSync[] = 'hauling.hauler';
      }
      if ($portalPermsToSync !== [] && !empty($services['discord_delivery'])) {
        try {
          /** @var \App\Services\DiscordDeliveryService $delivery */
          $delivery = $services['discord_delivery'];
          $delivery->syncPortalRightsFromDiscord($corpId, (int)$result['user_id'], $discordUserId, $configRow, $portalPermsToSync);
        } catch (Throwable $e) {
          error_log('[discord-link] rights sync failed: ' . $e->getMessage());
        }
      }
      $successDashboardUrl = $dashboardUrl !== '' ? $dashboardUrl : 'https://lonewolves.online/my-contracts/';
      $sendFollowupMessage(implode("\n", [
        '✅ Identity link established',
        '',
        'Capsuleer, your Discord account has been successfully linked to the Lone Wolves Mining logistics network.',
        '',
        'Your identity is now recognized by:',
        '',
        'Dispatch',
        'Contract coordination',
        'Automated hauling operations',
        '',
        '🛰 What you can do now',
        '',
        'You can immediately:',
        '',
        'View your hauling requests and contracts',
        'Track operational status updates',
        'Receive dispatch pings when action is required',
        '',
        '👉 Access your dashboard:',
        $successDashboardUrl,
        '',
        'From here on, all systems operate under a single, verified identity.',
        '',
        'The route is clear. Paperwork is in order.',
        'You are authorized to undock.',
        '',
        'Lone Wolves Mining',
        'Logistics Command · Hauling Operations',
      ]), $linkEphemeral);
      break;
    }

    $error = (string)($result['error'] ?? 'unknown');
    $errorMessage = match ($error) {
      'invalid_code' => 'Link code not found. Please generate a new code in the portal.',
      'code_used' => 'That link code has already been used. Generate a new code in the portal.',
      'code_expired' => 'That link code has expired. Generate a new code in the portal.',
      'discord_already_linked' => 'This Discord user is already linked to another portal account.',
      'code_not_valid' => 'That link code is not valid for this server.',
      default => 'Unable to link your account with that code.',
    };
    $sendFollowupMessage($errorMessage . $portalLinkLine, $linkEphemeral);
    break;

  case 'rates':
    $rates = $db->select(
      "SELECT service_class, rate_per_jump, collateral_rate, min_price
         FROM rate_plan
        WHERE corp_id = :cid
        ORDER BY service_class",
      ['cid' => $corpId]
    );
    if ($rates === []) {
      $sendFollowupMessage('No rate plans configured.', $ephemeral);
      exit;
    }
    $lines = [];
    foreach ($rates as $rate) {
      $lines[] = sprintf(
        '%s: %s ISK/jump, collateral %s%%, min %s ISK',
        (string)($rate['service_class'] ?? 'N/A'),
        number_format((float)($rate['rate_per_jump'] ?? 0), 2),
        number_format((float)($rate['collateral_rate'] ?? 0) * 100, 2),
        number_format((float)($rate['min_price'] ?? 0), 2)
      );
    }
    $sendFollowupMessage("Rate plan snapshot:\n" . implode("\n", $lines), $ephemeral);
    break;

  case 'help':
    $help = [
      '/open [filters]',
      '/request <id|code>',
      '/link code',
      '/myrequests',
      '/rates',
      '/ping',
    ];
    $sendFollowupMessage("Commands:\n" . implode("\n", $help) . $portalLinkLine, true);
    break;

  case 'ping':
    $sendFollowupMessage('Pong! Discord bot is online.', $ephemeral);
    break;

  default:
    $sendFollowupMessage('Unknown command.', $ephemeral);
}
