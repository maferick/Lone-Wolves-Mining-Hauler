<?php
declare(strict_types=1);

function api_send_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function api_read_json(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = [];
  if (trim($raw) !== '') {
    $data = json_decode($raw, true);
  }
  if (is_array($data)) {
    return $data;
  }
  if (!empty($_POST)) {
    return $_POST;
  }
  if (!empty($_GET)) {
    return $_GET;
  }
  return [];
}

function api_key_ok(): bool {
  $required = getenv('API_KEY') ?: '';
  if ($required === '') return true; // no auth configured

  $hdr = $_SERVER['HTTP_X_API_KEY'] ?? '';
  $q = $_GET['api_key'] ?? '';
  $provided = $hdr !== '' ? $hdr : $q;

  return hash_equals($required, (string)$provided);
}

function api_require_key(): void {
  if (!api_key_ok()) {
    api_send_json(['ok' => false, 'error' => 'unauthorized'], 401);
  }
}
