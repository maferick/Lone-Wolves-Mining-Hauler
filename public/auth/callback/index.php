<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

/**
 * Handles SSO callback:
 * - exchange code -> tokens
 * - verify access_token -> character identity + scopes
 * - fetch character + corp (+ alliance) via ESI public endpoints
 * - upsert corp/app_user/sso_token
 * - first user in system becomes admin; later users become requester by default
 */

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$mode = $_SESSION['sso_mode'] ?? 'member';
$returnTo = $_SESSION['sso_return_to'] ?? '';

if (($code === '' || $state === '') && !empty($_SERVER['HTTP_REFERER'])) {
  $ref = parse_url((string)$_SERVER['HTTP_REFERER']);
  $refHost = (string)($ref['host'] ?? '');
  $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
  $refPath = (string)($ref['path'] ?? '');

  if ($refHost !== '' && $reqHost !== '' && strcasecmp($refHost, $reqHost) === 0 && str_contains($refPath, '/auth/callback')) {
    $query = (string)($ref['query'] ?? '');
    if ($query !== '') {
      parse_str($query, $refQuery);
      $code = $code !== '' ? $code : (string)($refQuery['code'] ?? '');
      $state = $state !== '' ? $state : (string)($refQuery['state'] ?? '');
    }
  }
}

if ($db === null || !isset($services['sso_login'], $services['eve_public'])) {
  http_response_code(503);
  echo "SSO unavailable (database offline).";
  exit;
}

if ($code === '' || $state === '') {
  $errorDetail = (string)($_GET['error_description'] ?? $_GET['error'] ?? '');
  $base = rtrim((string)($config['app']['base_path'] ?? ''), '/');
  $loginUrl = ($base ?: '') . '/login/?start=1&mode=' . rawurlencode($mode);
  if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
    $loginUrl .= '&return=' . rawurlencode($returnTo);
  }

  http_response_code(400);
  if ($errorDetail !== '') {
    echo "SSO error: " . htmlspecialchars($errorDetail, ENT_QUOTES, 'UTF-8') . "<br>";
  }
  echo "Missing code/state. <a href=\"" . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . "\">Restart login</a>";
  exit;
}

if (($_SESSION['sso_state'] ?? '') !== $state) {
  http_response_code(400);
  echo "Invalid state";
  exit;
}

unset($_SESSION['sso_state']);
unset($_SESSION['sso_mode'], $_SESSION['sso_return_to']);

