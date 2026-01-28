<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);
if (!Auth::hasRole($authCtx, 'admin')) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Contract Compare';

$selectedRequestId = (int)($_GET['request_id'] ?? 0);
$selectedContractId = (int)($_GET['contract_id'] ?? 0);
$requestRow = null;
$contractRow = null;

$hasHaulRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");
$hasContractView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_contract_display'");

$requests = [];
if ($corpId > 0 && $hasHaulRequestView) {
  $requests = $db->select(
    "SELECT request_id, request_key, status,
            from_location_id, to_location_id, from_location_type, to_location_type,
            from_name, to_name, volume_m3, reward_isk, collateral_isk,
            contract_id, esi_contract_id
       FROM v_haul_request_display
      WHERE corp_id = :cid
        AND status NOT IN ('completed', 'failed', 'contract_linked')
        AND (contract_id IS NULL OR contract_id = 0)
        AND (esi_contract_id IS NULL OR esi_contract_id = 0)
      ORDER BY request_id DESC
      LIMIT 200",
    ['cid' => $corpId]
  );
} elseif ($corpId > 0) {
  $requests = $db->select(
    "SELECT request_id, request_key, status,
            from_location_id, to_location_id, from_location_type, to_location_type,
            volume_m3, reward_isk, collateral_isk,
            contract_id, esi_contract_id
       FROM haul_request
      WHERE corp_id = :cid
        AND status NOT IN ('completed', 'failed', 'contract_linked')
        AND (contract_id IS NULL OR contract_id = 0)
        AND (esi_contract_id IS NULL OR esi_contract_id = 0)
      ORDER BY request_id DESC
      LIMIT 200",
    ['cid' => $corpId]
  );
  $requests = array_map(static function (array $row): array {
    $row['from_name'] = (string)($row['from_location_type'] ?? 'location') . ':' . (string)($row['from_location_id'] ?? '');
    $row['to_name'] = (string)($row['to_location_type'] ?? 'location') . ':' . (string)($row['to_location_id'] ?? '');
    return $row;
  }, $requests);
}

$contracts = [];
if ($corpId > 0 && $hasContractView) {
  $contracts = $db->select(
    "SELECT vc.contract_id, vc.status, vc.type, vc.title, vc.start_location_id, vc.end_location_id,
            vc.start_name, vc.end_name, vc.volume_m3, vc.reward_isk, vc.collateral_isk, vc.date_issued
       FROM v_contract_display vc
  LEFT JOIN haul_request hr
         ON hr.corp_id = vc.corp_id
        AND (hr.contract_id = vc.contract_id OR hr.esi_contract_id = vc.contract_id)
      WHERE vc.corp_id = :cid
        AND vc.type = 'courier'
        AND vc.status NOT IN ('finished','deleted','failed')
        AND hr.request_id IS NULL
      ORDER BY vc.date_issued DESC, vc.contract_id DESC
      LIMIT 200",
    ['cid' => $corpId]
  );
} elseif ($corpId > 0) {
  $contracts = $db->select(
    "SELECT cc.contract_id, cc.status, cc.type, cc.title, cc.start_location_id, cc.end_location_id,
            cc.volume_m3, cc.reward_isk, cc.collateral_isk, cc.date_issued
       FROM esi_corp_contract cc
  LEFT JOIN haul_request hr
         ON hr.corp_id = cc.corp_id
        AND (hr.contract_id = cc.contract_id OR hr.esi_contract_id = cc.contract_id)
      WHERE cc.corp_id = :cid
        AND cc.type = 'courier'
        AND cc.status NOT IN ('finished','deleted','failed')
        AND hr.request_id IS NULL
      ORDER BY cc.date_issued DESC, cc.contract_id DESC
      LIMIT 200",
    ['cid' => $corpId]
  );
  $contracts = array_map(static function (array $row): array {
    $row['start_name'] = 'loc:' . (string)($row['start_location_id'] ?? '');
    $row['end_name'] = 'loc:' . (string)($row['end_location_id'] ?? '');
    return $row;
  }, $contracts);
}

