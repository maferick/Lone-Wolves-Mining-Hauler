<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
  throw new RuntimeException('Unable to resolve repo root.');
}

$script = <<<'SCRIPT'
require 'src/Auth/Auth.php';
use App\Auth\Auth;
$ctx = ['is_entitled' => false];
Auth::requireEntitled($ctx);
echo "ALLOWED";
SCRIPT;

$command = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script);
$output = shell_exec($command);

if ($output === null) {
  throw new RuntimeException('Failed to execute entitlement gate subprocess.');
}
if (strpos($output, 'Forbidden') === false) {
  throw new RuntimeException('Expected Forbidden response for non-entitled user. Output: ' . $output);
}

echo "RequireEntitled gate test passed.\n";
