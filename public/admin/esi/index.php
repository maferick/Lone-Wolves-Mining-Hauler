<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • ESI';
$returnPath = ($basePath ?: '') . '/admin/esi/';
$ssoUrl = ($basePath ?: '') . '/login/?mode=esi&start=1&return=' . urlencode($returnPath);

$msg = null;
$cronCharId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'pull' && !empty($_POST['character_id'])) {
    $charId = (int)$_POST['character_id'];
    try {
      $result = $db->tx(fn(Db $db) => $services['esi']->contracts()->pull($corpId, $charId));
      $reconcile = $services['esi']->contractReconcile()->reconcile($corpId);
      $msg = "Pulled contracts: " . (int)($result['upserted_contracts'] ?? 0)
        . " (items: " . (int)($result['upserted_items'] ?? 0) . "). "
        . "Reconciled: " . (int)($reconcile['updated'] ?? 0) . " updated.";
    } catch (Throwable $e) {
      $msg = "Pull failed: " . $e->getMessage();
    }
  }

  if ($action === 'set_cron' && !empty($_POST['character_id'])) {
    $charId = (int)$_POST['character_id'];
    $token = $db->one(
      "SELECT owner_name FROM sso_token WHERE corp_id = :cid AND owner_type = 'character' AND owner_id = :oid LIMIT 1",
      ['cid' => $corpId, 'oid' => $charId]
    );
    $charName = (string)($token['owner_name'] ?? '');
    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
       VALUES (:cid, 'esi.cron', JSON_OBJECT('character_id', :char_id, 'character_name', :char_name), :uid)
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by_user_id=VALUES(updated_by_user_id)",
      [
        'cid' => $corpId,
        'char_id' => $charId,
        'char_name' => $charName,
        'uid' => (int)$authCtx['user_id'],
      ]
    );
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'esi.cron.set', 'app_setting', 'esi.cron', null, [
      'character_id' => $charId,
      'character_name' => $charName,
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Cron character set to " . ($charName !== '' ? $charName : (string)$charId) . ".";
  }

  if ($action === 'clear_cron') {
    $db->execute(
      "DELETE FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
      ['cid' => $corpId]
    );
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'esi.cron.clear', 'app_setting', 'esi.cron', null, null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Cron character cleared.";
  }

  if ($action === 'delete_token' && !empty($_POST['character_id'])) {
    $charId = (int)$_POST['character_id'];
    $token = $db->one(
      "SELECT owner_name FROM sso_token WHERE corp_id = :cid AND owner_type = 'character' AND owner_id = :oid LIMIT 1",
      ['cid' => $corpId, 'oid' => $charId]
    );
    $charName = (string)($token['owner_name'] ?? '');
    $db->tx(function (Db $db) use ($corpId, $charId) {
      $db->execute(
        "DELETE FROM sso_token WHERE corp_id = :cid AND owner_type = 'character' AND owner_id = :oid LIMIT 1",
        ['cid' => $corpId, 'oid' => $charId]
      );
      $db->execute(
        "DELETE FROM app_setting
          WHERE corp_id = :cid
            AND setting_key = 'esi.cron'
            AND JSON_EXTRACT(setting_json, '$.character_id') = :oid
          LIMIT 1",
        ['cid' => $corpId, 'oid' => $charId]
      );
    });
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'esi.token.delete', 'sso_token', (string)$charId, null, [
      'character_id' => $charId,
      'character_name' => $charName,
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Removed token for " . ($charName !== '' ? $charName : (string)$charId) . ".";
  }
}

$cronSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
  ['cid' => $corpId]
);
$cronJson = $cronSetting ? Db::jsonDecode($cronSetting['setting_json'], []) : [];
$cronCharId = (int)($cronJson['character_id'] ?? 0);
$cronCharName = (string)($cronJson['character_name'] ?? '');

$tokens = $db->select(
  "SELECT owner_id, owner_name, expires_at, scopes, token_status, last_error
     FROM sso_token
    WHERE corp_id=:cid AND owner_type='character'
    ORDER BY updated_at DESC",
  ['cid'=>$corpId]
);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>ESI Tokens & Contract Sync</h2>
    <p class="muted">Tokens are refreshed automatically when near expiry. Pulls are cached via ETag.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="pill" style="margin-bottom:12px;">
      <strong>Cron character:</strong>
      <?= $cronCharId > 0
        ? htmlspecialchars(($cronCharName !== '' ? $cronCharName : (string)$cronCharId), ENT_QUOTES, 'UTF-8')
        : 'Not set' ?>
      <?php if ($cronCharId > 0): ?>
        <form method="post" style="display:inline; margin-left:8px;">
          <button class="btn ghost" name="action" value="clear_cron" type="submit">Clear</button>
        </form>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:12px;">
      <a class="sso-button" href="<?= htmlspecialchars($ssoUrl, ENT_QUOTES, 'UTF-8') ?>">
        <img src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-small.png" alt="Log in with EVE Online" />
      </a>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>Character</th>
          <th>Expires (UTC)</th>
          <th>Status</th>
          <th>Scopes</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tokens as $t): ?>
        <tr>
          <td><?= htmlspecialchars((string)($t['owner_name'] ?: $t['owner_id']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$t['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$t['token_status'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars((string)$t['scopes'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string)$t['scopes'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td>
            <form method="post">
              <input type="hidden" name="character_id" value="<?= (int)$t['owner_id'] ?>" />
              <button class="btn" name="action" value="pull" type="submit">Pull Contracts</button>
              <?php if ((int)$t['owner_id'] === $cronCharId): ?>
                <span class="badge">Cron</span>
              <?php else: ?>
                <button class="btn ghost" name="action" value="set_cron" type="submit">Use for Cron</button>
              <?php endif; ?>
              <button class="btn ghost" name="action" value="delete_token" type="submit" onclick="return confirm('Remove this ESI token?');">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
