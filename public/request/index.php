<?php
declare(strict_types=1);

// Standalone request endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

use App\Db\Db;

$appName = $config['app']['name'];
$title = $appName . ' • Contract Instructions';
$basePathForViews = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$apiKey = (string)($config['security']['api_key'] ?? '');

\App\Auth\Auth::requireLogin($authCtx);
$requestKey = trim((string)($_GET['request_key'] ?? ''));
$error = null;
$request = null;
$routeSummary = '';
$issuerName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
$shipClassLabel = '';
$shipClassMax = 0.0;
$contractDescription = '';
$contractAttachEnabled = true;

if ($requestKey === '') {
  $error = 'Request key is required.';
} elseif ($db === null || !($health['db'] ?? false)) {
  $error = 'Database unavailable.';
} else {
  $hasContractStatusEsi = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_status_esi'");
  $contractStatusEsiSelect = $hasContractStatusEsi ? 'contract_status_esi' : 'NULL AS contract_status_esi';
  $hasContractState = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
  $contractStateSelect = $hasContractState ? 'contract_state' : 'NULL AS contract_state';
  $hasContractAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_acceptor_name'");
  $contractAcceptorNameSelect = $hasContractAcceptorName ? 'contract_acceptor_name' : 'NULL AS contract_acceptor_name';
  $hasContractHintText = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_hint_text'");
  $contractHintTextSelect = $hasContractHintText ? 'contract_hint_text' : 'NULL AS contract_hint_text';
  $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'mismatch_reason_json'");
  $mismatchSelect = $hasMismatchJson ? 'mismatch_reason_json' : 'NULL AS mismatch_reason_json';
  $hasContractMatchedAt = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_matched_at'");
  $contractMatchedAtSelect = $hasContractMatchedAt ? 'contract_matched_at' : 'NULL AS contract_matched_at';
  $hasDateAccepted = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'date_accepted'");
  $dateAcceptedSelect = $hasDateAccepted ? 'date_accepted' : 'NULL AS date_accepted';
  $hasDateCompleted = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'date_completed'");
  $dateCompletedSelect = $hasDateCompleted ? 'date_completed' : 'NULL AS date_completed';
  $hasDateExpired = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'date_expired'");
  $dateExpiredSelect = $hasDateExpired ? 'date_expired' : 'NULL AS date_expired';
  $request = $db->one(
    "SELECT request_id, corp_id, requester_user_id, from_location_id, to_location_id, reward_isk, collateral_isk, volume_m3,
            ship_class, route_policy, route_profile, price_breakdown_json, quote_id, status, contract_id, contract_status,
            {$contractStatusEsiSelect}, {$contractStateSelect}, {$contractAcceptorNameSelect}, {$contractHintTextSelect}, {$mismatchSelect},
            {$contractMatchedAtSelect}, {$dateAcceptedSelect}, {$dateCompletedSelect}, {$dateExpiredSelect}, request_key
       FROM haul_request
      WHERE request_key = :rkey
      LIMIT 1",
    ['rkey' => $requestKey]
  );

  if (!$request) {
    $error = 'Request not found.';
  } else {
    $corpId = (int)$request['corp_id'];
    $canRead = !empty($authCtx['user_id'])
      && (\App\Auth\Auth::can($authCtx, 'haul.request.read') || (int)$request['requester_user_id'] === (int)$authCtx['user_id']);
    if (!$canRead) {
      http_response_code(403);
      $error = 'You do not have access to this request.';
    } else {
      $attachRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.attach_enabled' LIMIT 1",
        ['cid' => $corpId]
      );
      $attachSetting = $attachRow && !empty($attachRow['setting_json'])
        ? Db::jsonDecode((string)$attachRow['setting_json'], [])
        : [];
      if (is_array($attachSetting) && array_key_exists('enabled', $attachSetting)) {
        $contractAttachEnabled = (bool)$attachSetting['enabled'];
      }

      $quote = null;
      if (!empty($request['quote_id'])) {
        $quote = $db->one(
          "SELECT route_json, breakdown_json FROM quote WHERE quote_id = :qid LIMIT 1",
          ['qid' => (int)$request['quote_id']]
        );
      }
      $route = $quote && !empty($quote['route_json']) ? json_decode((string)$quote['route_json'], true) : [];
      $path = is_array($route['path'] ?? null) ? $route['path'] : [];
      if ($path) {
        $first = $path[0]['system_name'] ?? 'Start';
        $last = $path[count($path) - 1]['system_name'] ?? 'Destination';
        $routeSummary = trim((string)$first) . ' → ' . trim((string)$last);
      } else {
        $routeSummary = 'Route unavailable';
      }

      $breakdown = [];
      if (!empty($request['price_breakdown_json'])) {
        $breakdown = json_decode((string)$request['price_breakdown_json'], true);
      } elseif ($quote && !empty($quote['breakdown_json'])) {
        $breakdown = json_decode((string)$quote['breakdown_json'], true);
      }

      $shipClass = (string)($breakdown['ship_class']['service_class'] ?? ($request['ship_class'] ?? ''));
      $shipClassMax = (float)($breakdown['ship_class']['max_volume'] ?? 0);
      $shipClassLabel = $shipClass !== '' ? $shipClass : 'N/A';

      $hintText = trim((string)($request['contract_hint_text'] ?? ''));
      if ($hintText === '' && !empty($request['request_key'])) {
        $hintText = 'Quote ' . (string)$request['request_key'];
      } elseif ($hintText === '' && !empty($request['quote_id'])) {
        $hintText = 'Quote #' . (string)$request['quote_id'];
      }
      if ($hintText === '') {
        $hintText = 'Request #' . (string)$request['request_id'];
      }
      $contractDescription = $hintText;
    }
  }
}

require __DIR__ . '/../../src/Views/request.php';
