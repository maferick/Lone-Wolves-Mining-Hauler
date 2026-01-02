<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}

$payload = api_read_json();
$quoteId = (int)($payload['quote_id'] ?? 0);
$contractId = (int)($payload['contract_id'] ?? 0);

if ($quoteId <= 0 || $contractId <= 0) {
  api_send_json(['ok' => false, 'error' => 'quote_id and contract_id are required'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$request = $db->one(
  "SELECT request_id, requester_user_id, from_location_id, to_location_id, collateral_isk, reward_isk, volume_m3, ship_class,
          route_policy, price_breakdown_json, quote_id
     FROM haul_request
    WHERE corp_id = :cid AND quote_id = :qid
    ORDER BY request_id DESC
    LIMIT 1",
  ['cid' => $corpId, 'qid' => $quoteId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'haul request not found for quote'], 404);
}

$canManage = Auth::can($authCtx, 'haul.request.manage');
$isOwner = (int)$request['requester_user_id'] === (int)$authCtx['user_id'];
if (!$canManage && !$isOwner) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$cronSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
  ['cid' => $corpId]
);
$cronJson = $cronSetting ? Db::jsonDecode((string)$cronSetting['setting_json'], []) : [];
$charId = (int)($cronJson['character_id'] ?? 0);
if ($charId <= 0) {
  api_send_json(['ok' => false, 'error' => 'No ESI cron character configured for contract validation.'], 400);
}

/** @var \App\Services\EsiService $esi */
$esi = $services['esi'];

try {
  $contract = $esi->contracts()->findContractById($corpId, $charId, $contractId);
} catch (Throwable $e) {
  api_send_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

if (!$contract) {
  api_send_json(['ok' => false, 'error' => 'Contract not found via ESI'], 404);
}

$contractType = (string)($contract['type'] ?? 'unknown');
if ($contractType !== 'courier') {
  api_send_json(['ok' => false, 'error' => 'Contract must be courier type'], 400);
}

$startLocationId = (int)($contract['start_location_id'] ?? 0);
$endLocationId = (int)($contract['end_location_id'] ?? 0);

$resolveSystemId = static function (Db $db, int $locationId): int {
  if ($locationId <= 0) {
    return 0;
  }
  $row = $db->one(
    "SELECT system_id FROM map_system WHERE system_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  $row = $db->one(
    "SELECT system_id FROM eve_station WHERE station_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  $row = $db->one(
    "SELECT system_id FROM eve_structure WHERE structure_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  return 0;
};

$startSystemId = $resolveSystemId($db, $startLocationId);
$endSystemId = $resolveSystemId($db, $endLocationId);

if ($startSystemId !== (int)$request['from_location_id']) {
  api_send_json(['ok' => false, 'error' => 'Contract pickup location does not match quote'], 400);
}
if ($endSystemId !== (int)$request['to_location_id']) {
  api_send_json(['ok' => false, 'error' => 'Contract destination does not match quote'], 400);
}

$contractCollateral = (float)($contract['collateral'] ?? 0);
$contractReward = (float)($contract['reward'] ?? 0);
$contractVolume = (float)($contract['volume'] ?? 0);

if ($contractCollateral + 0.01 < (float)$request['collateral_isk']) {
  api_send_json(['ok' => false, 'error' => 'Contract collateral below expected'], 400);
}

$toleranceSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.reward_tolerance' LIMIT 1",
  ['cid' => $corpId]
);
$tolJson = $toleranceSetting ? Db::jsonDecode((string)$toleranceSetting['setting_json'], []) : [];
$tolType = (string)($tolJson['type'] ?? 'percent');
$tolValue = (float)($tolJson['value'] ?? 0.0);

$expectedReward = (float)$request['reward_isk'];
$tolerance = 0.0;
if ($tolType === 'flat') {
  $tolerance = $tolValue;
} else {
  $tolerance = $expectedReward * $tolValue;
}
$minReward = max(0.0, $expectedReward - $tolerance);
if ($contractReward + 0.01 < $minReward) {
  api_send_json(['ok' => false, 'error' => 'Contract reward below expected tolerance'], 400);
}

$breakdown = [];
if (!empty($request['price_breakdown_json'])) {
  $breakdown = Db::jsonDecode((string)$request['price_breakdown_json'], []);
}
$maxVolume = (float)($breakdown['ship_class']['max_volume'] ?? 0);
if ($maxVolume > 0 && $contractVolume > $maxVolume) {
  api_send_json(['ok' => false, 'error' => 'Contract volume exceeds ship class maximum'], 400);
}

$fromName = $db->fetchValue(
  "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
  ['id' => (int)$request['from_location_id']]
);
$toName = $db->fetchValue(
  "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
  ['id' => (int)$request['to_location_id']]
);

$securityCounts = $breakdown['security_counts'] ?? ['high' => 0, 'low' => 0, 'null' => 0];

$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
$pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
$requestPath = ($pathPrefix ?: '') . '/request?request_id=' . (string)$request['request_id'];
$requestUrl = $baseUrl !== '' ? $baseUrl . $requestPath : $requestPath;

$webhookPayload = [
  'quote_id' => (int)$request['quote_id'],
  'request_id' => (int)$request['request_id'],
  'from_system_id' => (int)$request['from_location_id'],
  'to_system_id' => (int)$request['to_location_id'],
  'from_system_name' => (string)($fromName ?: ''),
  'to_system_name' => (string)($toName ?: ''),
  'jumps' => (int)($breakdown['jumps'] ?? 0),
  'security_counts' => $securityCounts,
  'price_isk' => $expectedReward,
  'collateral_isk' => (float)$request['collateral_isk'],
  'volume_m3' => (float)$request['volume_m3'],
  'ship_class' => (string)($request['ship_class'] ?? ''),
  'request_url' => $requestUrl,
];

$webhookPayload['content'] = sprintf(
  "New haul request #%s (Quote #%s)\\n%s → %s • %d jumps (HS %d / LS %d / NS %d)\\nShip: %s • Volume: %s m³ • Price: %s ISK • Collateral: %s ISK\\n%s",
  (string)$request['request_id'],
  (string)$request['quote_id'],
  (string)($fromName ?: 'Unknown'),
  (string)($toName ?: 'Unknown'),
  (int)($breakdown['jumps'] ?? 0),
  (int)($securityCounts['high'] ?? 0),
  (int)($securityCounts['low'] ?? 0),
  (int)($securityCounts['null'] ?? 0),
  (string)($request['ship_class'] ?? 'N/A'),
  number_format((float)$request['volume_m3'], 0),
  number_format($expectedReward, 2),
  number_format((float)$request['collateral_isk'], 2),
  $requestUrl
);

$db->tx(function (Db $db) use ($request, $contractId, $contractType, $contract, $webhookPayload, $services, $authCtx, $corpId): void {
  $db->execute(
    "UPDATE haul_request
        SET contract_id = :contract_id,
            contract_type = :contract_type,
            contract_status = :contract_status,
            status = 'in_queue',
            updated_at = CURRENT_TIMESTAMP
      WHERE request_id = :rid",
    [
      'contract_id' => $contractId,
      'contract_type' => $contractType,
      'contract_status' => (string)($contract['status'] ?? 'unknown'),
      'rid' => (int)$request['request_id'],
    ]
  );

  $db->insert('haul_event', [
    'request_id' => (int)$request['request_id'],
    'event_type' => 'posted',
    'message' => 'Contract attached and validated.',
    'payload_json' => Db::jsonEncode([
      'contract_id' => $contractId,
      'status' => (string)($contract['status'] ?? 'unknown'),
    ]),
    'created_by_user_id' => (int)($authCtx['user_id'] ?? 0) ?: null,
  ]);

  /** @var \App\Services\DiscordWebhookService $webhooks */
  $webhooks = $services['discord_webhook'];
  $webhooks->enqueue($corpId, 'haul.contract.attached', $webhookPayload);
});

api_send_json([
  'ok' => true,
  'request_id' => (int)$request['request_id'],
  'contract_id' => $contractId,
  'status' => 'in_queue',
]);