try {
  $tokens = $services['sso_login']->exchangeCode($code);
  $verify = $services['sso_login']->verify($tokens['access_token']);

  $characterId = (int)($verify['CharacterID'] ?? 0);
  $characterName = (string)($verify['CharacterName'] ?? 'Unknown');
  $scopes = (string)($verify['Scopes'] ?? ($tokens['scope'] ?? ''));

  if ($characterId <= 0) {
    throw new RuntimeException("SSO verify did not return CharacterID.");
  }

  // Pull public character to find corp_id
  $char = $services['eve_public']->character($characterId);
  $corpId = (int)($char['corporation_id'] ?? 0);
  if ($corpId <= 0) throw new RuntimeException("Character did not return corporation_id.");

  $corp = $services['eve_public']->corporation($corpId);
  $corpName = (string)($corp['name'] ?? ('Corp ' . $corpId));
  $allianceId = isset($corp['alliance_id']) ? (int)$corp['alliance_id'] : null;
  $allianceName = null;

  if ($allianceId) {
    try {
      $ally = $services['eve_public']->alliance($allianceId);
      $allianceName = (string)($ally['name'] ?? null);
    } catch (Throwable $e) {
      // alliance fetch optional
    }
  }

  $expiresIn = (int)($tokens['expires_in'] ?? 0);
  $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $expiresIn));
  $storeToken = $mode === 'esi';

  $isAdmin = false;
  $db->tx(function(Db $db) use (&$isAdmin, $corpId, $corpName, $allianceId, $allianceName, $characterId, $characterName, $tokens, $expiresAt, $scopes, $storeToken) {

    // Ensure corp row
    $db->execute(
      "INSERT INTO corp (corp_id, corp_name, alliance_id, alliance_name, is_active)
       VALUES (:cid, :cname, :aid, :aname, 1)
       ON DUPLICATE KEY UPDATE corp_name=VALUES(corp_name), alliance_id=VALUES(alliance_id), alliance_name=VALUES(alliance_name), is_active=1",
      [
        'cid' => $corpId,
        'cname' => $corpName,
        'aid' => $allianceId,
        'aname' => $allianceName,
      ]
    );

    // Create roles if missing (admin/subadmin/requester/hauler/dispatcher)
    $roleKeys = [
      ['admin', 'Admin', 'Full access to configuration and operations.', 1],
      ['subadmin', 'Sub Admin', 'Delegated admin: can manage ops/config with scoped permissions.', 1],
      ['dispatcher', 'Dispatcher', 'Can assign jobs and manage workflow.', 1],
      ['hauler', 'Hauler', 'Can accept/execute hauling assignments.', 1],
      ['requester', 'Requester', 'Can create and track hauling requests.', 1],
    ];
    foreach ($roleKeys as $rk) {
      $db->execute(
        "INSERT INTO role (corp_id, role_key, role_name, description, is_system)
         VALUES (:corp_id, :role_key, :role_name, :desc, :is_system)
         ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), description=VALUES(description)",
        [
          'corp_id' => $corpId,
          'role_key' => $rk[0],
          'role_name' => $rk[1],
          'desc' => $rk[2],
          'is_system' => $rk[3],
        ]
      );
    }

    // Ensure baseline permissions exist (idempotent)
    $permList = [
      ['haul.request.create','Create haul request','Create new haul requests and submit for posting.'],
      ['haul.request.read','View haul requests','View haul requests for the corporation.'],
      ['haul.request.manage','Manage haul requests','Edit/quote/cancel/post requests.'],
      ['haul.assign','Assign hauls','Assign requests to internal haulers.'],
      ['haul.execute','Execute hauls','Update status (pickup/in-transit/delivered).'],
      ['pricing.manage','Manage pricing','Create/update pricing rules and lanes.'],
      ['webhook.manage','Manage webhooks','Create/update Discord webhooks and templates.'],
      ['esi.manage','Manage ESI','Configure ESI tokens and scheduled pulls.'],
      ['user.manage','Manage users','Manage user access and role assignments.'],
      ['corp.manage','Manage corporation settings','Configure corp profile, defaults, and access rules.'],
    ];
    foreach ($permList as $p) {
      $db->execute(
        "INSERT INTO permission (perm_key, perm_name, description) VALUES (:k, :n, :d)
         ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), description=VALUES(description)",
        ['k' => $p[0], 'n' => $p[1], 'd' => $p[2]]
      );
    }

    // Determine if this is the first real SSO user EVER (platform bootstrap) OR first in this corp.
    // Ignore seeded/system placeholders that lack a real character_id.
    $totalUsers = (int)$db->scalar("SELECT COUNT(*) FROM app_user WHERE character_id IS NOT NULL AND character_id > 0");
    $corpUsers = (int)$db->scalar(
      "SELECT COUNT(*) FROM app_user WHERE corp_id = :cid AND character_id IS NOT NULL AND character_id > 0",
      ['cid' => $corpId]
    );

    // Upsert user
    $existing = $db->one("SELECT user_id FROM app_user WHERE corp_id=:cid AND character_id=:chid LIMIT 1", ['cid'=>$corpId,'chid'=>$characterId]);
    if ($existing) {
      $userId = (int)$existing['user_id'];
      $db->execute(
        "UPDATE app_user
            SET character_name=:cn, display_name=:dn, last_login_at=UTC_TIMESTAMP(), status='active'
          WHERE user_id=:uid",
        ['cn'=>$characterName,'dn'=>$characterName,'uid'=>$userId]
      );
    } else {
      $userId = (int)$db->insert(
        "INSERT INTO app_user (corp_id, character_id, character_name, display_name, status, last_login_at)
         VALUES (:cid, :chid, :cn, :dn, 'active', UTC_TIMESTAMP())",
        ['cid'=>$corpId,'chid'=>$characterId,'cn'=>$characterName,'dn'=>$characterName]
      );
    }

    if ($storeToken) {
      // Upsert SSO token (owner_type=character)
      $db->execute(
        "INSERT INTO sso_token (corp_id, owner_type, owner_id, owner_name, access_token, refresh_token, expires_at, scopes, token_status)
         VALUES (:cid, 'character', :oid, :oname, :at, :rt, :exp, :scopes, 'ok')
         ON DUPLICATE KEY UPDATE
           owner_name=VALUES(owner_name),
           access_token=VALUES(access_token),
           refresh_token=VALUES(refresh_token),
           expires_at=VALUES(expires_at),
           scopes=VALUES(scopes),
           token_status='ok',
           last_error=NULL,
           last_refreshed_at=UTC_TIMESTAMP()",
        [
          'cid'=>$corpId,
          'oid'=>$characterId,
          'oname'=>$characterName,
          'at'=>$tokens['access_token'],
          'rt'=>$tokens['refresh_token'],
          'exp'=>$expiresAt,
          'scopes'=>$scopes,
        ]
      );
    }

    // Assign roles:
    // - if first user ever, admin
    // - else if first user in this corp, admin (corp bootstrap)
    // - else requester by default
    $roleToAssign = ($totalUsers === 0 || $corpUsers === 0) ? 'admin' : 'requester';

    $roleId = (int)$db->scalar("SELECT role_id FROM role WHERE corp_id=:cid AND role_key=:rk LIMIT 1", ['cid'=>$corpId,'rk'=>$roleToAssign]);
    if ($roleId > 0) {
      $db->execute("INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:uid, :rid)", ['uid'=>$userId,'rid'=>$roleId]);
    }

    // Admin gets all perms; subadmin default set (ops + ESI + webhooks + pricing + users optional)
    // Keep idempotent.
    $adminRoleId = (int)$db->scalar("SELECT role_id FROM role WHERE corp_id=:cid AND role_key='admin' LIMIT 1", ['cid'=>$corpId]);
    if ($adminRoleId > 0) {
      $db->execute(
        "INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
         SELECT :rid, p.perm_id, 1 FROM permission p",
        ['rid'=>$adminRoleId]
      );
    }

    $subRoleId = (int)$db->scalar("SELECT role_id FROM role WHERE corp_id=:cid AND role_key='subadmin' LIMIT 1", ['cid'=>$corpId]);
    if ($subRoleId > 0) {
      $db->execute(
        "INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
         SELECT :rid, p.perm_id, 1 FROM permission p
          WHERE p.perm_key IN ('haul.request.read','haul.request.manage','haul.assign','haul.execute','pricing.manage','webhook.manage','esi.manage','user.manage','corp.manage')",
        ['rid'=>$subRoleId]
      );
    }

    // Store initial settings (idempotent)
    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json)
       VALUES (:cid, 'corp.profile', JSON_OBJECT('corp_id', :cid_profile, 'corp_name', :cname, 'alliance_id', :aid, 'alliance_name', :aname))
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json)",
      [
        'cid' => $corpId,
        'cid_profile' => $corpId,
        'cname' => $corpName,
        'aid' => $allianceId,
        'aname' => $allianceName,
      ]
    );

    $isAdmin = (bool)$db->scalar(
      "SELECT 1
         FROM user_role ur
         JOIN role r ON r.role_id = ur.role_id
        WHERE ur.user_id = :uid AND r.role_key = 'admin'
        LIMIT 1",
      ['uid' => $userId]
    );

    // Persist user_id to session (outside tx is fine, but safe here too)
    $_SESSION['user_id'] = $userId;
  });

  // Redirect to admin for admins; otherwise return to dashboard.
  $base = rtrim((string)($config['app']['base_path'] ?? ''), '/');
  if ($base !== '' && str_ends_with($base, '/auth/callback')) {
    $base = '';
  }
  $redirectPath = $isAdmin ? '/admin/' : '/';
  $returnTo = (string)$returnTo;
  if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
    header('Location: ' . $returnTo);
    exit;
  }
  header('Location: ' . ($base ?: '') . $redirectPath);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo "Auth failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  exit;
}
