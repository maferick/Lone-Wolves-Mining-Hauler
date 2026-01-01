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
$title = $appName . ' • Corp Settings';

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $corpName = trim((string)($_POST['corp_name'] ?? ''));
  $ticker = trim((string)($_POST['ticker'] ?? ''));
  $allianceId = trim((string)($_POST['alliance_id'] ?? ''));
  $allianceName = trim((string)($_POST['alliance_name'] ?? ''));

  $allianceIdVal = ($allianceId === '') ? null : (int)$allianceId;
  if ($corpName === '') $corpName = 'Corporation';

  $db->tx(function(Db $db) use ($corpId, $corpName, $ticker, $allianceIdVal, $allianceName, $authCtx) {
    $db->execute(
      "UPDATE corp
          SET corp_name=:cn, ticker=:t, alliance_id=:aid, alliance_name=:an, updated_at=CURRENT_TIMESTAMP
        WHERE corp_id=:cid",
      ['cn'=>$corpName,'t'=>$ticker ?: null,'aid'=>$allianceIdVal,'an'=>$allianceName ?: null,'cid'=>$corpId]
    );

    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'corp.update', 'corp', (string)$corpId, null, [
      'corp_name'=>$corpName,'ticker'=>$ticker,'alliance_id'=>$allianceIdVal,'alliance_name'=>$allianceName
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);

    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
       VALUES (:cid, 'corp.profile', JSON_OBJECT('corp_id', :cid, 'corp_name', :cn, 'ticker', :t, 'alliance_id', :aid, 'alliance_name', :an), :uid)
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by_user_id=VALUES(updated_by_user_id)",
      ['cid'=>$corpId,'cn'=>$corpName,'t'=>$ticker ?: null,'aid'=>$allianceIdVal,'an'=>$allianceName ?: null,'uid'=>$authCtx['user_id']]
    );
  });

  $msg = "Saved.";
}

$corp = $db->one("SELECT corp_id, corp_name, ticker, alliance_id, alliance_name FROM corp WHERE corp_id=:cid", ['cid'=>$corpId]);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Corporation Profile</h2>
    <p class="muted">These values drive branding, access control, and ESI context.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?>
      <div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="row">
        <div>
          <div class="label">Corp Name</div>
          <input class="input" name="corp_name" value="<?= htmlspecialchars((string)($corp['corp_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <div class="label">Ticker</div>
          <input class="input" name="ticker" value="<?= htmlspecialchars((string)($corp['ticker'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>

      <div class="row">
        <div>
          <div class="label">Alliance ID (optional)</div>
          <input class="input" name="alliance_id" value="<?= htmlspecialchars((string)($corp['alliance_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <div class="label">Alliance Name (optional)</div>
          <input class="input" name="alliance_name" value="<?= htmlspecialchars((string)($corp['alliance_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Save</button>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin">Back</a>
      </div>
    </form>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
