<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

api_require_key();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$method = strtoupper($method);

if ($method === 'GET') {
  $activeOnly = isset($_GET['active']) ? (int)$_GET['active'] === 1 : null;
  $params = [];
  $where = '';
  if ($activeOnly !== null) {
    $where = 'WHERE active = :active';
    $params['active'] = $activeOnly ? 1 : 0;
  }

  $rows = $db->select(
    "SELECT dnf_rule.dnf_rule_id,
            dnf_rule.scope_type,
            dnf_rule.id_a,
            dnf_rule.id_b,
            dnf_rule.severity,
            dnf_rule.is_hard_block,
            dnf_rule.apply_pickup,
            dnf_rule.apply_delivery,
            dnf_rule.apply_transit,
            dnf_rule.reason,
            dnf_rule.active,
            dnf_rule.created_at,
            dnf_rule.updated_at,
            CASE
              WHEN dnf_rule.scope_type = 'system' THEN sys_a.system_name
              WHEN dnf_rule.scope_type = 'constellation' THEN const_a.constellation_name
              WHEN dnf_rule.scope_type = 'region' THEN reg_a.region_name
              WHEN dnf_rule.scope_type = 'edge' THEN sys_a.system_name
              ELSE NULL
            END AS name_a,
            CASE
              WHEN dnf_rule.scope_type = 'edge' THEN sys_b.system_name
              ELSE NULL
            END AS name_b
       FROM dnf_rule
       LEFT JOIN eve_system sys_a
         ON sys_a.system_id = dnf_rule.id_a
        AND dnf_rule.scope_type IN ('system', 'edge')
       LEFT JOIN eve_system sys_b
         ON sys_b.system_id = dnf_rule.id_b
        AND dnf_rule.scope_type = 'edge'
       LEFT JOIN eve_constellation const_a
         ON const_a.constellation_id = dnf_rule.id_a
        AND dnf_rule.scope_type = 'constellation'
       LEFT JOIN eve_region reg_a
         ON reg_a.region_id = dnf_rule.id_a
        AND dnf_rule.scope_type = 'region'
       {$where}
      ORDER BY dnf_rule.dnf_rule_id DESC",
    $params
  );

  api_send_json(['ok' => true, 'rules' => $rows]);
}

$payload = api_read_json();
$allowedScopes = ['system', 'constellation', 'region', 'edge'];

$lookupId = function (string $scope, string $name) use ($db): int {
  $name = trim($name);
  if ($name === '') {
    api_send_json(['ok' => false, 'error' => 'Invalid name'], 400);
  }

  switch ($scope) {
    case 'system':
      $table = 'eve_system';
      $idColumn = 'system_id';
      $nameColumn = 'system_name';
      break;
    case 'constellation':
      $table = 'eve_constellation';
      $idColumn = 'constellation_id';
      $nameColumn = 'constellation_name';
      break;
    case 'region':
      $table = 'eve_region';
      $idColumn = 'region_id';
      $nameColumn = 'region_name';
      break;
    default:
      api_send_json(['ok' => false, 'error' => 'Invalid scope_type'], 400);
  }

  $rows = $db->select(
    "SELECT {$idColumn} AS id FROM {$table} WHERE LOWER({$nameColumn}) = LOWER(:name) LIMIT 2",
    ['name' => $name]
  );
  if (!$rows) {
    api_send_json(['ok' => false, 'error' => "Unknown name for scope {$scope}"], 400);
  }
  if (count($rows) > 1) {
    api_send_json(['ok' => false, 'error' => "Ambiguous name for scope {$scope}"], 400);
  }

  return (int)$rows[0]['id'];
};

if ($method === 'POST') {
  $scope = strtolower(trim((string)($payload['scope_type'] ?? '')));
  if (!in_array($scope, $allowedScopes, true)) {
    api_send_json(['ok' => false, 'error' => 'Invalid scope_type'], 400);
  }

  $idA = (int)($payload['id_a'] ?? 0);
  $nameA = trim((string)($payload['name_a'] ?? ''));
  if ($idA <= 0) {
    $lookupScope = $scope === 'edge' ? 'system' : $scope;
    $idA = $lookupId($lookupScope, $nameA);
  }

  $idB = isset($payload['id_b']) ? (int)$payload['id_b'] : null;
  $nameB = trim((string)($payload['name_b'] ?? ''));
  if ($scope === 'edge') {
    if ($idB === null || $idB <= 0) {
      $idB = $lookupId('system', $nameB);
    }
  }

  if ($idA <= 0 || ($scope === 'edge' && ($idB === null || $idB <= 0))) {
    api_send_json(['ok' => false, 'error' => 'Invalid ids'], 400);
  }

  $severity = (int)($payload['severity'] ?? 1);
  if ($severity <= 0) {
    $severity = 1;
  }

  $isHard = !empty($payload['is_hard_block']) ? 1 : 0;
  $applyPickup = array_key_exists('apply_pickup', $payload) ? (!empty($payload['apply_pickup']) ? 1 : 0) : 1;
  $applyDelivery = array_key_exists('apply_delivery', $payload) ? (!empty($payload['apply_delivery']) ? 1 : 0) : 1;
  $applyTransit = array_key_exists('apply_transit', $payload) ? (!empty($payload['apply_transit']) ? 1 : 0) : 1;
  $active = array_key_exists('active', $payload) ? (!empty($payload['active']) ? 1 : 0) : 1;
  $reason = trim((string)($payload['reason'] ?? ''));

  $ruleId = $db->insert('dnf_rule', [
    'scope_type' => $scope,
    'id_a' => $idA,
    'id_b' => $idB,
    'severity' => $severity,
    'is_hard_block' => $isHard,
    'apply_pickup' => $applyPickup,
    'apply_delivery' => $applyDelivery,
    'apply_transit' => $applyTransit,
    'reason' => $reason !== '' ? $reason : null,
    'active' => $active,
  ]);

  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'dnf.create',
    'dnf_rule',
    (string)$ruleId,
    null,
    [
      'scope_type' => $scope,
      'id_a' => $idA,
      'id_b' => $idB,
      'severity' => $severity,
      'is_hard_block' => $isHard,
      'apply_pickup' => $applyPickup,
      'apply_delivery' => $applyDelivery,
      'apply_transit' => $applyTransit,
      'reason' => $reason,
      'active' => $active,
    ],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  api_send_json(['ok' => true, 'dnf_rule_id' => $ruleId], 201);
}

