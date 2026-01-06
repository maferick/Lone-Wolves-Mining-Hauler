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
$title = $appName . ' • Access Settings';

$accessDefaults = [
  'scope' => 'corp',
  'alliances' => [],
];
$accessConfig = $accessDefaults;

$accessRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.login' LIMIT 1",
  ['cid' => $corpId]
);
if ($accessRow && !empty($accessRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$accessRow['setting_json'], []);
  if (is_array($decoded)) {
    $accessConfig = array_merge($accessConfig, $decoded);
  }
}

$allowedScopes = ['corp', 'alliance', 'alliances', 'public'];
$scope = in_array($accessConfig['scope'], $allowedScopes, true) ? $accessConfig['scope'] : 'corp';

$selectedAlliances = [];
foreach (($accessConfig['alliances'] ?? []) as $row) {
  $id = (int)($row['id'] ?? 0);
  $name = trim((string)($row['name'] ?? ''));
  if ($id > 0 && $name !== '') {
    $selectedAlliances[$id] = ['id' => $id, 'name' => $name];
  }
}
$selectedAlliances = array_values($selectedAlliances);

$msg = null;
$msgTone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedScope = strtolower(trim((string)($_POST['scope'] ?? 'corp')));
  if (!in_array($postedScope, $allowedScopes, true)) {
    $postedScope = 'corp';
  }

  $alliancesJson = (string)($_POST['alliances'] ?? '');
  $alliances = [];
  if ($alliancesJson !== '') {
    $decoded = json_decode($alliancesJson, true);
    if (is_array($decoded)) {
      foreach ($decoded as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        if ($id > 0 && $name !== '') {
          $alliances[$id] = ['id' => $id, 'name' => $name];
        }
      }
    }
  }

  $payload = [
    'scope' => $postedScope,
    'alliances' => array_values($alliances),
  ];

  $db->tx(function(Db $db) use ($corpId, $authCtx, $payload, $accessConfig) {
    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
       VALUES (:cid, 'access.login', :json, :uid)
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by_user_id=VALUES(updated_by_user_id)",
      [
        'cid' => $corpId,
        'json' => Db::jsonEncode($payload),
        'uid' => $authCtx['user_id'],
      ]
    );

    $db->audit(
      $corpId,
      $authCtx['user_id'],
      $authCtx['character_id'],
      'access.login.update',
      'app_setting',
      'access.login',
      $accessConfig,
      $payload,
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null
    );
  });

  $scope = $postedScope;
  $selectedAlliances = array_values($alliances);
  $msg = 'Saved access rules.';
}

$corpName = (string)($config['corp']['name'] ?? 'Corporation');
$allianceName = (string)($config['corp']['alliance_name'] ?? '');

ob_start();
?>
<section
  class="card"
  data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
  data-initial-alliances="<?= htmlspecialchars(json_encode($selectedAlliances, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="card-header">
    <h2>Login Access</h2>
    <p class="muted">Control who can sign in and request hauling services.</p>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="stack" style="margin-top:12px;">
      <div>
        <div class="label">Current corp</div>
        <div class="muted"><?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?><?php if ($allianceName !== ''): ?> (Alliance: <?= htmlspecialchars($allianceName, ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?></div>
      </div>

      <form method="post" id="access-form">
        <div class="row" style="align-items:flex-end;">
          <label class="form-field" style="flex:1;">
            <span class="form-label">Access scope</span>
            <select class="input" name="scope" id="access-scope">
              <option value="corp" <?= $scope === 'corp' ? 'selected' : '' ?>>Corp only</option>
              <option value="alliance" <?= $scope === 'alliance' ? 'selected' : '' ?>>Corp + alliance</option>
              <option value="alliances" <?= $scope === 'alliances' ? 'selected' : '' ?>>Selected alliances</option>
              <option value="public" <?= $scope === 'public' ? 'selected' : '' ?>>Everyone (public)</option>
            </select>
          </label>
          <button class="btn" type="submit">Save</button>
        </div>

        <div id="alliance-picker" style="margin-top:16px;">
          <div class="label">Allowed alliances</div>
          <div class="muted">Search ESI alliances to add them to the allowlist.</div>
          <div class="row" style="margin-top:10px; align-items:center;">
            <input class="input" type="text" id="alliance-search" placeholder="Search alliance name" autocomplete="off" />
            <div class="muted" id="alliance-search-status"></div>
          </div>
          <div id="alliance-results" class="stack" style="margin-top:10px;"></div>
          <div style="margin-top:12px;">
            <div class="label">Selected alliances</div>
            <div id="alliance-selected" class="stack" style="margin-top:6px;"></div>
          </div>
        </div>

        <input type="hidden" name="alliances" id="alliances-json" value="<?= htmlspecialchars(json_encode($selectedAlliances, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" />
      </form>
    </div>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/access.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/admin_layout.php';
