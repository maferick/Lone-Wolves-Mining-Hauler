<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = ['enabled' => false, 'thresholds' => []];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.volume_pressure' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($row === null && $corpId !== 0) {
    $row = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.volume_pressure' LIMIT 1"
    );
  }
  $payload = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $payload = array_merge($payload, $decoded);
    }
  }
  api_send_json(['ok' => true] + $payload);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$enabled = !empty($payload['enabled']);
$thresholds = $payload['thresholds'] ?? [];
if (!is_array($thresholds)) {
  api_send_json(['ok' => false, 'error' => 'Invalid thresholds'], 400);
}

$normalized = [];
foreach ($thresholds as $threshold) {
  if (!is_array($threshold)) {
    continue;
  }
  $thresholdPct = isset($threshold['threshold_pct']) ? (float)$threshold['threshold_pct'] : null;
  $surchargePct = isset($threshold['surcharge_pct']) ? (float)$threshold['surcharge_pct'] : null;
  if ($thresholdPct === null || $surchargePct === null) {
    continue;
  }
  $normalized[] = [
    'threshold_pct' => max(0.0, min(100.0, $thresholdPct)),
    'surcharge_pct' => max(0.0, $surchargePct),
  ];
}

$settingJson = Db::jsonEncode([
  'enabled' => $enabled,
  'thresholds' => $normalized,
]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'pricing.volume_pressure', :json)
   ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json)",
  [
    'cid' => $corpId,
    'json' => $settingJson,
  ]
);

$db->audit(
  $corpId,
  (int)($authCtx['user_id'] ?? 0) ?: null,
  (int)($authCtx['character_id'] ?? 0) ?: null,
  'pricing.volume_pressure.update',
  'app_setting',
  'pricing.volume_pressure',
  null,
  ['enabled' => $enabled, 'thresholds' => $normalized],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'enabled' => $enabled, 'thresholds' => $normalized]);
