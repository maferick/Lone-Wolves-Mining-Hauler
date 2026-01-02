<?php
declare(strict_types=1);

// Standalone rates endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Rates';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$corpId = (int)($config['corp']['id'] ?? 0);

$ratePlans = $db->all(
  "SELECT service_class, rate_per_jump, collateral_rate, min_price, updated_at
     FROM rate_plan
    WHERE corp_id = :cid
    ORDER BY FIELD(service_class, 'BR', 'DST', 'FREIGHTER', 'JF'), service_class",
  ['cid' => $corpId]
);

$priorityFees = ['normal' => 0.0, 'high' => 0.0];
$priorityRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.priority_fee' LIMIT 1",
  ['cid' => $corpId]
);
if (!$priorityRow) {
  $priorityRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.priority_fee' LIMIT 1"
  );
}
if ($priorityRow && !empty($priorityRow['setting_json'])) {
  $decoded = json_decode((string)$priorityRow['setting_json'], true);
  if (is_array($decoded)) {
    $priorityFees = array_merge($priorityFees, $decoded);
  }
}

$securityMultipliers = ['low' => 0.5, 'null' => 1.0];
$ratesUpdatedAt = null;
foreach ($ratePlans as $plan) {
  if (!empty($plan['updated_at'])) {
    $ratesUpdatedAt = max($ratesUpdatedAt ?? '', (string)$plan['updated_at']);
  }
}

ob_start();
require __DIR__ . '/../../src/Views/rates.php';
$body = ob_get_clean();

require __DIR__ . '/../../src/Views/layout.php';
