<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EsiClient.php
 *
 * ESI client with:
 * - ETag-aware caching via esi_cache
 * - Standardized request signature hashing
 * - Minimal, dependency-free curl transport
 *
 * Design notes:
 * - Cache stores JSON string in esi_cache.response_json (LONGTEXT)
 * - We pass corp_id to isolate cache per corp when needed (or NULL for shared cache)
 * - If ESI returns 304, we reuse cached JSON and extend TTL
 */
final class EsiClient
{
  public function __construct(
    private Db $db,
    private array $config
  ) {}

  public function get(string $path, ?array $query = null, ?int $corpId = null, ?int $ttlSeconds = null): array
  {
    return $this->request('GET', $path, $query, null, $corpId, $ttlSeconds);
  }

  public function post(string $path, ?array $query = null, ?array $body = null, ?int $corpId = null, ?int $ttlSeconds = null): array
  {
    return $this->request('POST', $path, $query, $body, $corpId, $ttlSeconds);
  }

  /**
   * Returns:
   *  [
   *    'ok' => bool,
   *    'status' => int,
   *    'headers' => array,
   *    'json' => mixed (decoded),
   *    'raw' => string (raw JSON),
   *    'from_cache' => bool
   *  ]
   */
  public function request(
    string $method,
    string $path,
    ?array $query,
    ?array $body,
    ?int $corpId,
    ?int $ttlSeconds
  ): array {
    $base = rtrim($this->config['esi']['endpoints']['esi_base'] ?? 'https://esi.evetech.net', '/');
    $url = $base . '/' . ltrim($path, '/');

    $ttl = $ttlSeconds ?? (int)($this->config['esi']['cache']['default_ttl_seconds'] ?? 300);
    $cacheEnabled = (bool)($this->config['esi']['cache']['enabled'] ?? true);

    $cacheKeyBin = Db::esiCacheKey($method, $url, $query, $body);
    $cached = ['hit' => false, 'json' => null, 'etag' => null];

    if ($cacheEnabled) {
      $cached = $this->db->esiCacheGet($corpId, $cacheKeyBin);
      if ($cached['hit'] && $cached['json'] !== null) {
        $expiresAt = $cached['expires_at'] ?? null;
        $statusCode = isset($cached['status_code']) ? (int)$cached['status_code'] : 0;
        if ($expiresAt && $statusCode > 0 && $statusCode < 400) {
          $expiresTs = strtotime((string)$expiresAt);
          if ($expiresTs !== false && $expiresTs > time()) {
            return [
              'ok' => true,
              'status' => $statusCode,
              'headers' => [],
              'json' => $this->safeDecode($cached['json']),
              'raw' => $cached['json'],
              'from_cache' => true,
            ];
          }
        }
        // Otherwise, revalidate with ETag when available.
      }
    }

    $qs = $query ? ('?' . http_build_query($query)) : '';
    $finalUrl = $url . $qs;

    $headersOut = [];
    $respHeaders = [];

    $ch = curl_init($finalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
      [$k, $v] = explode(':', $headerLine, 2);
      $respHeaders[strtolower(trim($k))] = trim($v);
      return $len;
    });

    $reqHeaders = [
      'Accept: application/json',
    ];

    if ($cacheEnabled && !empty($cached['etag'])) {
      $reqHeaders[] = 'If-None-Match: ' . $cached['etag'];
    }

    if ($body !== null) {
      $bodyJson = Db::jsonEncode($body);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
      $reqHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $raw = '';
    $errno = 0;
    $err = null;
    $status = 0;
    $attempt = 0;

    while (true) {
      $raw = (string)curl_exec($ch);
      $errno = curl_errno($ch);
      $err = $errno ? curl_error($ch) : null;
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

      if (!$errno && EsiRateLimiter::shouldRetry($status, $this->config) && $attempt < 1) {
        curl_close($ch);
        EsiRateLimiter::sleepForRetry($respHeaders, $this->config);
        $respHeaders = [];
        $ch = curl_init($finalUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
          $len = strlen($headerLine);
          $headerLine = trim($headerLine);
          if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
          [$k, $v] = explode(':', $headerLine, 2);
          $respHeaders[strtolower(trim($k))] = trim($v);
          return $len;
        });
        if ($body !== null) {
          $bodyJson = Db::jsonEncode($body);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        $attempt++;
        continue;
      }

      break;
    }

    curl_close($ch);

    if (!$errno) {
      EsiRateLimiter::sleepIfLowRemaining($respHeaders, $this->config);
    }

    // 304 means "use cached"
    if ($status === 304 && $cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
      $raw = $cached['json'];
      // Extend TTL on successful revalidation
      $this->db->esiCachePut(
        $corpId,
        $cacheKeyBin,
        $method,
        $url,
        $query,
        $body,
        200,
        $cached['etag'],
        $respHeaders['last-modified'] ?? null,
        $ttl,
        $raw,
        null
      );
      return [
        'ok' => true,
        'status' => 200,
        'headers' => $respHeaders,
        'json' => $this->safeDecode($raw),
        'raw' => $raw,
        'from_cache' => true,
      ];
    }

    // Network error
    if ($errno) {
      if ($cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
        // Fail-open with cached payload
        return [
          'ok' => true,
          'status' => 200,
          'headers' => $respHeaders,
          'json' => $this->safeDecode($cached['json']),
          'raw' => $cached['json'],
          'from_cache' => true,
          'warning' => 'ESI network error; served cached data: ' . $err,
        ];
      }

      return [
        'ok' => false,
        'status' => 0,
        'headers' => $respHeaders,
        'json' => null,
        'raw' => '',
        'from_cache' => false,
        'error' => $err,
      ];
    }

    // Persist cache (even errors, to avoid hammering)
    if ($cacheEnabled) {
      $etag = $respHeaders['etag'] ?? null;
      $lastMod = $respHeaders['last-modified'] ?? null;

      $this->db->esiCachePut(
        $corpId,
        $cacheKeyBin,
        $method,
        $url,
        $query,
        $body,
        $status,
        $etag,
        $lastMod,
        $ttl,
        $raw,
        ($status >= 400 ? ('HTTP ' . $status) : null)
      );
    }

    $decoded = $this->safeDecode($raw);

    return [
      'ok' => $status >= 200 && $status < 300,
      'status' => $status,
      'headers' => $respHeaders,
      'json' => $decoded,
      'raw' => $raw,
      'from_cache' => false,
    ];
  }

  private function safeDecode(string $raw)
  {
    try {
      return Db::jsonDecode($raw, null);
    } catch (\Throwable $e) {
      return null;
    }
  }
}
