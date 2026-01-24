<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Pricing Discounts';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && str_starts_with($path, $basePath)) {
  $path = substr($path, strlen($basePath));
}

$action = 'list';
$ruleId = (int)($_GET['id'] ?? 0);
if (preg_match('#^/admin/pricing/discounts/new/?$#', $path)) {
  $action = 'new';
} elseif (preg_match('#^/admin/pricing/discounts/([0-9]+)/edit/?$#', $path, $matches)) {
  $action = 'edit';
  $ruleId = (int)$matches[1];
} elseif (preg_match('#^/admin/pricing/discounts/([0-9]+)/toggle/?$#', $path, $matches)) {
  $action = 'toggle';
  $ruleId = (int)$matches[1];
} elseif (preg_match('#^/admin/pricing/discounts/([0-9]+)/delete/?$#', $path, $matches)) {
  $action = 'delete';
  $ruleId = (int)$matches[1];
} elseif (!empty($_GET['action'])) {
  $action = (string)$_GET['action'];
}

$message = null;
$messageTone = 'info';
$errors = [];

$defaultForm = [
  'name' => '',
  'description' => '',
  'enabled' => 1,
  'priority' => 50,
  'stackable' => 0,
  'starts_at' => '',
  'ends_at' => '',
  'customer_scope' => 'any',
  'scope_id' => '',
  'route_scope' => 'any',
  'pickup_id' => '',
  'drop_id' => '',
  'min_volume_m3' => '',
  'max_volume_m3' => '',
  'min_reward_isk' => '',
  'max_reward_isk' => '',
  'min_contracts_in_window' => '',
  'window_hours' => '',
  'offpeak_start_hhmm' => '',
  'offpeak_end_hhmm' => '',
  'discount_type' => 'percent',
  'discount_value' => '',
  'max_discount_isk' => '',
  'min_final_price_isk' => '',
];

