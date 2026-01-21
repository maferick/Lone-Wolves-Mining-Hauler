<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class AuthzService
{
  private Db $db;
  public const SNAPSHOT_DEFAULT_MAX_AGE_SECONDS = 21600;
  private ?array $breakglassUserIds = null;
  private ?array $breakglassEmails = null;
  private ?array $rightsSourceByCorp = null;

  public function __construct(Db $db)
  {
    $this->db = $db;
  }

  public static function snapshotMaxAgeSeconds(): int
  {
    $env = (int)($_ENV['DISCORD_SNAPSHOT_MAX_AGE_SECONDS'] ?? 0);
    $value = $env > 0 ? $env : self::SNAPSHOT_DEFAULT_MAX_AGE_SECONDS;
    return max(60, $value);
  }

  public static function isSnapshotFresh(?string $scannedAt, ?int $maxAgeSeconds = null): bool
  {
    if ($scannedAt === null || $scannedAt === '') {
      return false;
    }
    $timestamp = strtotime($scannedAt);
    if ($timestamp === false) {
      return false;
    }
    $age = time() - $timestamp;
    $maxAge = $maxAgeSeconds ?? self::snapshotMaxAgeSeconds();
    return $age <= $maxAge;
  }

  public static function classifyCompliance(bool $inScope, bool $entitled): array
  {
    if ($inScope && $entitled) {
      return ['key' => 'compliant', 'label' => 'Compliant', 'icon' => '✅', 'class' => 'pill pill-success'];
    }
    if ($inScope && !$entitled) {
      return ['key' => 'de-entitled', 'label' => 'De-entitled', 'icon' => '⚠️', 'class' => 'pill pill-warning'];
    }
    if (!$inScope && $entitled) {
      return ['key' => 'drift', 'label' => 'Drift', 'icon' => '🚨', 'class' => 'pill pill-danger'];
    }
    return ['key' => 'out-of-scope', 'label' => 'Out of scope', 'icon' => '⛔', 'class' => 'pill subtle'];
  }

  public static function classifyComplianceForAdmin(bool $inScope, bool $entitled, bool $isAdmin): array
  {
    if ($isAdmin && !($inScope && $entitled)) {
      return ['key' => 'admin-exempt', 'label' => 'Privileged exempt', 'icon' => '🛡️', 'class' => 'pill subtle'];
    }
    return self::classifyCompliance($inScope, $entitled);
  }

  public static function accessGranted(bool $inScope, bool $entitled, bool $isAdmin): bool
  {
    return $isAdmin || ($inScope && $entitled);
  }

  public function loadAccessConfig(): array
  {
    $config = [
      'scope' => 'corp',
      'alliances' => [],
      'corp_id' => null,
    ];
    $row = $this->db->one(
      "SELECT corp_id, setting_json
         FROM app_setting
        WHERE setting_key = 'access.login'
        ORDER BY updated_at DESC
        LIMIT 1"
    );
    if ($row && !empty($row['setting_json'])) {
      $decoded = Db::jsonDecode((string)$row['setting_json'], []);
      if (is_array($decoded)) {
        $config = array_merge($config, $decoded);
      }
    }
    if ($row && isset($row['corp_id'])) {
      $config['corp_id'] = (int)$row['corp_id'];
    }
    return $config;
  }

  public function isUserInScope(array $user, array $accessConfig): bool
  {
    if (array_key_exists('is_in_scope', $user)) {
      return (bool)$user['is_in_scope'];
    }
    $scope = in_array((string)($accessConfig['scope'] ?? 'corp'), ['corp', 'alliance', 'alliances', 'public'], true)
      ? (string)$accessConfig['scope']
      : 'corp';
    if ($scope === 'public') {
      return true;
    }
    $homeCorpId = (int)($accessConfig['corp_id'] ?? 0);
    $userCorpId = (int)($user['corp_id'] ?? 0);
    if ($homeCorpId === 0) {
      return $userCorpId > 0;
    }
    return $userCorpId === $homeCorpId;
  }

  public function getDiscordRoleId(int $corpId, string $permKey): string
  {
    $row = $this->db->one(
      "SELECT role_map_json
         FROM discord_config
        WHERE corp_id = :cid
        LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$row || empty($row['role_map_json'])) {
      return '';
    }
    $roleMap = Db::jsonDecode((string)$row['role_map_json'], []);
    if (!is_array($roleMap)) {
      return '';
    }
    return trim((string)($roleMap[$permKey] ?? ''));
  }

  public function getLatestMemberSnapshot(int $corpId, string $roleId): ?array
  {
    if ($roleId === '') {
      return null;
    }
    $row = $this->db->one(
      "SELECT member_json, scanned_at
         FROM discord_member_snapshot
        WHERE corp_id = :cid AND role_id = :role_id
        ORDER BY scanned_at DESC
        LIMIT 1",
      [
        'cid' => $corpId,
        'role_id' => $roleId,
      ]
    );
    if (!$row) {
      return null;
    }
    $members = Db::jsonDecode((string)($row['member_json'] ?? ''), []);
    if (!is_array($members)) {
      $members = [];
    }
    return [
      'members' => $members,
      'scanned_at' => (string)($row['scanned_at'] ?? ''),
    ];
  }

  public function buildDiscordMemberLookup(?array $snapshot): array
  {
    if (!$snapshot || !isset($snapshot['members']) || !is_array($snapshot['members'])) {
      return [];
    }
    $lookup = [];
    foreach ($snapshot['members'] as $member) {
      if (!is_array($member)) {
        continue;
      }
      $discordUserId = trim((string)($member['discord_user_id'] ?? ''));
      if ($discordUserId !== '') {
        $lookup[$discordUserId] = true;
      }
    }
    return $lookup;
  }

  public function fetchDiscordUserId(int $userId): string
  {
    $row = $this->db->one(
      "SELECT discord_user_id
         FROM discord_user_link
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    return $row ? trim((string)($row['discord_user_id'] ?? '')) : '';
  }

  public function isDiscordEntitled(int $corpId, string $discordUserId, ?array $snapshot): bool
  {
    if ($discordUserId === '') {
      return false;
    }
    $scannedAt = $snapshot['scanned_at'] ?? null;
    if (!self::isSnapshotFresh($scannedAt)) {
      return false;
    }
    $lookup = $this->buildDiscordMemberLookup($snapshot);
    return isset($lookup[$discordUserId]);
  }

  public function isEntitledByUserRow(array $user): bool
  {
    return $this->isEntitled($user);
  }

  public function isEntitled(array $user, ?array $accessConfig = null, array $permKeys = []): bool
  {
    $status = (string)($user['status'] ?? 'active');
    if ($status !== 'active') {
      return false;
    }
    $corpId = (int)($user['corp_id'] ?? 0);
    if ($corpId <= 0) {
      return false;
    }
    $rightsSource = $this->resolveRightsSource($corpId, 'hauling.member');
    if ($rightsSource === 'portal') {
      if ($permKeys === []) {
        $permKeys = $this->getUserPermKeys((int)($user['user_id'] ?? 0));
      }
      return in_array('hauling.member', $permKeys, true);
    }

    $discordUserId = $this->fetchDiscordUserId((int)($user['user_id'] ?? 0));
    $roleId = $this->getDiscordRoleId($corpId, 'hauling.member');
    $snapshot = $this->getLatestMemberSnapshot($corpId, $roleId);
    return $this->isDiscordEntitled($corpId, $discordUserId, $snapshot);
  }

  public function isEntitledUserId(int $userId): bool
  {
    $user = $this->db->one(
      "SELECT user_id, corp_id, status, is_in_scope
         FROM app_user
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    if (!$user) {
      return false;
    }
    return $this->isEntitled($user);
  }

  public function isAccessGranted(array $user, ?array $accessConfig = null): bool
  {
    $accessConfig = $accessConfig ?? $this->loadAccessConfig();
    if (!$this->isUserInScope($user, $accessConfig)) {
      return false;
    }
    return $this->isEntitled($user, $accessConfig);
  }

  public function isAccessGrantedUserId(int $userId): bool
  {
    $user = $this->db->one(
      "SELECT user_id, corp_id, status, is_in_scope
         FROM app_user
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    if (!$user) {
      return false;
    }
    return $this->isAccessGranted($user);
  }

  public function userIsAdmin(int $userId, ?array $userRow = null): bool
  {
    if ($userId <= 0) {
      return false;
    }

    $email = '';
    if (is_array($userRow) && isset($userRow['email'])) {
      $email = trim((string)$userRow['email']);
    }

    if ($this->isBreakglassAdmin($userId, $email)) {
      return true;
    }

    if ($email === '' && $this->getBreakglassEmails() !== []) {
      $email = $this->fetchUserEmail($userId);
      if ($this->isBreakglassAdmin($userId, $email)) {
        return true;
      }
    }

    return $this->userHasRole($userId, 'admin');
  }

  public function computeAccessState(array $user, array $permKeys = []): array
  {
    $accessConfig = $this->loadAccessConfig();
    $inScope = $this->isUserInScope($user, $accessConfig);
    $isEntitled = $this->isEntitled($user, $accessConfig, $permKeys);
    $userId = (int)($user['user_id'] ?? 0);
    $email = trim((string)($user['email'] ?? ''));
    $isAdmin = $this->isBreakglassAdmin($userId, $email) || $this->userHasRole($userId, 'admin');
    $accessGranted = self::accessGranted($inScope, $isEntitled, $isAdmin);

    return [
      'is_admin' => $isAdmin,
      'is_entitled' => $isEntitled,
      'is_in_scope' => $inScope,
      'access_granted' => $accessGranted,
      'compliance' => self::classifyComplianceForAdmin($inScope, $isEntitled, $isAdmin),
    ];
  }

  public function computeReconcileDecision(array $user, bool $isAdmin, bool $inScope, bool $entitled): array
  {
    $previousStatus = (string)($user['status'] ?? 'active');
    $sessionRevokedAt = (string)($user['session_revoked_at'] ?? '');
    $accessGranted = $inScope && $entitled;

    $desiredStatus = $previousStatus;
    if ($accessGranted) {
      $desiredStatus = 'active';
    } elseif (!$isAdmin) {
      $desiredStatus = 'suspended';
    } elseif ($previousStatus !== 'disabled') {
      $desiredStatus = 'active';
    }

    return [
      'desired_status' => $desiredStatus,
      'status_changed' => $desiredStatus !== $previousStatus,
      'should_revoke_session' => !$accessGranted && !$isAdmin && $sessionRevokedAt === '',
    ];
  }

  public function getAdminClassUserIds(): array
  {
    $roleRows = $this->db->select(
      "SELECT role_id, corp_id
         FROM role
        WHERE role_key IN ('admin', 'subadmin')"
    );
    $roleIds = array_map('intval', array_column($roleRows, 'role_id'));
    $roleIds = array_values(array_filter($roleIds, static fn(int $id): bool => $id > 0));

    $userIds = [];
    if ($roleIds !== []) {
      $placeholders = [];
      $params = [];
      foreach ($roleIds as $index => $roleId) {
        $key = 'role_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $roleId;
      }
      $rows = $this->db->select(
        "SELECT DISTINCT user_id
           FROM user_role
          WHERE role_id IN (" . implode(', ', $placeholders) . ")",
        $params
      );
      $userIds = array_map('intval', array_column($rows, 'user_id'));
      $userIds = array_values(array_filter($userIds, static fn(int $id): bool => $id > 0));
    }

    $breakglassIds = $this->getBreakglassUserIds();
    if ($breakglassIds !== []) {
      $userIds = array_merge($userIds, $breakglassIds);
    }

    $breakglassEmails = $this->getBreakglassEmails();
    if ($breakglassEmails !== []) {
      $placeholders = [];
      $params = [];
      foreach ($breakglassEmails as $index => $email) {
        $key = 'email_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $email;
      }
      $emailRows = $this->db->select(
        "SELECT user_id
           FROM app_user
          WHERE LOWER(email) IN (" . implode(', ', $placeholders) . ")",
        $params
      );
      $emailUserIds = array_map('intval', array_column($emailRows, 'user_id'));
      $userIds = array_merge($userIds, $emailUserIds);
    }

    $userIds = array_values(array_unique($userIds));
    sort($userIds);
    return $userIds;
  }

  public function userIsAdminClass(int $userId, ?array $userRow = null): bool
  {
    if ($this->userIsAdmin($userId, $userRow)) {
      return true;
    }
    return $this->userHasRole($userId, 'subadmin');
  }

  public function userIsSubadmin(int $userId): bool
  {
    return $this->userHasRole($userId, 'subadmin');
  }

  public function selfHealAdminAccess(string $source = 'cron'): int
  {
    $adminUserIds = $this->getAdminClassUserIds();
    if ($adminUserIds === []) {
      return 0;
    }

    $remediated = 0;
    foreach ($adminUserIds as $userId) {
      $userId = (int)$userId;
      if ($userId <= 0) {
        continue;
      }
      $user = $this->db->one(
        "SELECT user_id, corp_id, status, session_revoked_at, email
           FROM app_user
          WHERE user_id = :uid
          LIMIT 1",
        ['uid' => $userId]
      );
      if (!$user) {
        continue;
      }
      $status = (string)($user['status'] ?? '');
      if ($status !== 'suspended') {
        continue;
      }
      if (!$this->userIsAdminClass($userId, $user)) {
        continue;
      }

      $previousStatus = $status;
      $previousSessionRevokedAt = $user['session_revoked_at'] ?? null;
      $updated = $this->db->execute(
        "UPDATE app_user
            SET status = 'active',
                session_revoked_at = NULL
          WHERE user_id = :uid
            AND status = 'suspended'",
        ['uid' => $userId]
      );
      if ($updated <= 0) {
        continue;
      }

      $timestamp = gmdate('c');
      $this->db->audit(
        isset($user['corp_id']) ? (int)$user['corp_id'] : null,
        null,
        null,
        'entitlement.admin_selfheal',
        'app_user',
        (string)$userId,
        [
          'status' => $previousStatus,
          'session_revoked_at' => $previousSessionRevokedAt,
        ],
        [
          'status' => 'active',
          'session_revoked_at' => null,
          'user_id' => $userId,
          'previous_status' => $previousStatus,
          'previous_session_revoked_at' => $previousSessionRevokedAt,
          'timestamp' => $timestamp,
          'source' => $source,
        ],
        null,
        null
      );
      $remediated++;
    }

    return $remediated;
  }

  private function parseEnvList(string $value): array
  {
    $value = trim($value);
    if ($value === '') {
      return [];
    }
    $parts = preg_split('/[,\s]+/', $value) ?: [];
    $cleaned = [];
    foreach ($parts as $part) {
      $part = trim((string)$part);
      if ($part !== '') {
        $cleaned[] = $part;
      }
    }
    return array_values(array_unique($cleaned));
  }

  private function getBreakglassUserIds(): array
  {
    if ($this->breakglassUserIds !== null) {
      return $this->breakglassUserIds;
    }
    $raw = (string)($_ENV['ADMIN_BREAKGLASS_USER_IDS'] ?? '');
    $ids = array_map('intval', $this->parseEnvList($raw));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    $this->breakglassUserIds = $ids;
    return $this->breakglassUserIds;
  }

  private function getBreakglassEmails(): array
  {
    if ($this->breakglassEmails !== null) {
      return $this->breakglassEmails;
    }
    $raw = (string)($_ENV['ADMIN_BREAKGLASS_EMAILS'] ?? '');
    $emails = array_map(
      static fn(string $email): string => strtolower($email),
      $this->parseEnvList($raw)
    );
    $this->breakglassEmails = $emails;
    return $this->breakglassEmails;
  }

  private function isBreakglassAdmin(int $userId, string $email): bool
  {
    if ($userId > 0 && in_array($userId, $this->getBreakglassUserIds(), true)) {
      return true;
    }
    if ($email !== '' && in_array(strtolower($email), $this->getBreakglassEmails(), true)) {
      return true;
    }
    return false;
  }

  private function fetchUserEmail(int $userId): string
  {
    if ($userId <= 0) {
      return '';
    }
    $row = $this->db->one(
      "SELECT email
         FROM app_user
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    return $row ? trim((string)($row['email'] ?? '')) : '';
  }

  private function userHasRole(int $userId, string $roleKey): bool
  {
    if ($userId <= 0 || $roleKey === '') {
      return false;
    }
    $row = $this->db->one(
      "SELECT 1
         FROM user_role ur
         JOIN role r ON r.role_id = ur.role_id
        WHERE ur.user_id = :uid
          AND r.role_key = :role_key
        LIMIT 1",
      [
        'uid' => $userId,
        'role_key' => $roleKey,
      ]
    );
    return $row !== null;
  }

  private function getUserPermKeys(int $userId): array
  {
    if ($userId <= 0) {
      return [];
    }
    $rows = $this->db->select(
      "SELECT DISTINCT p.perm_key
         FROM user_role ur
         JOIN role_permission rp ON rp.role_id = ur.role_id AND rp.allow = 1
         JOIN permission p ON p.perm_id = rp.perm_id
        WHERE ur.user_id = :uid",
      ['uid' => $userId]
    );
    return array_values(array_map(static fn($row) => (string)($row['perm_key'] ?? ''), $rows));
  }

  private function resolveRightsSource(int $corpId, string $permKey): string
  {
    $configRow = $this->loadDiscordConfig($corpId);
    $legacy = (string)($configRow['rights_source'] ?? 'portal');
    $field = match ($permKey) {
      'hauling.member' => 'rights_source_member',
      'hauling.hauler' => 'rights_source_hauler',
      default => '',
    };
    $value = $field !== '' ? (string)($configRow[$field] ?? '') : '';
    $normalized = $value !== '' ? $value : $legacy;
    return in_array($normalized, ['portal', 'discord'], true) ? $normalized : 'portal';
  }

  private function loadDiscordConfig(int $corpId): array
  {
    if ($corpId <= 0) {
      return [];
    }
    if ($this->rightsSourceByCorp !== null && array_key_exists($corpId, $this->rightsSourceByCorp)) {
      return $this->rightsSourceByCorp[$corpId];
    }
    $row = $this->db->one(
      "SELECT rights_source, rights_source_member, rights_source_hauler
         FROM discord_config
        WHERE corp_id = :cid
        LIMIT 1",
      ['cid' => $corpId]
    );
    $this->rightsSourceByCorp ??= [];
    $this->rightsSourceByCorp[$corpId] = $row ?: [];
    return $this->rightsSourceByCorp[$corpId];
  }
}