if ($method === 'PUT') {
  $ruleId = (int)($payload['dnf_rule_id'] ?? 0);
  if ($ruleId <= 0) {
    api_send_json(['ok' => false, 'error' => 'dnf_rule_id required'], 400);
  }

  $fields = [];
  $params = ['id' => $ruleId];

  if (isset($payload['scope_type'])) {
    $scope = strtolower(trim((string)$payload['scope_type']));
    $allowedScopes = ['system', 'constellation', 'region', 'edge'];
    if (!in_array($scope, $allowedScopes, true)) {
      api_send_json(['ok' => false, 'error' => 'Invalid scope_type'], 400);
    }
    $fields[] = 'scope_type = :scope_type';
    $params['scope_type'] = $scope;
  }

  if (isset($payload['id_a'])) {
    $fields[] = 'id_a = :id_a';
    $params['id_a'] = (int)$payload['id_a'];
  }
  if (array_key_exists('id_b', $payload)) {
    $fields[] = 'id_b = :id_b';
    $params['id_b'] = $payload['id_b'] !== null ? (int)$payload['id_b'] : null;
  }
  if (isset($payload['severity'])) {
    $severity = (int)$payload['severity'];
    if ($severity <= 0) {
      $severity = 1;
    }
    $fields[] = 'severity = :severity';
    $params['severity'] = $severity;
  }
  if (array_key_exists('is_hard_block', $payload)) {
    $fields[] = 'is_hard_block = :is_hard_block';
    $params['is_hard_block'] = !empty($payload['is_hard_block']) ? 1 : 0;
  }
  if (array_key_exists('apply_pickup', $payload)) {
    $fields[] = 'apply_pickup = :apply_pickup';
    $params['apply_pickup'] = !empty($payload['apply_pickup']) ? 1 : 0;
  }
  if (array_key_exists('apply_delivery', $payload)) {
    $fields[] = 'apply_delivery = :apply_delivery';
    $params['apply_delivery'] = !empty($payload['apply_delivery']) ? 1 : 0;
  }
  if (array_key_exists('apply_transit', $payload)) {
    $fields[] = 'apply_transit = :apply_transit';
    $params['apply_transit'] = !empty($payload['apply_transit']) ? 1 : 0;
  }
  if (array_key_exists('reason', $payload)) {
    $fields[] = 'reason = :reason';
    $reason = trim((string)$payload['reason']);
    $params['reason'] = $reason !== '' ? $reason : null;
  }
  if (array_key_exists('active', $payload)) {
    $fields[] = 'active = :active';
    $params['active'] = !empty($payload['active']) ? 1 : 0;
  }

  if ($fields === []) {
    api_send_json(['ok' => false, 'error' => 'No fields to update'], 400);
  }

  $sql = "UPDATE dnf_rule SET " . implode(', ', $fields) . " WHERE dnf_rule_id = :id";
  $db->execute($sql, $params);

  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'dnf.update',
    'dnf_rule',
    (string)$ruleId,
    null,
    $params,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  api_send_json(['ok' => true]);
}

if ($method === 'DELETE') {
  $ruleId = (int)($_GET['dnf_rule_id'] ?? $payload['dnf_rule_id'] ?? 0);
  if ($ruleId <= 0) {
    api_send_json(['ok' => false, 'error' => 'dnf_rule_id required'], 400);
  }
  $db->execute(
    "UPDATE dnf_rule SET active = 0 WHERE dnf_rule_id = :id",
    ['id' => $ruleId]
  );
  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'dnf.disable',
    'dnf_rule',
    (string)$ruleId,
    null,
    ['active' => 0],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );
  api_send_json(['ok' => true]);
}

api_send_json(['ok' => false, 'error' => 'Unsupported method'], 405);
