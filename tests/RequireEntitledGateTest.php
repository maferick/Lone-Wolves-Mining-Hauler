<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
  throw new RuntimeException('Unable to resolve repo root.');
}

$script = <<<'SCRIPT'
require 'src/Auth/Auth.php';
use App\Auth\Auth;
$ctx = ['is_entitled' => false, 'access_granted' => false];
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

$adminScript = <<<'SCRIPT'
require 'src/Auth/Auth.php';
use App\Auth\Auth;
$ctx = ['is_admin' => true, 'is_entitled' => false, 'access_granted' => false, 'user_id' => 1];
Auth::requireEntitled($ctx);
echo "ALLOWED";
SCRIPT;
$adminCommand = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($adminScript);
$adminOutput = shell_exec($adminCommand);
if ($adminOutput === null || strpos($adminOutput, 'ALLOWED') === false) {
  throw new RuntimeException('Expected admin to bypass entitlement gate. Output: ' . ($adminOutput ?? 'null'));
}

echo "RequireEntitled gate test passed.\n";