if ($selectedRequestId > 0 && $hasHaulRequestView) {
  $requestRow = $db->one(
    "SELECT *
       FROM v_haul_request_display
      WHERE corp_id = :cid AND request_id = :rid
      LIMIT 1",
    ['cid' => $corpId, 'rid' => $selectedRequestId]
  );
} elseif ($selectedRequestId > 0) {
  $requestRow = $db->one(
    "SELECT *
       FROM haul_request
      WHERE corp_id = :cid AND request_id = :rid
      LIMIT 1",
    ['cid' => $corpId, 'rid' => $selectedRequestId]
  );
}
if ($requestRow && empty($requestRow['from_name'])) {
  $requestRow['from_name'] = (string)($requestRow['from_location_type'] ?? 'location') . ':' . (string)($requestRow['from_location_id'] ?? '');
}
if ($requestRow && empty($requestRow['to_name'])) {
  $requestRow['to_name'] = (string)($requestRow['to_location_type'] ?? 'location') . ':' . (string)($requestRow['to_location_id'] ?? '');
}

if ($selectedContractId > 0 && $hasContractView) {
  $contractRow = $db->one(
    "SELECT vc.*
       FROM v_contract_display vc
      WHERE vc.corp_id = :cid AND vc.contract_id = :cid_contract
      LIMIT 1",
    ['cid' => $corpId, 'cid_contract' => $selectedContractId]
  );
} elseif ($selectedContractId > 0) {
  $contractRow = $db->one(
    "SELECT cc.*
       FROM esi_corp_contract cc
      WHERE cc.corp_id = :cid AND cc.contract_id = :cid_contract
      LIMIT 1",
    ['cid' => $corpId, 'cid_contract' => $selectedContractId]
  );
}
if ($contractRow && empty($contractRow['start_name'])) {
  $contractRow['start_name'] = 'loc:' . (string)($contractRow['start_location_id'] ?? '');
}
if ($contractRow && empty($contractRow['end_name'])) {
  $contractRow['end_name'] = 'loc:' . (string)($contractRow['end_location_id'] ?? '');
}

$rewardTolerance = ['type' => 'percent', 'value' => 0.0];
$rewardRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.reward_tolerance' LIMIT 1",
  ['cid' => $corpId]
);
if ($rewardRow && !empty($rewardRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$rewardRow['setting_json'], []);
  $rewardTolerance['type'] = (string)($decoded['type'] ?? 'percent');
  $rewardTolerance['value'] = (float)($decoded['value'] ?? 0.0);
}

$structureOverrideMap = [];
$accessRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.rules' LIMIT 1",
  ['cid' => $corpId]
);
if ($accessRow && !empty($accessRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$accessRow['setting_json'], []);
  if (is_array($decoded)) {
    foreach ($decoded['structures'] ?? [] as $rule) {
      $id = (int)($rule['id'] ?? 0);
      $systemId = (int)($rule['system_id'] ?? 0);
      if ($id > 0 && $systemId > 0) {
        $structureOverrideMap[$id] = $systemId;
      }
    }
  }
}

$resolveSystemId = static function (Db $db, int $locationId, array $overrideMap): int {
  if ($locationId <= 0) {
    return 0;
  }
  if (isset($overrideMap[$locationId])) {
    return (int)$overrideMap[$locationId];
  }
  $row = $db->one(
    "SELECT system_id FROM map_system WHERE system_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  $row = $db->one(
    "SELECT system_id FROM eve_station WHERE station_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  $row = $db->one(
    "SELECT system_id FROM eve_structure WHERE structure_id = :id LIMIT 1",
    ['id' => $locationId]
  );
  if ($row) {
    return (int)$row['system_id'];
  }
  return 0;
};

$resolveSystemName = static function (Db $db, int $systemId): string {
  if ($systemId <= 0) {
    return '';
  }
  $row = $db->one(
    "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
    ['id' => $systemId]
  );
  return $row ? (string)$row['system_name'] : '';
};

$compareMoney = static function (float $actual, float $expected, float $tolerance): bool {
  $epsilon = 0.01;
  return abs($actual - $expected) <= ($tolerance + $epsilon);
};

$rewardToleranceValue = static function (float $expected, array $rewardTolerance): float {
  if (($rewardTolerance['type'] ?? '') === 'flat') {
    return (float)($rewardTolerance['value'] ?? 0.0);
  }
  $percent = (float)($rewardTolerance['value'] ?? 0.0);
  return $expected * $percent;
};

$daysBetween = static function ($issuedAt, $expiredAt): ?int {
  if (!$issuedAt || !$expiredAt) {
    return null;
  }
  $issuedTs = strtotime((string)$issuedAt);
  $expiredTs = strtotime((string)$expiredAt);
  if ($issuedTs === false || $expiredTs === false) {
    return null;
  }
  $diffSeconds = $expiredTs - $issuedTs;
  if ($diffSeconds < 0) {
    return null;
  }
  return (int)round($diffSeconds / 86400);
};

