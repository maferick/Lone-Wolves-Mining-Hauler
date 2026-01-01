<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EveSsoLoginService.php
 *
 * Handles EVE SSO authorization flow:
 * - build authorize URL
 * - exchange code for tokens
 * - verify access token to get character identity + scopes
 *
 * Requires env:
 *  EVE_CLIENT_ID
 *  EVE_CLIENT_SECRET
 *  EVE_CALLBACK_URL   (e.g. http://killsineve.online/hauling/auth/callback)
 */
final class EveSsoLoginService
{
  public function __construct(private Db $db, private array $config) {}

  public function authorizeUrl(string $state, array $scopes): string
  {
    $clientId = getenv('EVE_CLIENT_ID') ?: '';
    if ($clientId === '') throw new \RuntimeException("Missing EVE_CLIENT_ID.");

    $callback = getenv('EVE_CALLBACK_URL') ?: '';
    if ($callback === '') throw new \RuntimeException("Missing EVE_CALLBACK_URL.");

    $scope = implode(' ', $scopes);
    $qs = http_build_query([
      'response_type' => 'code',
      'redirect_uri' => $callback,
      'client_id' => $clientId,
      'scope' => $scope,
      'state' => $state,
    ]);

    return 'https://login.eveonline.com/v2/oauth/authorize/?' . $qs;
  }

  public function exchangeCode(string $code): array
  {
    $clientId = getenv('EVE_CLIENT_ID') ?: '';
    $clientSecret = getenv('EVE_CLIENT_SECRET') ?: '';
    if ($clientId === '' || $clientSecret === '') {
      throw new \RuntimeException("Missing EVE_CLIENT_ID/EVE_CLIENT_SECRET.");
    }

    $tokenUrl = $this->config['esi']['endpoints']['sso_token'] ?? 'https://login.eveonline.com/v2/oauth/token';
    $callback = getenv('EVE_CALLBACK_URL') ?: '';
    if ($callback === '') throw new \RuntimeException("Missing EVE_CALLBACK_URL.");

    $payload = http_build_query([
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $callback,
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
      throw new \RuntimeException("SSO code exchange failed: " . ($err ?: "HTTP {$status}: {$raw}"));
    }

    $data = Db::jsonDecode($raw, []);
    if (empty($data['access_token']) || empty($data['refresh_token'])) {
      throw new \RuntimeException("SSO exchange response missing tokens.");
    }

    return $data;
  }

  public function verify(string $accessToken): array
  {
    $verifyUrl = $this->config['esi']['endpoints']['sso_verify'] ?? 'https://login.eveonline.com/oauth/verify';

    $respHeaders = [];
    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $accessToken,
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
      throw new \RuntimeException("SSO verify failed: " . ($err ?: "HTTP {$status}: {$raw}"));
    }

    $data = Db::jsonDecode($raw, []);
    return $data;
  }
}
