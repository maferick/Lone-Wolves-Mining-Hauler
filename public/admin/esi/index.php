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
$courierStats = [
  'total' => 0,
  'matched' => 0,
  'mismatch' => 0,
  'unmatched' => 0,
];
$courierContracts = [];
$courierContractsAvailable = false;
$hasCourierContracts = false;
$courierListLimit = 200;
$manualLinkRequests = [];
$manualLinkRequestsAvailable = false;
$canManageRequests = Auth::can($authCtx, 'haul.request.manage');
$contractAttachEnabled = true;

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

if ($corpId > 0) {
  $hasCourierContracts = (bool)$db->fetchValue("SHOW TABLES LIKE 'esi_corp_contract'");
  $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
  $hasHaulRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

  if ($hasCourierContracts) {
    if ($hasHaulRequest) {
      $statsRow = $db->one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN r.request_id IS NOT NULL THEN 1 ELSE 0 END) AS matched,
                SUM(CASE WHEN r.status = 'contract_mismatch' THEN 1 ELSE 0 END) AS mismatch
           FROM esi_corp_contract c
           LEFT JOIN haul_request r
             ON r.corp_id = c.corp_id
            AND (r.esi_contract_id = c.contract_id OR r.contract_id = c.contract_id)
          WHERE c.corp_id = :cid
            AND c.type = 'courier'
            AND c.status NOT IN ('finished', 'deleted', 'failed')",
        ['cid' => $corpId]
      );
    } else {
      $statsRow = $db->one(
        "SELECT COUNT(*) AS total
           FROM esi_corp_contract c
          WHERE c.corp_id = :cid
            AND c.type = 'courier'
            AND c.status NOT IN ('finished', 'deleted', 'failed')",
        ['cid' => $corpId]
      );
    }

    if ($statsRow) {
      $courierStats['total'] = (int)($statsRow['total'] ?? 0);
      $courierStats['matched'] = (int)($statsRow['matched'] ?? 0);
      $courierStats['mismatch'] = (int)($statsRow['mismatch'] ?? 0);
      $courierStats['unmatched'] = max(0, $courierStats['total'] - $courierStats['matched']);
    }

    $hasContractView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_contract_display'");
    $contractTable = $hasContractView ? 'v_contract_display' : 'esi_corp_contract';
    $startNameSelect = $hasContractView ? 'c.start_name' : "CONCAT('loc:', c.start_location_id)";
    $endNameSelect = $hasContractView ? 'c.end_name' : "CONCAT('loc:', c.end_location_id)";

    $requestSelect = $hasHaulRequest
      ? "r.request_id, r.status AS request_status"
      : "NULL AS request_id, NULL AS request_status";
    $requestJoin = $hasHaulRequest
      ? "LEFT JOIN haul_request r
           ON r.corp_id = c.corp_id
          AND (r.esi_contract_id = c.contract_id OR r.contract_id = c.contract_id)"
      : '';

    $courierContracts = $db->select(
      "SELECT c.contract_id, c.status, c.title, c.volume_m3, c.reward_isk, c.collateral_isk,
              c.date_issued, c.date_expired,
              {$startNameSelect} AS start_name,
              {$endNameSelect} AS end_name,
              {$requestSelect}
         FROM {$contractTable} c
         {$requestJoin}
        WHERE c.corp_id = :cid
          AND c.type = 'courier'
          AND c.status NOT IN ('finished', 'deleted', 'failed')
        ORDER BY c.date_issued DESC, c.contract_id DESC
        LIMIT {$courierListLimit}",
      ['cid' => $corpId]
    );
    $courierContractsAvailable = !empty($courierContracts);
  }

  if ($hasHaulRequest) {
    $attachSettingRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.attach_enabled' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($attachSettingRow && !empty($attachSettingRow['setting_json'])) {
      $attachSetting = Db::jsonDecode((string)$attachSettingRow['setting_json'], []);
      if (is_array($attachSetting) && array_key_exists('enabled', $attachSetting)) {
        $contractAttachEnabled = (bool)$attachSetting['enabled'];
      }
    }

    $manualJoin = $hasHaulRequestView ? 'LEFT JOIN v_haul_request_display v ON v.request_id = r.request_id' : '';
    $manualFromSelect = $hasHaulRequestView ? 'v.from_name' : "CONCAT(r.from_location_type, ':', r.from_location_id)";
    $manualToSelect = $hasHaulRequestView ? 'v.to_name' : "CONCAT(r.to_location_type, ':', r.to_location_id)";

    $manualLinkRequests = $db->select(
      "SELECT r.request_id, r.quote_id, r.status, r.reward_isk, r.volume_m3,
              {$manualFromSelect} AS from_name,
              {$manualToSelect} AS to_name
         FROM haul_request r
         {$manualJoin}
        WHERE r.corp_id = :cid
          AND r.quote_id IS NOT NULL
          AND (r.contract_id IS NULL OR r.contract_id = 0)
          AND (r.esi_contract_id IS NULL OR r.esi_contract_id = 0)
          AND r.status NOT IN ('completed', 'cancelled', 'delivered', 'expired', 'rejected')
        ORDER BY r.created_at DESC
        LIMIT 200",
      ['cid' => $corpId]
    );
    $manualLinkRequestsAvailable = !empty($manualLinkRequests);
  }
}

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

    <div class="card" style="margin-top:20px;">
      <div class="card-header">
        <h3>Downloaded Courier Contracts</h3>
        <p class="muted">Overview of courier contracts pulled from ESI so you can spot anything that needs manual matching.</p>
      </div>
      <div class="content">
        <?php if (!$hasCourierContracts): ?>
          <div class="muted">No contract mirror table found yet. Pull contracts first to populate this list.</div>
        <?php elseif ($courierStats['total'] <= 0): ?>
          <div class="muted">No courier contracts downloaded yet.</div>
        <?php else: ?>
          <div class="row" style="gap:16px; margin-bottom:16px;">
            <div class="pill"><strong>Total:</strong> <?= number_format($courierStats['total']) ?></div>
            <div class="pill"><strong>Matched:</strong> <?= number_format($courierStats['matched']) ?></div>
            <div class="pill"><strong>Unmatched:</strong> <?= number_format($courierStats['unmatched']) ?></div>
            <div class="pill"><strong>Mismatched:</strong> <?= number_format($courierStats['mismatch']) ?></div>
          </div>

          <?php if (!$courierContractsAvailable): ?>
            <div class="muted">No courier contract rows available.</div>
          <?php else: ?>
            <div class="muted" style="margin-bottom:10px;">
              Showing the latest <?= (int)$courierListLimit ?> courier contracts.
            </div>
            <div
              class="js-esi-contract-link"
              data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
              data-attach-enabled="<?= $contractAttachEnabled ? '1' : '0' ?>"
              data-can-manage="<?= $canManageRequests ? '1' : '0' ?>"
            >
            <table class="table">
              <thead>
                <tr>
                  <th>Contract</th>
                  <th>Status</th>
                  <th>Route</th>
                  <th>Volume</th>
                  <th>Reward</th>
                  <th>Collateral</th>
                  <th>Issued</th>
                  <th>Match</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($courierContracts as $contract): ?>
                  <?php
                  $requestId = (int)($contract['request_id'] ?? 0);
                  $requestStatus = (string)($contract['request_status'] ?? '');
                  $matchLabel = 'Unmatched';
                  $matchBadge = 'badge';
                  if ($requestId > 0 && $requestStatus === 'contract_mismatch') {
                    $matchLabel = 'Mismatch (Request #' . $requestId . ')';
                    $matchBadge = 'badge';
                  } elseif ($requestId > 0) {
                    $matchLabel = 'Linked (Request #' . $requestId . ')';
                    $matchBadge = 'badge';
                  }
                  ?>
                  <tr>
                    <td>#<?= htmlspecialchars((string)$contract['contract_id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$contract['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <?= htmlspecialchars((string)($contract['start_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?>
                      →
                      <?= htmlspecialchars((string)($contract['end_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td><?= number_format((float)($contract['volume_m3'] ?? 0), 0) ?> m³</td>
                    <td><?= number_format((float)($contract['reward_isk'] ?? 0), 2) ?> ISK</td>
                    <td><?= number_format((float)($contract['collateral_isk'] ?? 0), 2) ?> ISK</td>
                    <td><?= htmlspecialchars((string)($contract['date_issued'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <?php if ($requestId > 0): ?>
                        <span class="<?= htmlspecialchars($matchBadge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($matchLabel, ENT_QUOTES, 'UTF-8') ?></span>
                      <?php elseif (!$canManageRequests): ?>
                        <span class="muted">No permission</span>
                      <?php elseif (!$contractAttachEnabled): ?>
                        <span class="muted">Attachment disabled</span>
                      <?php elseif (!$manualLinkRequestsAvailable): ?>
                        <span class="muted">No open requests</span>
                      <?php else: ?>
                        <div class="row" style="gap:8px; align-items:center; margin-bottom:4px;">
                          <select class="input js-contract-select" data-contract-id="<?= htmlspecialchars((string)$contract['contract_id'], ENT_QUOTES, 'UTF-8') ?>">
                            <option value="">Select request…</option>
                            <?php foreach ($manualLinkRequests as $req): ?>
                              <?php
                                $fromName = (string)($req['from_name'] ?? 'Unknown');
                                $toName = (string)($req['to_name'] ?? 'Unknown');
                                $requestLabel = '#' . (string)$req['request_id']
                                  . ' • '
                                  . $fromName
                                  . ' → '
                                  . $toName
                                  . ' • '
                                  . number_format((float)($req['volume_m3'] ?? 0), 0)
                                  . ' m³';
                              ?>
                              <option value="<?= htmlspecialchars((string)($req['quote_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($requestLabel, ENT_QUOTES, 'UTF-8') ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn ghost js-link-contract" type="button" data-contract-id="<?= htmlspecialchars((string)$contract['contract_id'], ENT_QUOTES, 'UTF-8') ?>">Link</button>
                        </div>
                        <div class="muted js-link-status" data-contract-id="<?= htmlspecialchars((string)$contract['contract_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php if ($courierContractsAvailable): ?>
  <script src="<?= ($basePath ?: '') ?>/assets/js/admin/esi.js" defer></script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