$form = $defaultForm;
if ($ruleId > 0) {
  $row = $db->one("SELECT * FROM pricing_discount_rules WHERE id = :id LIMIT 1", ['id' => $ruleId]);
  if ($row) {
    $form = array_merge($form, [
      'name' => (string)$row['name'],
      'description' => (string)($row['description'] ?? ''),
      'enabled' => (int)$row['enabled'],
      'priority' => (int)$row['priority'],
      'stackable' => (int)$row['stackable'],
      'starts_at' => !empty($row['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$row['starts_at'])) : '',
      'ends_at' => !empty($row['ends_at']) ? date('Y-m-d\TH:i', strtotime((string)$row['ends_at'])) : '',
      'customer_scope' => (string)$row['customer_scope'],
      'scope_id' => (string)($row['scope_id'] ?? ''),
      'route_scope' => (string)$row['route_scope'],
      'pickup_id' => $row['pickup_id'] !== null ? (string)$row['pickup_id'] : '',
      'drop_id' => $row['drop_id'] !== null ? (string)$row['drop_id'] : '',
      'min_volume_m3' => $row['min_volume_m3'] !== null ? (string)$row['min_volume_m3'] : '',
      'max_volume_m3' => $row['max_volume_m3'] !== null ? (string)$row['max_volume_m3'] : '',
      'min_reward_isk' => $row['min_reward_isk'] !== null ? (string)$row['min_reward_isk'] : '',
      'max_reward_isk' => $row['max_reward_isk'] !== null ? (string)$row['max_reward_isk'] : '',
      'min_contracts_in_window' => $row['min_contracts_in_window'] !== null ? (string)$row['min_contracts_in_window'] : '',
      'window_hours' => $row['window_hours'] !== null ? (string)$row['window_hours'] : '',
      'offpeak_start_hhmm' => (string)($row['offpeak_start_hhmm'] ?? ''),
      'offpeak_end_hhmm' => (string)($row['offpeak_end_hhmm'] ?? ''),
      'discount_type' => (string)$row['discount_type'],
      'discount_value' => (string)$row['discount_value'],
      'max_discount_isk' => $row['max_discount_isk'] !== null ? (string)$row['max_discount_isk'] : '',
      'min_final_price_isk' => $row['min_final_price_isk'] !== null ? (string)$row['min_final_price_isk'] : '',
    ]);
  }
}

$parseDecimal = static function ($value): ?float {
  if ($value === null) {
    return null;
  }
  $trimmed = trim((string)$value);
  if ($trimmed === '') {
    return null;
  }
  if (!is_numeric($trimmed)) {
    return null;
  }
  return (float)$trimmed;
};

$parseInt = static function ($value): ?int {
  if ($value === null) {
    return null;
  }
  $trimmed = trim((string)$value);
  if ($trimmed === '') {
    return null;
  }
  if (!is_numeric($trimmed)) {
    return null;
  }
  return (int)$trimmed;
};

$validateTime = static function (?string $value): bool {
  if ($value === null || $value === '') {
    return true;
  }
  if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
    return false;
  }
  return true;
};

$normalizeDateTimeLocal = static function (?string $value) use (&$errors): ?string {
  if ($value === null || trim($value) === '') {
    return null;
  }
  $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new \DateTimeZone('UTC'));
  if ($dt === false) {
    $errors[] = 'Dates must use YYYY-MM-DD HH:MM.';
    return null;
  }
  return $dt->format('Y-m-d H:i:s');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = (string)($_POST['form_action'] ?? $_POST['action'] ?? '');
  if ($postAction === 'toggle' && $ruleId > 0) {
    $db->execute(
      "UPDATE pricing_discount_rules
          SET enabled = IF(enabled = 1, 0, 1)
        WHERE id = :id",
      ['id' => $ruleId]
    );
    header('Location: ' . ($basePath ?: '') . '/admin/pricing/discounts/');
    exit;
  }
  if ($postAction === 'delete' && $ruleId > 0) {
    $db->execute(
      "UPDATE pricing_discount_rules SET enabled = 0 WHERE id = :id",
      ['id' => $ruleId]
    );
    header('Location: ' . ($basePath ?: '') . '/admin/pricing/discounts/');
    exit;
  }

  $form = array_merge($form, [
    'name' => trim((string)($_POST['name'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'enabled' => !empty($_POST['enabled']) ? 1 : 0,
    'priority' => (int)($_POST['priority'] ?? 0),
    'stackable' => !empty($_POST['stackable']) ? 1 : 0,
    'starts_at' => (string)($_POST['starts_at'] ?? ''),
    'ends_at' => (string)($_POST['ends_at'] ?? ''),
    'customer_scope' => (string)($_POST['customer_scope'] ?? 'any'),
    'scope_id' => trim((string)($_POST['scope_id'] ?? '')),
    'route_scope' => (string)($_POST['route_scope'] ?? 'any'),
    'pickup_id' => trim((string)($_POST['pickup_id'] ?? '')),
    'drop_id' => trim((string)($_POST['drop_id'] ?? '')),
    'min_volume_m3' => trim((string)($_POST['min_volume_m3'] ?? '')),
    'max_volume_m3' => trim((string)($_POST['max_volume_m3'] ?? '')),
    'min_reward_isk' => trim((string)($_POST['min_reward_isk'] ?? '')),
    'max_reward_isk' => trim((string)($_POST['max_reward_isk'] ?? '')),
    'min_contracts_in_window' => trim((string)($_POST['min_contracts_in_window'] ?? '')),
    'window_hours' => trim((string)($_POST['window_hours'] ?? '')),
    'offpeak_start_hhmm' => trim((string)($_POST['offpeak_start_hhmm'] ?? '')),
    'offpeak_end_hhmm' => trim((string)($_POST['offpeak_end_hhmm'] ?? '')),
    'discount_type' => (string)($_POST['discount_type'] ?? 'percent'),
    'discount_value' => trim((string)($_POST['discount_value'] ?? '')),
    'max_discount_isk' => trim((string)($_POST['max_discount_isk'] ?? '')),
    'min_final_price_isk' => trim((string)($_POST['min_final_price_isk'] ?? '')),
  ]);

  if ($form['name'] === '') {
    $errors[] = 'Name is required.';
  }

  if ($form['customer_scope'] !== 'any' && $form['scope_id'] === '') {
    $errors[] = 'Scope ID is required for the selected customer scope.';
  }

  if ($form['route_scope'] === 'lane' && ($form['pickup_id'] === '' || $form['drop_id'] === '')) {
    $errors[] = 'Lane scope requires both pickup and drop IDs.';
  }
  if (in_array($form['route_scope'], ['pickup_region', 'pickup_system'], true) && $form['pickup_id'] === '') {
    $errors[] = 'Pickup scope requires a pickup ID.';
  }
  if (in_array($form['route_scope'], ['drop_region', 'drop_system'], true) && $form['drop_id'] === '') {
    $errors[] = 'Drop scope requires a drop ID.';
  }

  if (!$validateTime($form['offpeak_start_hhmm']) || !$validateTime($form['offpeak_end_hhmm'])) {
    $errors[] = 'Off-peak times must use HH:MM (24h) format.';
  }

  $startsAt = $normalizeDateTimeLocal($form['starts_at']);
  $endsAt = $normalizeDateTimeLocal($form['ends_at']);
  if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) < strtotime($startsAt)) {
    $errors[] = 'End date must be after start date.';
  }

  $minVolume = $parseDecimal($form['min_volume_m3']);
  $maxVolume = $parseDecimal($form['max_volume_m3']);
  if ($minVolume !== null && $maxVolume !== null && $minVolume > $maxVolume) {
    $errors[] = 'Minimum volume cannot exceed maximum volume.';
  }

  $minReward = $parseDecimal($form['min_reward_isk']);
  $maxReward = $parseDecimal($form['max_reward_isk']);
  if ($minReward !== null && $maxReward !== null && $minReward > $maxReward) {
    $errors[] = 'Minimum reward cannot exceed maximum reward.';
  }

  $minContracts = $parseInt($form['min_contracts_in_window']);
  $windowHours = $parseInt($form['window_hours']);
  if (($minContracts !== null && $windowHours === null) || ($minContracts === null && $windowHours !== null)) {
    $errors[] = 'Bundle discounts require both min contracts and window hours.';
  }

  $discountValue = $parseDecimal($form['discount_value']);
  if ($discountValue === null) {
    $errors[] = 'Discount value must be numeric.';
  }

  $maxDiscount = $parseDecimal($form['max_discount_isk']);
  $minFinal = $parseDecimal($form['min_final_price_isk']);

  if ($errors === []) {
    $payload = [
      'name' => $form['name'],
      'description' => $form['description'] !== '' ? $form['description'] : null,
      'enabled' => $form['enabled'],
      'priority' => $form['priority'],
      'stackable' => $form['stackable'],
      'starts_at' => $startsAt,
      'ends_at' => $endsAt,
      'customer_scope' => $form['customer_scope'],
      'scope_id' => $form['scope_id'] !== '' ? $form['scope_id'] : null,
      'route_scope' => $form['route_scope'],
      'pickup_id' => $form['pickup_id'] !== '' ? (int)$form['pickup_id'] : null,
      'drop_id' => $form['drop_id'] !== '' ? (int)$form['drop_id'] : null,
      'min_volume_m3' => $minVolume,
      'max_volume_m3' => $maxVolume,
      'min_reward_isk' => $minReward,
      'max_reward_isk' => $maxReward,
      'min_contracts_in_window' => $minContracts,
      'window_hours' => $windowHours,
      'offpeak_start_hhmm' => $form['offpeak_start_hhmm'] !== '' ? $form['offpeak_start_hhmm'] : null,
      'offpeak_end_hhmm' => $form['offpeak_end_hhmm'] !== '' ? $form['offpeak_end_hhmm'] : null,
      'discount_type' => $form['discount_type'],
      'discount_value' => $discountValue ?? 0.0,
      'max_discount_isk' => $maxDiscount,
      'min_final_price_isk' => $minFinal,
      'updated_at' => gmdate('Y-m-d H:i:s'),
    ];

    if ($action === 'edit' && $ruleId > 0) {
      $payload['id'] = $ruleId;
      $db->execute(
        "UPDATE pricing_discount_rules
            SET name = :name,
                description = :description,
                enabled = :enabled,
                priority = :priority,
                stackable = :stackable,
                starts_at = :starts_at,
                ends_at = :ends_at,
                customer_scope = :customer_scope,
                scope_id = :scope_id,
                route_scope = :route_scope,
                pickup_id = :pickup_id,
                drop_id = :drop_id,
                min_volume_m3 = :min_volume_m3,
                max_volume_m3 = :max_volume_m3,
                min_reward_isk = :min_reward_isk,
                max_reward_isk = :max_reward_isk,
                min_contracts_in_window = :min_contracts_in_window,
                window_hours = :window_hours,
                offpeak_start_hhmm = :offpeak_start_hhmm,
                offpeak_end_hhmm = :offpeak_end_hhmm,
                discount_type = :discount_type,
                discount_value = :discount_value,
                max_discount_isk = :max_discount_isk,
                min_final_price_isk = :min_final_price_isk,
                updated_at = :updated_at
          WHERE id = :id",
        $payload
      );
      $message = 'Discount rule updated.';
    } else {
      $payload['created_at'] = gmdate('Y-m-d H:i:s');
      $db->insert('pricing_discount_rules', $payload);
      $message = 'Discount rule created.';
    }
  }
}

$rules = $db->select(
  "SELECT *
     FROM pricing_discount_rules
    ORDER BY priority ASC, id ASC"
);

ob_start();
require __DIR__ . '/../../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Specials & Discounts</h2>
    <p class="muted">Create pricing overrides with deterministic evaluation and audit visibility.</p>
  </div>
  <div class="content">
    <?php if ($message): ?><div class="pill <?= $messageTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($errors): ?>
      <div class="pill pill-danger">
        <?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($action === 'new' || $action === 'edit'): ?>
      <form method="post" class="form-grid">
        <input type="hidden" name="form_action" value="save" />
        <label class="form-field">
          <span class="form-label">Name</span>
          <input class="input" name="name" value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>" required />
        </label>
        <label class="form-field">
          <span class="form-label">Description</span>
          <textarea class="input" name="description" rows="3"><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
        <div class="form-field">
          <label class="form-label">Enabled</label>
          <input type="checkbox" name="enabled" <?= $form['enabled'] ? 'checked' : '' ?> />
        </div>
        <div class="form-field">
          <label class="form-label">Stackable</label>
          <input type="checkbox" name="stackable" <?= $form['stackable'] ? 'checked' : '' ?> />
          <div class="muted">Only applies when discount stacking is enabled.</div>
        </div>
        <label class="form-field">
          <span class="form-label">Priority</span>
          <input class="input" name="priority" type="number" value="<?= htmlspecialchars((string)$form['priority'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Starts at (UTC)</span>
          <input class="input" type="datetime-local" name="starts_at" value="<?= htmlspecialchars($form['starts_at'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Ends at (UTC)</span>
          <input class="input" type="datetime-local" name="ends_at" value="<?= htmlspecialchars($form['ends_at'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Discount type</span>
          <select class="input" name="discount_type">
            <?php foreach (['percent' => 'Percent', 'flat_isk' => 'Flat ISK', 'waive_min_fee' => 'Waive Min Fee'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= $form['discount_type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">
          <span class="form-label">Discount value</span>
          <input class="input" name="discount_value" type="number" step="0.0001" value="<?= htmlspecialchars($form['discount_value'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Max discount (ISK)</span>
          <input class="input" name="max_discount_isk" type="number" step="0.01" value="<?= htmlspecialchars($form['max_discount_isk'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Minimum final price (ISK)</span>
          <input class="input" name="min_final_price_isk" type="number" step="0.01" value="<?= htmlspecialchars($form['min_final_price_isk'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <label class="form-field">
          <span class="form-label">Customer scope</span>
          <select class="input" name="customer_scope">
            <?php foreach (['any', 'character', 'corporation', 'alliance', 'acl_group'] as $scope): ?>
              <option value="<?= $scope ?>" <?= $form['customer_scope'] === $scope ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $scope)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">
          <span class="form-label">Scope ID</span>
          <input class="input" name="scope_id" value="<?= htmlspecialchars($form['scope_id'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <label class="form-field">
          <span class="form-label">Route scope</span>
          <select class="input" name="route_scope">
            <?php foreach (['any', 'lane', 'pickup_region', 'drop_region', 'pickup_system', 'drop_system'] as $scope): ?>
              <option value="<?= $scope ?>" <?= $form['route_scope'] === $scope ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $scope)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">
          <span class="form-label">Pickup ID</span>
          <input class="input" name="pickup_id" type="number" value="<?= htmlspecialchars($form['pickup_id'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Drop ID</span>
          <input class="input" name="drop_id" type="number" value="<?= htmlspecialchars($form['drop_id'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <label class="form-field">
          <span class="form-label">Min volume (m³)</span>
          <input class="input" name="min_volume_m3" type="number" step="0.01" value="<?= htmlspecialchars($form['min_volume_m3'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Max volume (m³)</span>
          <input class="input" name="max_volume_m3" type="number" step="0.01" value="<?= htmlspecialchars($form['max_volume_m3'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Min reward/base price (ISK)</span>
          <input class="input" name="min_reward_isk" type="number" step="0.01" value="<?= htmlspecialchars($form['min_reward_isk'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Max reward/base price (ISK)</span>
          <input class="input" name="max_reward_isk" type="number" step="0.01" value="<?= htmlspecialchars($form['max_reward_isk'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <label class="form-field">
          <span class="form-label">Bundle: min contracts</span>
          <input class="input" name="min_contracts_in_window" type="number" value="<?= htmlspecialchars($form['min_contracts_in_window'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Bundle window (hours)</span>
          <input class="input" name="window_hours" type="number" value="<?= htmlspecialchars($form['window_hours'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <label class="form-field">
          <span class="form-label">Off-peak start (HH:MM)</span>
          <input class="input" name="offpeak_start_hhmm" value="<?= htmlspecialchars($form['offpeak_start_hhmm'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>
        <label class="form-field">
          <span class="form-label">Off-peak end (HH:MM)</span>
          <input class="input" name="offpeak_end_hhmm" value="<?= htmlspecialchars($form['offpeak_end_hhmm'], ENT_QUOTES, 'UTF-8') ?>" />
        </label>

        <div class="row" style="margin-top:12px;">
          <button class="btn" type="submit"><?= $action === 'edit' ? 'Update' : 'Create' ?></button>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/pricing/discounts/">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <div class="row" style="margin-bottom:16px;">
        <a class="btn" href="<?= ($basePath ?: '') ?>/admin/pricing/discounts/new">New discount</a>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/pricing/">Back to pricing</a>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Enabled</th>
            <th>Type</th>
            <th>Value</th>
            <th>Scope</th>
            <th>Route</th>
            <th>Window</th>
            <th>Priority</th>
            <th>Stackable</th>
            <th>Active dates</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rules): ?>
            <tr><td colspan="11" class="muted">No discount rules configured yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($rules as $rule): ?>
            <?php
              $enabled = (int)$rule['enabled'] === 1;
              $type = (string)$rule['discount_type'];
              $value = $type === 'percent'
                ? number_format((float)$rule['discount_value'], 2) . '%'
                : number_format((float)$rule['discount_value'], 2) . ' ISK';
              $scope = (string)$rule['customer_scope'];
              if (!empty($rule['scope_id'])) {
                $scope .= ' #' . (string)$rule['scope_id'];
              }
              $route = (string)$rule['route_scope'];
              if (!empty($rule['pickup_id']) || !empty($rule['drop_id'])) {
                $route .= sprintf(' (%s → %s)', $rule['pickup_id'] ?? '-', $rule['drop_id'] ?? '-');
              }
              $window = '';
              if (!empty($rule['min_contracts_in_window']) && !empty($rule['window_hours'])) {
                $window = $rule['min_contracts_in_window'] . ' / ' . $rule['window_hours'] . 'h';
              } elseif (!empty($rule['offpeak_start_hhmm']) && !empty($rule['offpeak_end_hhmm'])) {
                $window = $rule['offpeak_start_hhmm'] . '–' . $rule['offpeak_end_hhmm'];
              }
              $dates = trim(((string)($rule['starts_at'] ?? '')) . ' → ' . ((string)($rule['ends_at'] ?? '')));
            ?>
            <tr>
              <td><?= htmlspecialchars((string)$rule['name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $enabled ? 'Yes' : 'No' ?></td>
              <td><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($window, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= (int)$rule['priority'] ?></td>
              <td><?= (int)$rule['stackable'] === 1 ? 'Yes' : 'No' ?></td>
              <td><?= htmlspecialchars($dates, ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="actions" style="display:flex; gap:6px;">
                  <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/pricing/discounts/<?= (int)$rule['id'] ?>/edit">Edit</a>
                  <form method="post" action="<?= ($basePath ?: '') ?>/admin/pricing/discounts/<?= (int)$rule['id'] ?>/toggle" onsubmit="return confirm('Toggle this rule?');">
                    <input type="hidden" name="form_action" value="toggle" />
                    <button class="btn ghost" type="submit"><?= $enabled ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="post" action="<?= ($basePath ?: '') ?>/admin/pricing/discounts/<?= (int)$rule['id'] ?>/delete" onsubmit="return confirm('Disable this discount rule?');">
                    <input type="hidden" name="form_action" value="delete" />
                    <button class="btn danger" type="submit">Disable</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<?php
$body = ob_get_clean();
require __DIR__ . '/../../../../src/Views/layout.php';
?>
