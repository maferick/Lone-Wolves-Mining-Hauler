<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
    ['cid' => $corpId]
  );
  $priority = 'normal';
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], null);
    if (is_array($decoded)) {
      $priority = (string)($decoded['priority'] ?? $decoded['profile'] ?? $priority);
    } elseif (is_string($decoded)) {
      $priority = $decoded;
    }
  }
  $priority = strtolower(trim($priority)) === 'high' ? 'high' : 'normal';
  api_send_json(['ok' => true, 'corp_id' => $corpId, 'priority' => $priority]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$priority = strtolower(trim((string)($payload['priority'] ?? $payload['profile'] ?? '')));
$allowed = ['normal', 'high'];
if (!in_array($priority, $allowed, true)) {
  api_send_json(['ok' => false, 'error' => 'Invalid priority'], 400);
}

$settingJson = Db::jsonEncode(['priority' => $priority]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'routing.default_profile', :json)
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
  'routing.priority.update',
  'app_setting',
  'routing.default_profile',
  null,
  ['priority' => $priority],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'priority' => $priority]);
