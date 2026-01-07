<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = [
  'enabled' => true,
  'detour_budget_jumps' => 5,
  'max_suggestions' => 5,
  'min_free_capacity_percent' => 10,
];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.optimization' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $merged = array_merge($defaults, is_array($setting) ? $setting : []);

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'settings' => [
      'enabled' => !empty($merged['enabled']),
      'detour_budget_jumps' => (int)$merged['detour_budget_jumps'],
      'max_suggestions' => (int)$merged['max_suggestions'],
      'min_free_capacity_percent' => (float)$merged['min_free_capacity_percent'],
    ],
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$enabled = !empty($payload['enabled']);
$detourBudget = max(0, (int)($payload['detour_budget_jumps'] ?? $defaults['detour_budget_jumps']));
$maxSuggestions = max(1, (int)($payload['max_suggestions'] ?? $defaults['max_suggestions']));
$minFreePercent = max(0.0, (float)($payload['min_free_capacity_percent'] ?? $defaults['min_free_capacity_percent']));

$settingJson = Db::jsonEncode([
  'enabled' => $enabled,
  'detour_budget_jumps' => $detourBudget,
  'max_suggestions' => $maxSuggestions,
  'min_free_capacity_percent' => $minFreePercent,
]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'routing.optimization', :json, :uid)
   ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_by_user_id = VALUES(updated_by_user_id)",
  [
    'cid' => $corpId,
    'json' => $settingJson,
    'uid' => (int)($authCtx['user_id'] ?? 0) ?: null,
  ]
);

$db->audit(
  $corpId,
  (int)($authCtx['user_id'] ?? 0) ?: null,
  (int)($authCtx['character_id'] ?? 0) ?: null,
  'settings.routing.optimization.update',
  'app_setting',
  'routing.optimization',
  null,
  [
    'enabled' => $enabled,
    'detour_budget_jumps' => $detourBudget,
    'max_suggestions' => $maxSuggestions,
    'min_free_capacity_percent' => $minFreePercent,
  ],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json([
  'ok' => true,
  'corp_id' => $corpId,
  'settings' => [
    'enabled' => $enabled,
    'detour_budget_jumps' => $detourBudget,
    'max_suggestions' => $maxSuggestions,
    'min_free_capacity_percent' => $minFreePercent,
  ],
]);
