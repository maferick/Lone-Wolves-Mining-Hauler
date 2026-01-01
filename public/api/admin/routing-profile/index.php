<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
    ['cid' => $corpId]
  );
  $profile = 'shortest';
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], null);
    if (is_array($decoded) && isset($decoded['profile'])) {
      $profile = (string)$decoded['profile'];
    } elseif (is_string($decoded)) {
      $profile = $decoded;
    }
  }
  api_send_json(['ok' => true, 'corp_id' => $corpId, 'profile' => $profile]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$profile = strtolower(trim((string)($payload['profile'] ?? '')));
$allowed = ['shortest', 'balanced', 'safest'];
if (!in_array($profile, $allowed, true)) {
  api_send_json(['ok' => false, 'error' => 'Invalid profile'], 400);
}

$settingJson = Db::jsonEncode(['profile' => $profile]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'routing.default_profile', :json)
   ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json)",
  [
    'cid' => $corpId,
    'json' => $settingJson,
  ]
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'profile' => $profile]);