$contractDescription = static function (array $contractRow): string {
  $title = trim((string)($contractRow['title'] ?? ''));
  $decoded = [];
  if (!empty($contractRow['raw_json'])) {
    $decoded = Db::jsonDecode((string)$contractRow['raw_json'], []);
  }
  $desc = trim((string)($decoded['description'] ?? ''));
  if ($desc !== '') {
    return $desc;
  }
  if ($title !== '') {
    return $title;
  }
  return trim((string)($decoded['title'] ?? ''));
};

$expectedExpirationDays = 7;
$expectedDaysToComplete = 2;

$comparison = null;
if ($requestRow && $contractRow) {
  $requestVolume = (float)($requestRow['volume_m3'] ?? 0.0);
  $requestCollateral = (float)($requestRow['collateral_isk'] ?? 0.0);
  $requestReward = (float)($requestRow['reward_isk'] ?? 0.0);
  $requestFromSystem = (int)($requestRow['from_location_id'] ?? 0);
  $requestToSystem = (int)($requestRow['to_location_id'] ?? 0);
  $requestHint = trim((string)($requestRow['contract_hint_text'] ?? ''));
  $priceBreakdown = [];
  if (!empty($requestRow['price_breakdown_json'])) {
    $priceBreakdown = Db::jsonDecode((string)$requestRow['price_breakdown_json'], []);
  }
  $maxVolume = (float)($priceBreakdown['ship_class']['max_volume'] ?? 0.0);

  $contractVolume = (float)($contractRow['volume_m3'] ?? 0.0);
  $contractCollateral = (float)($contractRow['collateral_isk'] ?? 0.0);
  $contractReward = (float)($contractRow['reward_isk'] ?? 0.0);
  $contractType = (string)($contractRow['type'] ?? '');
  $contractStatus = (string)($contractRow['status'] ?? '');
  $startLocationId = (int)($contractRow['start_location_id'] ?? 0);
  $endLocationId = (int)($contractRow['end_location_id'] ?? 0);
  $contractDaysToComplete = isset($contractRow['days_to_complete']) ? (int)$contractRow['days_to_complete'] : null;
  $contractIssuedAt = $contractRow['date_issued'] ?? null;
  $contractExpiredAt = $contractRow['date_expired'] ?? null;
  $contractDescriptionText = $contractDescription($contractRow);

  $startSystemId = $resolveSystemId($db, $startLocationId, $structureOverrideMap);
  $endSystemId = $resolveSystemId($db, $endLocationId, $structureOverrideMap);
  $startSystemName = $resolveSystemName($db, $startSystemId);
  $endSystemName = $resolveSystemName($db, $endSystemId);

  $rewardToleranceAmount = $rewardToleranceValue($requestReward, $rewardTolerance);

  $comparison = [
    'context' => [
      'start_system_id' => $startSystemId,
      'end_system_id' => $endSystemId,
      'start_system_name' => $startSystemName,
      'end_system_name' => $endSystemName,
      'start_override' => isset($structureOverrideMap[$startLocationId]),
      'end_override' => isset($structureOverrideMap[$endLocationId]),
      'reward_tolerance' => $rewardTolerance,
      'reward_tolerance_amount' => $rewardToleranceAmount,
    ],
    'checks' => [
      [
        'label' => 'Contract type',
        'expected' => 'courier',
        'actual' => $contractType !== '' ? $contractType : 'unknown',
        'ok' => $contractType === 'courier',
      ],
      [
        'label' => 'Contract status',
        'expected' => 'outstanding | in_progress',
        'actual' => $contractStatus !== '' ? $contractStatus : 'unknown',
        'ok' => in_array($contractStatus, ['outstanding', 'in_progress'], true),
      ],
      [
        'label' => 'Pickup system',
        'expected' => $requestFromSystem,
        'actual' => $startSystemId,
        'ok' => $startSystemId === $requestFromSystem,
      ],
      [
        'label' => 'Delivery system',
        'expected' => $requestToSystem,
        'actual' => $endSystemId,
        'ok' => $endSystemId === $requestToSystem,
      ],
      [
        'label' => 'Volume (m3)',
        'expected' => $maxVolume > 0.0 ? $maxVolume : $requestVolume,
        'actual' => $contractVolume,
        'ok' => $maxVolume > 0.0 ? ($contractVolume <= ($maxVolume + 0.01)) : true,
      ],
      [
        'label' => 'Collateral (ISK)',
        'expected' => $requestCollateral,
        'actual' => $contractCollateral,
        'ok' => $compareMoney($contractCollateral, $requestCollateral, 0.0),
      ],
      [
        'label' => 'Reward (ISK)',
        'expected' => $requestReward,
        'actual' => $contractReward,
        'ok' => $compareMoney($contractReward, $requestReward, $rewardToleranceAmount),
      ],
      [
        'label' => 'Days to complete',
        'expected' => $expectedDaysToComplete,
        'actual' => $contractDaysToComplete,
        'ok' => $contractDaysToComplete === $expectedDaysToComplete,
      ],
      [
        'label' => 'Expiration days',
        'expected' => $expectedExpirationDays,
        'actual' => $daysBetween($contractIssuedAt, $contractExpiredAt),
        'ok' => $daysBetween($contractIssuedAt, $contractExpiredAt) === $expectedExpirationDays,
      ],
      [
        'label' => 'Description contains hint',
        'expected' => $requestHint !== '' ? $requestHint : 'non-empty hint',
        'actual' => $contractDescriptionText,
        'ok' => $requestHint !== '' && $contractDescriptionText !== '' && str_contains($contractDescriptionText, $requestHint),
      ],
    ],
  ];
}

