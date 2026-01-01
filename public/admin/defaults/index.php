<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'corp.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) { http_response_code(400); echo "No corp context"; exit; }

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Defaults';

$msg = null;
$errors = [];

$defaults = [
  'pricing.defaults' => [
    'min_fee' => 1000000,
    'per_jump' => 1000000,
  ],
  'routing.defaults' => [
    'route_policy' => 'safest',
    'avoid_low' => true,
    'avoid_null' => true,
  ],
  'discord.templates' => [
    'request_post' => [
      'enabled' => true,
    ],
  ],
];

$settingRows = $db->select(
  "SELECT setting_key, setting_json FROM app_setting
    WHERE corp_id = :cid AND setting_key IN ('pricing.defaults','routing.defaults','discord.templates')",
  ['cid' => $corpId]
);

$settings = $defaults;
foreach ($settingRows as $row) {
  $key = (string)$row['setting_key'];
  if (!array_key_exists($key, $settings)) continue;
  if (empty($row['setting_json'])) continue;
  try {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
  } catch (Throwable $e) {
    $decoded = [];
  }
  if (is_array($decoded) && $decoded !== []) {
    $settings[$key] = array_replace_recursive($settings[$key], $decoded);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $minFeeRaw = trim((string)($_POST['pricing_min_fee'] ?? ''));
  $perJumpRaw = trim((string)($_POST['pricing_per_jump'] ?? ''));

  $minFee = (int)preg_replace('/[^0-9]/', '', $minFeeRaw);
  $perJump = (int)preg_replace('/[^0-9]/', '', $perJumpRaw);

  if ($minFee < 0) $errors[] = 'Minimum fee must be zero or higher.';
  if ($perJump < 0) $errors[] = 'Per jump fee must be zero or higher.';

  $routePolicy = (string)($_POST['routing_policy'] ?? 'safest');
  $allowedPolicies = ['safest' => 'Safest', 'shortest' => 'Shortest'];
  if (!array_key_exists($routePolicy, $allowedPolicies)) {
    $routePolicy = 'safest';
  }

  $avoidLow = isset($_POST['routing_avoid_low']);
  $avoidNull = isset($_POST['routing_avoid_null']);

  $requestPostEnabled = isset($_POST['discord_request_post_enabled']);

  if ($errors === []) {
    $updates = [
      'pricing.defaults' => [
        'min_fee' => $minFee,
        'per_jump' => $perJump,
      ],
      'routing.defaults' => [
        'route_policy' => $routePolicy,
        'avoid_low' => $avoidLow,
        'avoid_null' => $avoidNull,
      ],
      'discord.templates' => [
        'request_post' => [
          'enabled' => $requestPostEnabled,
        ],
      ],
    ];

    $db->tx(function(Db $db) use ($corpId, $updates, $authCtx) {
      foreach ($updates as $key => $payload) {
        $beforeRow = $db->one(
          "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = :key",
          ['cid' => $corpId, 'key' => $key]
        );
        $before = null;
        if ($beforeRow && !empty($beforeRow['setting_json'])) {
          $before = Db::jsonDecode((string)$beforeRow['setting_json'], null);
        }

        $db->execute(
          "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
           VALUES (:cid, :key, :json, :uid)
           ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_by_user_id = VALUES(updated_by_user_id)",
          [
            'cid' => $corpId,
            'key' => $key,
            'json' => Db::jsonEncode($payload),
            'uid' => $authCtx['user_id'],
          ]
        );

        $db->audit(
          $corpId,
          $authCtx['user_id'],
          $authCtx['character_id'],
          'setting.update',
          'app_setting',
          $key,
          $before,
          $payload,
          $_SERVER['REMOTE_ADDR'] ?? null,
          $_SERVER['HTTP_USER_AGENT'] ?? null
        );
      }
    });

    $settings = array_replace_recursive($settings, $updates);
    $msg = 'Saved.';
  }
}

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Defaults</h2>
    <p class="muted">Configure pricing, routing, and Discord templates for your corp.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?>
      <div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="pill warning">
        <?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3>Pricing Defaults</h3>
          <p class="muted">Baseline fees for quote calculations.</p>
        </div>
        <div class="content">
          <div class="row">
            <div>
              <div class="label">Minimum fee (ISK)</div>
              <input class="input" name="pricing_min_fee" value="<?= htmlspecialchars((string)($settings['pricing.defaults']['min_fee'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <div class="label">Per jump fee (ISK)</div>
              <input class="input" name="pricing_per_jump" value="<?= htmlspecialchars((string)($settings['pricing.defaults']['per_jump'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3>Routing Defaults</h3>
          <p class="muted">Route policy and hazard avoidance.</p>
        </div>
        <div class="content">
          <div class="row">
            <div>
              <div class="label">Route policy</div>
              <select class="input" name="routing_policy">
                <?php
                $selectedPolicy = (string)($settings['routing.defaults']['route_policy'] ?? 'safest');
                $policyOptions = ['safest' => 'Safest', 'shortest' => 'Shortest'];
                foreach ($policyOptions as $value => $label):
                ?>
                  <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedPolicy === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="label">Avoidance</div>
              <label class="checkbox">
                <input type="checkbox" name="routing_avoid_low" <?= !empty($settings['routing.defaults']['avoid_low']) ? 'checked' : '' ?> />
                Avoid low-sec
              </label>
              <label class="checkbox" style="margin-top:8px;">
                <input type="checkbox" name="routing_avoid_null" <?= !empty($settings['routing.defaults']['avoid_null']) ? 'checked' : '' ?> />
                Avoid null-sec
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3>Discord Templates</h3>
          <p class="muted">Enable or disable template blocks for automated posts.</p>
        </div>
        <div class="content">
          <label class="checkbox">
            <input type="checkbox" name="discord_request_post_enabled" <?= !empty($settings['discord.templates']['request_post']['enabled']) ? 'checked' : '' ?> />
            Enable request post template
          </label>
        </div>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Save</button>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
      </div>
    </form>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
