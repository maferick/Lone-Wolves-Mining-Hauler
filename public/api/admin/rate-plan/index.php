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

if ($method === 'POST' || $method === 'PUT') {
  $allowedClasses = ['BR', 'DST', 'FREIGHTER', 'JF'];
  $isUpdate = isset($payload['rate_plan_id']) && (int)$payload['rate_plan_id'] > 0;
  $errors = [];

  if ($isUpdate) {
    $ratePlanId = (int)$payload['rate_plan_id'];
    if (!array_key_exists('service_class', $payload)) {
      $errors['service_class'] = 'required';
    }
    if (!array_key_exists('rate_per_jump', $payload)) {
      $errors['rate_per_jump'] = 'required';
    }
    if (!array_key_exists('collateral_rate', $payload)) {
      $errors['collateral_rate'] = 'required';
    }
    if (!array_key_exists('min_price', $payload)) {
      $errors['min_price'] = 'required';
    }
  } else {
    if (empty($payload['service_class'])) {
      $errors['service_class'] = 'required';
    }
    if (!array_key_exists('rate_per_jump', $payload)) {
      $errors['rate_per_jump'] = 'required';
    }
    if (!array_key_exists('collateral_rate', $payload)) {
      $errors['collateral_rate'] = 'required';
    }
    if (!array_key_exists('min_price', $payload)) {
      $errors['min_price'] = 'required';
    }
  }

  if (!empty($payload['service_class'])) {
    $serviceClass = strtoupper(trim((string)$payload['service_class']));
    if (!in_array($serviceClass, $allowedClasses, true)) {
      $errors['service_class'] = 'invalid';
    }
  }

  if (!empty($errors)) {
    api_send_json(['ok' => false, 'error' => 'validation_error', 'details' => $errors], 400);
  }

  $ratePerJump = (float)($payload['rate_per_jump'] ?? 0);
  $collateralRate = (float)($payload['collateral_rate'] ?? 0);
  $minPrice = (float)($payload['min_price'] ?? 0);
  if ($ratePerJump < 0 || $collateralRate < 0 || $minPrice < 0) {
    $details = [];
    if ($ratePerJump < 0) {
      $details['rate_per_jump'] = 'must be non-negative';
    }
    if ($collateralRate < 0) {
      $details['collateral_rate'] = 'must be non-negative';
    }
    if ($minPrice < 0) {
      $details['min_price'] = 'must be non-negative';
    }
    api_send_json(['ok' => false, 'error' => 'validation_error', 'details' => $details], 400);
  }

  if ($isUpdate) {
    $serviceClass = strtoupper(trim((string)$payload['service_class']));
    $db->execute(
      "UPDATE rate_plan
         SET service_class = :service_class,
             rate_per_jump = :rate_per_jump,
             collateral_rate = :collateral_rate,
             min_price = :min_price
       WHERE rate_plan_id = :id",
      [
        'id' => $ratePlanId,
        'service_class' => $serviceClass,
        'rate_per_jump' => $ratePerJump,
        'collateral_rate' => $collateralRate,
        'min_price' => $minPrice,
      ]
    );

    $db->audit(
      (int)($authCtx['corp_id'] ?? null),
      (int)($authCtx['user_id'] ?? 0) ?: null,
      (int)($authCtx['character_id'] ?? 0) ?: null,
      'rate_plan.update',
      'rate_plan',
      (string)$ratePlanId,
      null,
      [
        'rate_plan_id' => $ratePlanId,
        'service_class' => $serviceClass,
        'rate_per_jump' => $ratePerJump,
        'collateral_rate' => $collateralRate,
        'min_price' => $minPrice,
      ],
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null
    );

    $row = $db->one(
      "SELECT rate_plan_id, corp_id, service_class, rate_per_jump, collateral_rate, min_price, updated_at
         FROM rate_plan
        WHERE rate_plan_id = :id",
      ['id' => $ratePlanId]
    );
    api_send_json(['ok' => true, 'rate_plan' => $row]);
  }

  $corpId = (int)($payload['corp_id'] ?? 0);
  $serviceClass = strtoupper(trim((string)($payload['service_class'] ?? '')));

  $ratePlanId = $db->insert('rate_plan', [
    'corp_id' => $corpId,
    'service_class' => $serviceClass,
    'rate_per_jump' => $ratePerJump,
    'collateral_rate' => $collateralRate,
    'min_price' => $minPrice,
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
      'rate_per_jump' => $ratePerJump,
      'collateral_rate' => $collateralRate,
      'min_price' => $minPrice,
    ],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  $row = $db->one(
    "SELECT rate_plan_id, corp_id, service_class, rate_per_jump, collateral_rate, min_price, updated_at
       FROM rate_plan
      WHERE rate_plan_id = :id",
    ['id' => $ratePlanId]
  );

  api_send_json(['ok' => true, 'rate_plan' => $row], 201);
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

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
error_log(sprintf('Unsupported method %s for %s', $method, $requestUri));
api_send_json(['ok' => false, 'error' => 'Unsupported method'], 405);
