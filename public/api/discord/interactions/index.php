<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Db\Db;

$publicKey = (string)($config['discord']['public_key'] ?? '');
if ($publicKey === '') {
  api_send_json(['ok' => false, 'error' => 'discord_public_key_missing'], 500);
}

$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
$body = file_get_contents('php://input') ?: '';

if ($signature === '' || $timestamp === '') {
  api_send_json(['ok' => false, 'error' => 'signature_missing'], 401);
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

$respond = static function (array $data): void {
  api_send_json($data);
};

$discordEvents = $services['discord_events'] ?? null;
$corpId = null;
if ($discordEvents instanceof \App\Services\DiscordEventService) {
  $guildId = (string)($payload['guild_id'] ?? '');
  $corpId = $discordEvents->resolveCorpId($guildId);
}

if ($corpId === null || $corpId <= 0) {
  $respond([
    'type' => 4,
    'data' => [
      'content' => 'Discord configuration missing. Please contact an admin.',
      'flags' => 64,
    ],
  ]);
}

$configRow = $discordEvents ? $discordEvents->loadConfig($corpId) : [];
$ephemeral = !empty($configRow['commands_ephemeral_default']);

$respondWithMessage = static function (string $content, bool $ephemeral, array $embeds = []): void {
  $data = [
    'content' => $content,
    'flags' => $ephemeral ? 64 : 0,
  ];
  if ($embeds !== []) {
    $data['embeds'] = $embeds;
  }
  api_send_json([
    'type' => 4,
    'data' => $data,
  ]);
};

if ($type !== 2) {
  $respondWithMessage('Unsupported interaction.', $ephemeral);
}

$commandName = (string)($payload['data']['name'] ?? '');
$options = $payload['data']['options'] ?? [];

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

$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
$pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
$createRequestUrl = $baseUrl !== '' ? $baseUrl . ($pathPrefix ?: '') . '/' : ($pathPrefix ?: '/');

switch ($commandName) {
  case 'quote':
    if (empty($services['pricing'])) {
      $respondWithMessage('Quote service unavailable.', $ephemeral);
    }
    $pickup = trim((string)($optionMap['pickup'] ?? ''));
    $delivery = trim((string)($optionMap['delivery'] ?? ''));
    $volume = $parseNumber($optionMap['volume'] ?? '0');
    $collateral = $parseNumber($optionMap['collateral'] ?? '0');
    $priority = strtolower(trim((string)($optionMap['priority'] ?? 'normal')));
    if (!in_array($priority, ['normal', 'high'], true)) {
      $priority = 'normal';
    }

    try {
      /** @var \App\Services\PricingService $pricingService */
      $pricingService = $services['pricing'];
      $quote = $pricingService->quote([
        'pickup' => $pickup,
        'destination' => $delivery,
        'volume_m3' => $volume,
        'collateral_isk' => $collateral,
        'priority' => $priority,
      ], $corpId, [
        'allow_esi_fallback' => false,
      ]);

      $shipClass = (string)($quote['breakdown']['ship_class']['service_class'] ?? '');
      $route = $quote['route'] ?? [];
      $from = $route['path'][0]['system_name'] ?? $pickup;
      $to = '';
      if (!empty($route['path']) && is_array($route['path'])) {
        $last = end($route['path']);
        if (is_array($last)) {
          $to = (string)($last['system_name'] ?? $delivery);
        }
      }
      $routeSummary = trim((string)$from . ' → ' . (string)($to !== '' ? $to : $delivery));
      $jumps = (int)($route['jumps'] ?? 0);
      $price = number_format((float)$quote['price_total'], 2) . ' ISK';

      $embed = [
        'title' => 'Hauling Quote',
        'description' => $routeSummary,
        'fields' => [
          [
            'name' => 'Price',
            'value' => $price,
            'inline' => true,
          ],
          [
            'name' => 'Ship Class',
            'value' => $shipClass !== '' ? $shipClass : 'N/A',
            'inline' => true,
          ],
          [
            'name' => 'Jumps',
            'value' => (string)$jumps,
            'inline' => true,
          ],
          [
            'name' => 'Create Request',
            'value' => $createRequestUrl !== '' ? $createRequestUrl : 'Open the hauling portal to create a request.',
            'inline' => false,
          ],
        ],
      ];

      $respondWithMessage('Quote ready.', $ephemeral, [$embed]);
    } catch (\App\Services\RouteException $e) {
      $respondWithMessage('No viable route found with the current routing graph.', $ephemeral);
    } catch (Throwable $e) {
      $respondWithMessage('Unable to generate quote: ' . $e->getMessage(), $ephemeral);
    }
    break;

  case 'request':
    $requestKey = trim((string)($optionMap['id'] ?? ''));
    if ($requestKey === '') {
      $respondWithMessage('Request id or code required.', $ephemeral);
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
      $respondWithMessage('Request not found.', $ephemeral);
    }

    $from = (string)($requestRow['from_name'] ?? '');
    $to = (string)($requestRow['to_name'] ?? '');
    $routeSummary = trim($from . ' → ' . $to);
    $requestId = (int)($requestRow['request_id'] ?? 0);
    $requestKeyValue = (string)($requestRow['request_key'] ?? '');
    $requestUrl = $requestKeyValue !== '' ? $createRequestUrl . 'request?request_key=' . urlencode($requestKeyValue) : '';

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

    $respondWithMessage('Request details:', $ephemeral, [$embed]);
    break;

  case 'myrequests':
    $discordUserId = (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
    if ($discordUserId === '') {
      $respondWithMessage('Unable to resolve Discord user.', $ephemeral);
    }

    $link = $db->one(
      "SELECT user_id FROM discord_user_link WHERE corp_id = :cid AND discord_user_id = :did LIMIT 1",
      ['cid' => $corpId, 'did' => $discordUserId]
    );
    if (!$link) {
      $linkHint = $createRequestUrl !== '' ? "\nPortal: {$createRequestUrl}" : '';
      $respondWithMessage('No linked account found. Please link your Discord user in the hauling portal.' . $linkHint, $ephemeral);
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
        'uid' => (int)$link['user_id'],
      ]
    );

    if ($rows === []) {
      $respondWithMessage('No recent requests found.', $ephemeral);
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

    $respondWithMessage("Recent requests:\n" . implode("\n", $lines), $ephemeral);
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
      $respondWithMessage('No rate plans configured.', $ephemeral);
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
    $respondWithMessage("Rate plan snapshot:\n" . implode("\n", $lines), $ephemeral);
    break;

  case 'help':
    $help = [
      '/quote pickup delivery volume collateral priority',
      '/request <id|code>',
      '/myrequests',
      '/rates',
      '/ping',
    ];
    $linkLine = $createRequestUrl !== '' ? "\nPortal: {$createRequestUrl}" : '';
    $respondWithMessage("Commands:\n" . implode("\n", $help) . $linkLine, $ephemeral);
    break;

  case 'ping':
    $respondWithMessage('Pong! Discord bot is online.', $ephemeral);
    break;

  default:
    $respondWithMessage('Unknown command.', $ephemeral);
}
