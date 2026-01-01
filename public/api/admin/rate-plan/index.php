<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
  $corpId = (int)($_GET['corp_id'] ?? 0);
  $rows = $db->select(
    "SELECT rate_plan_id, corp_id, service_class, rate_per_jump, collateral_rate, min_price, updated_at
       FROM rate_plan
      WHERE corp_id = :cid
      ORDER BY service_class",
    ['cid' => $corpId]
  );
  api_send_json(['ok' => true, 'rate_plans' => $rows]);
}

$payload = api_read_json();

if ($method === 'POST') {
  $corpId = (int)($payload['corp_id'] ?? 0);
  $serviceClass = strtoupper(trim((string)($payload['service_class'] ?? '')));
  $allowed = ['BR', 'DST', 'FREIGHTER', 'JF'];
  if (!in_array($serviceClass, $allowed, true)) {
    api_send_json(['ok' => false, 'error' => 'Invalid service_class'], 400);
  }

  $ratePlanId = $db->insert('rate_plan', [
    'corp_id' => $corpId,
    'service_class' => $serviceClass,
    'rate_per_jump' => (float)($payload['rate_per_jump'] ?? 0),
    'collateral_rate' => (float)($payload['collateral_rate'] ?? 0),
    'min_price' => (float)($payload['min_price'] ?? 0),
  ]);

  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'rate_plan.create',
    'rate_plan',
    (string)$ratePlanId,
    null,
    [
      'corp_id' => $corpId,
      'service_class' => $serviceClass,
      'rate_per_jump' => (float)($payload['rate_per_jump'] ?? 0),
      'collateral_rate' => (float)($payload['collateral_rate'] ?? 0),
      'min_price' => (float)($payload['min_price'] ?? 0),
    ],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  api_send_json(['ok' => true, 'rate_plan_id' => $ratePlanId], 201);
}

if ($method === 'PUT') {
  $ratePlanId = (int)($payload['rate_plan_id'] ?? 0);
  if ($ratePlanId <= 0) {
    api_send_json(['ok' => false, 'error' => 'rate_plan_id required'], 400);
  }

  $fields = [];
  $params = ['id' => $ratePlanId];

  if (isset($payload['service_class'])) {
    $serviceClass = strtoupper(trim((string)$payload['service_class']));
    $allowed = ['BR', 'DST', 'FREIGHTER', 'JF'];
    if (!in_array($serviceClass, $allowed, true)) {
      api_send_json(['ok' => false, 'error' => 'Invalid service_class'], 400);
    }
    $fields[] = 'service_class = :service_class';
    $params['service_class'] = $serviceClass;
  }
  if (array_key_exists('rate_per_jump', $payload)) {
    $fields[] = 'rate_per_jump = :rate_per_jump';
    $params['rate_per_jump'] = (float)$payload['rate_per_jump'];
  }
  if (array_key_exists('collateral_rate', $payload)) {
    $fields[] = 'collateral_rate = :collateral_rate';
    $params['collateral_rate'] = (float)$payload['collateral_rate'];
  }
  if (array_key_exists('min_price', $payload)) {
    $fields[] = 'min_price = :min_price';
    $params['min_price'] = (float)$payload['min_price'];
  }

  if ($fields === []) {
    api_send_json(['ok' => false, 'error' => 'No fields to update'], 400);
  }

  $sql = "UPDATE rate_plan SET " . implode(', ', $fields) . " WHERE rate_plan_id = :id";
  $db->execute($sql, $params);

  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'rate_plan.update',
    'rate_plan',
    (string)$ratePlanId,
    null,
    $params,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  api_send_json(['ok' => true]);
}

if ($method === 'DELETE') {
  $ratePlanId = (int)($_GET['rate_plan_id'] ?? $payload['rate_plan_id'] ?? 0);
  if ($ratePlanId <= 0) {
    api_send_json(['ok' => false, 'error' => 'rate_plan_id required'], 400);
  }
  $db->execute(
    "DELETE FROM rate_plan WHERE rate_plan_id = :id",
    ['id' => $ratePlanId]
  );
  $db->audit(
    (int)($authCtx['corp_id'] ?? null),
    (int)($authCtx['user_id'] ?? 0) ?: null,
    (int)($authCtx['character_id'] ?? 0) ?: null,
    'rate_plan.delete',
    'rate_plan',
    (string)$ratePlanId,
    null,
    null,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );
  api_send_json(['ok' => true]);
}

api_send_json(['ok' => false, 'error' => 'Unsupported method'], 405);
