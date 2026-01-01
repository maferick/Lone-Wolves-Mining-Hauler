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
    "SELECT dnf_rule_id, scope_type, id_a, id_b, severity, is_hard_block, reason, active, created_at, updated_at
       FROM dnf_rule
       {$where}
      ORDER BY dnf_rule_id DESC",
    $params
  );

  api_send_json(['ok' => true, 'rules' => $rows]);
}

$payload = api_read_json();

if ($method === 'POST') {
  $scope = strtolower(trim((string)($payload['scope_type'] ?? '')));
  $allowedScopes = ['system', 'constellation', 'region', 'edge'];
  if (!in_array($scope, $allowedScopes, true)) {
    api_send_json(['ok' => false, 'error' => 'Invalid scope_type'], 400);
  }

  $idA = (int)($payload['id_a'] ?? 0);
  $idB = isset($payload['id_b']) ? (int)$payload['id_b'] : null;
  if ($idA <= 0 || ($scope === 'edge' && ($idB === null || $idB <= 0))) {
    api_send_json(['ok' => false, 'error' => 'Invalid ids'], 400);
  }

  $severity = (int)($payload['severity'] ?? 1);
  if ($severity <= 0) {
    $severity = 1;
  }

  $isHard = !empty($payload['is_hard_block']) ? 1 : 0;
  $active = array_key_exists('active', $payload) ? (!empty($payload['active']) ? 1 : 0) : 1;
  $reason = trim((string)($payload['reason'] ?? ''));

  $ruleId = $db->insert('dnf_rule', [
    'scope_type' => $scope,
    'id_a' => $idA,
    'id_b' => $idB,
    'severity' => $severity,
    'is_hard_block' => $isHard,
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
