<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/SsoService.php
 *
 * Token refresh against EVE SSO. Designed to work with sso_token table.
 * - Stores updated access/refresh tokens and expiry
 * - Does NOT assume a specific auth grant beyond refresh_token
 *
 * NOTE: SSO endpoints can evolve. Keep them configurable in .env:
 * - SSO_TOKEN_URL
 * - SSO_VERIFY_URL
 */
final class SsoService
{
  public function __construct(
    private Db $db,
    private array $config
  ) {}

  /**
   * Get the best token record for a given corp owner.
   * For corp contracts, owner_type=character + a character with director roles in corp.
   */
  public function getToken(string $ownerType, int $corpId, int $ownerId): ?array
  {
    return $this->db->one(
      "SELECT token_id, corp_id, owner_type, owner_id, owner_name, access_token, refresh_token, expires_at, scopes, token_status
         FROM sso_token
        WHERE corp_id = :corp_id AND owner_type = :owner_type AND owner_id = :owner_id
        LIMIT 1",
      [
        'corp_id' => $corpId,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
      ]
    );
  }

  /**
   * Ensure the access token is valid for at least $minValidSeconds.
   * Returns a token row with fresh access_token if needed.
   */
  public function ensureAccessToken(array $tokenRow, int $minValidSeconds = 60): array
  {
    $expiresAt = strtotime((string)$tokenRow['expires_at']);
    $now = time();

    if ($expiresAt === false || ($expiresAt - $now) <= $minValidSeconds) {
      return $this->refreshToken((int)$tokenRow['token_id']);
    }

    return $tokenRow;
  }

  /**
   * Refresh access token using refresh_token grant.
   * Requires:
   * - Basic auth header with EVE client_id:client_secret
   * - token endpoint
   *
   * Put these in .env:
   *   EVE_CLIENT_ID
   *   EVE_CLIENT_SECRET
   */
  public function refreshToken(int $tokenId): array
  {
    $token = $this->db->one(
      "SELECT token_id, corp_id, owner_type, owner_id, owner_name, refresh_token
         FROM sso_token WHERE token_id = :id LIMIT 1",
      ['id' => $tokenId]
    );

    if (!$token) {
      throw new \RuntimeException("SSO token_id not found: {$tokenId}");
    }

    $clientId = getenv('EVE_CLIENT_ID') ?: '';
    $clientSecret = getenv('EVE_CLIENT_SECRET') ?: '';
    if ($clientId === '' || $clientSecret === '') {
      throw new \RuntimeException("Missing EVE_CLIENT_ID/EVE_CLIENT_SECRET in environment.");
    }

    $tokenUrl = $this->config['esi']['endpoints']['sso_token'] ?? 'https://login.eveonline.com/v2/oauth/token';

    $payload = http_build_query([
      'grant_type' => 'refresh_token',
      'refresh_token' => $token['refresh_token'],
    ]);

    $basic = base64_encode($clientId . ':' . $clientSecret);

    $respHeaders = [];
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Basic ' . $basic,
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
      [$k, $v] = explode(':', $headerLine, 2);
      $respHeaders[strtolower(trim($k))] = trim($v);
      return $len;
    });

    $raw = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $status < 200 || $status >= 300) {
      $this->db->execute(
        "UPDATE sso_token SET token_status='error', last_error=:err, updated_at=CURRENT_TIMESTAMP WHERE token_id=:id",
        ['err' => $err ?: ("HTTP {$status}: {$raw}"), 'id' => $tokenId]
      );
      throw new \RuntimeException("SSO refresh failed: " . ($err ?: "HTTP {$status}"));
    }

    $data = Db::jsonDecode($raw, []);
    $accessToken = (string)($data['access_token'] ?? '');
    $refreshToken = (string)($data['refresh_token'] ?? $token['refresh_token']);
    $expiresIn = (int)($data['expires_in'] ?? 0);
    $scope = (string)($data['scope'] ?? '');

    if ($accessToken === '' || $expiresIn <= 0) {
      throw new \RuntimeException("SSO refresh response missing access_token/expires_in.");
    }

    $expiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn);

    $this->db->execute(
      "UPDATE sso_token
          SET access_token=:at,
              refresh_token=:rt,
              expires_at=:exp,
              scopes=:scopes,
              token_status='ok',
              last_error=NULL,
              last_refreshed_at=UTC_TIMESTAMP()
        WHERE token_id=:id",
      [
        'at' => $accessToken,
        'rt' => $refreshToken,
        'exp' => $expiresAt,
        'scopes' => $scope,
        'id' => $tokenId,
      ]
    );

    // Return updated row
    $updated = $this->db->one(
      "SELECT token_id, corp_id, owner_type, owner_id, owner_name, access_token, refresh_token, expires_at, scopes, token_status
         FROM sso_token WHERE token_id = :id LIMIT 1",
      ['id' => $tokenId]
    );

    if (!$updated) {
      throw new \RuntimeException("SSO token row vanished after refresh.");
    }
    return $updated;
  }
}
