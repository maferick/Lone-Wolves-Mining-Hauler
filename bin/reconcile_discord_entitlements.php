#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\AuthzService;

$log = static function (string $message): void {
  $timestamp = gmdate('c');
  fwrite(STDOUT, "[{$timestamp}] {$message}\n");
};

if ($db === null) {
  $log('Database unavailable; aborting entitlement reconciliation.');
  exit(1);
}

$authz = new AuthzService($db);
$accessConfig = $authz->loadAccessConfig();
$snapshotMaxAge = AuthzService::snapshotMaxAgeSeconds();

$corpRows = $db->select("SELECT DISTINCT corp_id FROM app_user");
if ($corpRows === []) {
  $log('No portal users found; nothing to reconcile.');
  exit(0);
}

foreach ($corpRows as $corpRow) {
  $corpId = (int)($corpRow['corp_id'] ?? 0);
  if ($corpId <= 0) {
    continue;
  }

  $roleId = $authz->getDiscordRoleId($corpId, 'hauling.member');
  $snapshot = $authz->getLatestMemberSnapshot($corpId, $roleId);
  $snapshotAt = $snapshot['scanned_at'] ?? null;
  $snapshotFresh = AuthzService::isSnapshotFresh($snapshotAt, $snapshotMaxAge);
  $membersById = $snapshotFresh ? $authz->buildDiscordMemberLookup($snapshot) : [];

  $users = $db->select(
    "SELECT u.user_id, u.corp_id, u.status, u.session_revoked_at, l.discord_user_id
       FROM app_user u
       LEFT JOIN discord_user_link l ON l.user_id = u.user_id
      WHERE u.corp_id = :cid
      ORDER BY u.user_id ASC",
    ['cid' => $corpId]
  );

  if ($users === []) {
    continue;
  }

  $log("Reconciling entitlements for corp {$corpId} (snapshot fresh: " . ($snapshotFresh ? 'yes' : 'no') . ').');

  foreach ($users as $user) {
    $userId = (int)($user['user_id'] ?? 0);
    if ($userId <= 0) {
      continue;
    }
    $discordUserId = trim((string)($user['discord_user_id'] ?? ''));
    $inScope = $authz->isUserInScope($user, $accessConfig);
    $discordEntitled = $snapshotFresh && $discordUserId !== '' && isset($membersById[$discordUserId]);
    $entitled = $inScope && $discordEntitled;
    $desiredStatus = $entitled ? 'active' : 'suspended';
    $previousStatus = (string)($user['status'] ?? 'active');
    $statusChanged = $previousStatus !== $desiredStatus;
    $sessionRevokedAt = (string)($user['session_revoked_at'] ?? '');
    $shouldRevokeSession = !$entitled && $sessionRevokedAt === '';

    $rolesRemoved = 0;
    if (!$entitled) {
      $rolesRemoved = $db->execute(
        "DELETE FROM user_role WHERE user_id = :uid",
        ['uid' => $userId]
      );
    }

    $sessionRevokedUpdate = null;
    if ($shouldRevokeSession) {
      $sessionRevokedUpdate = gmdate('Y-m-d H:i:s');
    }

    if ($statusChanged || $rolesRemoved > 0 || $sessionRevokedUpdate !== null) {
      $updateFields = [];
      $params = ['uid' => $userId];
      if ($statusChanged) {
        $updateFields[] = 'status = :status';
        $params['status'] = $desiredStatus;
      }
      if ($sessionRevokedUpdate !== null) {
        $updateFields[] = 'session_revoked_at = :revoked_at';
        $params['revoked_at'] = $sessionRevokedUpdate;
      }
      if ($updateFields !== []) {
        $db->execute(
          "UPDATE app_user SET " . implode(', ', $updateFields) . " WHERE user_id = :uid",
          $params
        );
      }

      $db->audit(
        $corpId,
        null,
        null,
        'entitlement.reconcile',
        'app_user',
        (string)$userId,
        [
          'status' => $previousStatus,
          'session_revoked_at' => $sessionRevokedAt,
        ],
        [
          'status' => $statusChanged ? $desiredStatus : $previousStatus,
          'session_revoked_at' => $sessionRevokedUpdate ?? $sessionRevokedAt,
          'in_scope' => $inScope,
          'discord_entitled' => $discordEntitled,
          'roles_removed' => $rolesRemoved,
        ],
        null,
        null
      );
    }
  }
}

$log('Entitlement reconciliation complete.');
