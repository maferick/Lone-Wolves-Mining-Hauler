<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class AuthzService
{
  private Db $db;
  public const SNAPSHOT_DEFAULT_MAX_AGE_SECONDS = 21600;

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
    $status = (string)($user['status'] ?? 'active');
    if ($status !== 'active') {
      return false;
    }
    $accessConfig = $this->loadAccessConfig();
    if (!$this->isUserInScope($user, $accessConfig)) {
      return false;
    }
    $corpId = (int)($user['corp_id'] ?? 0);
    if ($corpId <= 0) {
      return false;
    }
    if ($this->userHasRole((int)($user['user_id'] ?? 0), 'admin')) {
      return true;
    }
    $discordUserId = $this->fetchDiscordUserId((int)($user['user_id'] ?? 0));
    $roleId = $this->getDiscordRoleId($corpId, 'hauling.member');
    $snapshot = $this->getLatestMemberSnapshot($corpId, $roleId);
    return $this->isDiscordEntitled($corpId, $discordUserId, $snapshot);
  }

  public function isEntitledUserId(int $userId): bool
  {
    $user = $this->db->one(
      "SELECT user_id, corp_id, status
         FROM app_user
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    if (!$user) {
      return false;
    }
    return $this->isEntitledByUserRow($user);
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
}
