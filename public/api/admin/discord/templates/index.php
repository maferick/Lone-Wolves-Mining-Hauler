<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $rows = $db->select(
    "SELECT * FROM discord_template WHERE corp_id = :cid",
    ['cid' => $corpId]
  );
  api_send_json(['ok' => true, 'templates' => $rows]);
}

$eventKey = trim((string)($data['event_key'] ?? ''));
if ($eventKey === '') {
  api_send_json(['ok' => false, 'error' => 'event_key required'], 400);
}

$titleTemplate = (string)($data['title_template'] ?? '');
$bodyTemplate = (string)($data['body_template'] ?? '');
$footerTemplate = (string)($data['footer_template'] ?? '');

$db->execute(
  "INSERT INTO discord_template
    (corp_id, event_key, title_template, body_template, footer_template)
   VALUES
    (:cid, :event_key, :title_template, :body_template, :footer_template)
   ON DUPLICATE KEY UPDATE
    title_template = VALUES(title_template),
    body_template = VALUES(body_template),
    footer_template = VALUES(footer_template),
    updated_at = UTC_TIMESTAMP()",
  [
    'cid' => $corpId,
    'event_key' => $eventKey,
    'title_template' => $titleTemplate,
    'body_template' => $bodyTemplate,
    'footer_template' => $footerTemplate,
  ]
);

api_send_json(['ok' => true]);