$formatIsk = static function (float $value): string {
  return number_format($value, 2, '.', ',');
};

$formatNumber = static function (float $value): string {
  return number_format($value, 2, '.', ',');
};

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="stack">
  <div class="card">
    <div class="card-header">
      <h2>Contract vs Quote Compare</h2>
      <p class="muted">Pick a haul request and a courier contract to see why they do or do not match.</p>
    </div>
    <div class="content js-contract-compare" data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>">
      <form method="get" class="stack">
        <div>
          <div class="label">Haul request</div>
          <select name="request_id" class="input" required>
            <option value="">Select a request</option>
            <?php foreach ($requests as $row): ?>
              <?php
                $requestId = (int)($row['request_id'] ?? 0);
                $label = '#' . $requestId;
                $requestKey = trim((string)($row['request_key'] ?? ''));
                if ($requestKey !== '') {
                  $label .= ' • ' . $requestKey;
                }
                $route = trim((string)($row['from_name'] ?? '')) . ' → ' . trim((string)($row['to_name'] ?? ''));
                $status = (string)($row['status'] ?? '');
                $selected = $requestId === $selectedRequestId ? 'selected' : '';
              ?>
              <option value="<?= $requestId ?>" <?= $selected ?>>
                <?= htmlspecialchars($label . ' • ' . $route . ($status !== '' ? ' (' . $status . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="label">ESI contract</div>
          <select name="contract_id" class="input" required>
            <option value="">Select a contract</option>
            <?php foreach ($contracts as $row): ?>
              <?php
                $contractId = (int)($row['contract_id'] ?? 0);
                $route = trim((string)($row['start_name'] ?? '')) . ' → ' . trim((string)($row['end_name'] ?? ''));
                $status = (string)($row['status'] ?? '');
                $contractTitle = trim((string)($row['title'] ?? ''));
                $selected = $contractId === $selectedContractId ? 'selected' : '';
                $labelParts = ['#' . $contractId];
                if ($contractTitle !== '') {
                  $labelParts[] = $contractTitle;
                }
                $labelParts[] = $route;
                $label = implode(' • ', $labelParts);
              ?>
              <option value="<?= $contractId ?>" <?= $selected ?>>
                <?= htmlspecialchars($label . ($status !== '' ? ' (' . $status . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="row" style="gap:8px; align-items:center;">
            <button class="btn" type="submit">Compare</button>
            <button class="btn ghost js-run-contract-match" type="button">Run link now</button>
          </div>
          <div class="muted js-contract-match-status" aria-live="polite"></div>
        </div>
      </form>
    </div>
  </div>

  <?php if ($comparison && $requestRow && $contractRow): ?>
    <div class="grid">
      <div class="card">
        <div class="card-header">
          <h2>Match results</h2>
          <p class="muted">Review each check to see the exact mismatch reason.</p>
        </div>
        <div class="content">
          <div class="contract-checklist">
            <?php foreach ($comparison['checks'] as $check): ?>
              <div class="contract-check">
                <span class="status-pill <?= $check['ok'] ? 'status-success' : 'status-failed' ?>">
                  <?= $check['ok'] ? 'PASS' : 'FAIL' ?>
                </span>
                <div>
                  <div><strong><?= htmlspecialchars((string)$check['label'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <div class="muted">Expected: <?= htmlspecialchars((string)$check['expected'], ENT_QUOTES, 'UTF-8') ?> · Actual: <?= htmlspecialchars((string)$check['actual'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="muted">
            Reward tolerance:
            <?= htmlspecialchars((string)($comparison['context']['reward_tolerance']['type'] ?? 'percent'), ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars((string)($comparison['context']['reward_tolerance']['value'] ?? 0), ENT_QUOTES, 'UTF-8') ?>)
            → <?= htmlspecialchars($formatIsk((float)($comparison['context']['reward_tolerance_amount'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> ISK
          </div>
        </div>
      </div>

      <div class="stack">
        <div class="card">
          <div class="card-header">
            <h2>Request details</h2>
            <p class="muted">Quoted route and expected values.</p>
          </div>
          <div class="content">
            <div><strong>#<?= (int)($requestRow['request_id'] ?? 0) ?></strong></div>
            <div class="muted"><?= htmlspecialchars((string)($requestRow['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string)($requestRow['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="contract-meta">
              <span class="pill"><?= htmlspecialchars((string)($requestRow['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <?php if (!empty($requestRow['request_key'])): ?>
                <span class="pill subtle"><?= htmlspecialchars((string)$requestRow['request_key'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <ul class="list">
              <li><span class="badge">V</span> <?= htmlspecialchars($formatNumber((float)($requestRow['volume_m3'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> m3</li>
              <li><span class="badge">C</span> <?= htmlspecialchars($formatIsk((float)($requestRow['collateral_isk'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> ISK collateral</li>
              <li><span class="badge">R</span> <?= htmlspecialchars($formatIsk((float)($requestRow['reward_isk'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> ISK reward</li>
            </ul>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2>Contract details</h2>
            <p class="muted">Resolved systems and contract values.</p>
          </div>
          <div class="content">
            <div><strong>#<?= (int)($contractRow['contract_id'] ?? 0) ?></strong></div>
            <div class="muted"><?= htmlspecialchars((string)($contractRow['start_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string)($contractRow['end_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="contract-meta">
              <span class="pill"><?= htmlspecialchars((string)($contractRow['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pill subtle"><?= htmlspecialchars((string)($contractRow['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php
              $requestKey = trim((string)($requestRow['request_key'] ?? ''));
              $quoteReference = $requestKey !== '' ? ('Quote ' . $requestKey) : '';
              $contractTitle = trim((string)($contractRow['title'] ?? ''));
            ?>
            <?php if ($quoteReference !== ''): ?>
              <div class="muted">Quote reference: <?= htmlspecialchars($quoteReference, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($contractTitle !== ''): ?>
              <div class="muted">Contract title: <?= htmlspecialchars($contractTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <ul class="list">
              <li><span class="badge">V</span> <?= htmlspecialchars($formatNumber((float)($contractRow['volume_m3'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> m3</li>
              <li><span class="badge">C</span> <?= htmlspecialchars($formatIsk((float)($contractRow['collateral_isk'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> ISK collateral</li>
              <li><span class="badge">R</span> <?= htmlspecialchars($formatIsk((float)($contractRow['reward_isk'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?> ISK reward</li>
            </ul>
            <div class="muted">
              Resolved pickup system: <?= htmlspecialchars($comparison['context']['start_system_name'] !== '' ? $comparison['context']['start_system_name'] : ('System #' . (int)$comparison['context']['start_system_id']), ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($comparison['context']['start_override'])): ?>
                <span class="pill subtle">override</span>
              <?php endif; ?>
            </div>
            <div class="muted">
              Resolved delivery system: <?= htmlspecialchars($comparison['context']['end_system_name'] !== '' ? $comparison['context']['end_system_name'] : ('System #' . (int)$comparison['context']['end_system_id']), ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($comparison['context']['end_override'])): ?>
                <span class="pill subtle">override</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php elseif ($selectedRequestId || $selectedContractId): ?>
    <div class="alert alert-warning">
      <strong>Unable to compare.</strong> Make sure both a request and contract are selected.
    </div>
  <?php endif; ?>
</section>
<?php
$body = ob_get_clean();
$scripts = '<script src="' . ($basePath ?: '') . '/assets/js/admin/contract-compare.js" defer></script>';
require __DIR__ . '/../../../src/Views/layout.php';
