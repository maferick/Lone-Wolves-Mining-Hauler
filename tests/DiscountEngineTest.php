<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Domain/Pricing/QuoteContext.php';
require_once __DIR__ . '/../src/Domain/Pricing/DiscountRule.php';
require_once __DIR__ . '/../src/Domain/Pricing/DiscountResult.php';
require_once __DIR__ . '/../src/Services/Pricing/DiscountEngine.php';

use App\Db\Db;
use App\Domain\Pricing\QuoteContext;
use App\Services\Pricing\DiscountEngine;

$assertEqual = static function ($expected, $actual, string $message): void {
  if ($expected !== $actual) {
    throw new RuntimeException($message . " Expected: " . var_export($expected, true) . " Actual: " . var_export($actual, true));
  }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
  CREATE TABLE pricing_discount_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    priority INTEGER NOT NULL DEFAULT 100,
    stackable INTEGER NOT NULL DEFAULT 0,
    starts_at TEXT NULL,
    ends_at TEXT NULL,
    customer_scope TEXT NOT NULL DEFAULT 'any',
    scope_id TEXT NULL,
    route_scope TEXT NOT NULL DEFAULT 'any',
    pickup_id INTEGER NULL,
    drop_id INTEGER NULL,
    min_volume_m3 REAL NULL,
    max_volume_m3 REAL NULL,
    min_reward_isk REAL NULL,
    max_reward_isk REAL NULL,
    min_contracts_in_window INTEGER NULL,
    window_hours INTEGER NULL,
    offpeak_start_hhmm TEXT NULL,
    offpeak_end_hhmm TEXT NULL,
    discount_type TEXT NOT NULL,
    discount_value REAL NOT NULL DEFAULT 0,
    max_discount_isk REAL NULL,
    min_final_price_isk REAL NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL
  );
  CREATE TABLE quote (
    quote_id INTEGER PRIMARY KEY AUTOINCREMENT,
    corp_id INTEGER NOT NULL,
    created_at TEXT NOT NULL
  );
");

$db = new Db($pdo);
$engine = new DiscountEngine($db);

$insertRule = static function (array $data) use ($db): void {
  $defaults = [
    'name' => 'Rule',
    'description' => null,
    'enabled' => 1,
    'priority' => 50,
    'stackable' => 0,
    'starts_at' => null,
    'ends_at' => null,
    'customer_scope' => 'any',
    'scope_id' => null,
    'route_scope' => 'any',
    'pickup_id' => null,
    'drop_id' => null,
    'min_volume_m3' => null,
    'max_volume_m3' => null,
    'min_reward_isk' => null,
    'max_reward_isk' => null,
    'min_contracts_in_window' => null,
    'window_hours' => null,
    'offpeak_start_hhmm' => null,
    'offpeak_end_hhmm' => null,
    'discount_type' => 'percent',
    'discount_value' => 0,
    'max_discount_isk' => null,
    'min_final_price_isk' => null,
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00',
  ];
  $payload = array_merge($defaults, $data);
  $db->insert('pricing_discount_rules', $payload);
};

$buildContext = static function (float $basePrice, bool $allowStacking = false, ?\DateTimeImmutable $now = null): QuoteContext {
  return new QuoteContext(
    1,
    10,
    20,
    [],
    30000142,
    30002187,
    10000002,
    10000043,
    500.0,
    $basePrice,
    900.0,
    $basePrice === 900.0,
    0.0,
    900.0,
    $allowStacking,
    $now ?? new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC'))
  );
};

// Percent discount with cap
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Cap Rule',
  'discount_type' => 'percent',
  'discount_value' => 10,
  'max_discount_isk' => 500,
]);
$result = $engine->evaluate($buildContext(10000.0), 10000.0);
$assertEqual(500.0, $result->totalDiscount, 'Percent discount cap failed.');
$assertEqual(9500.0, $result->finalPrice, 'Percent discount cap final price failed.');

// Flat discount respecting min_final_price_isk
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Min Floor',
  'discount_type' => 'flat_isk',
  'discount_value' => 2000,
  'min_final_price_isk' => 9000,
]);
$result = $engine->evaluate($buildContext(10000.0), 10000.0);
$assertEqual(1000.0, $result->totalDiscount, 'Min final price floor failed.');
$assertEqual(9000.0, $result->finalPrice, 'Min final price final failed.');

// Best discount tie-break by priority
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Priority 20',
  'priority' => 20,
  'discount_type' => 'percent',
  'discount_value' => 10,
]);
$insertRule([
  'name' => 'Priority 10',
  'priority' => 10,
  'discount_type' => 'flat_isk',
  'discount_value' => 1000,
]);
$result = $engine->evaluate($buildContext(10000.0), 10000.0);
$assertEqual('Priority 10', $result->appliedRules[0]['rule_name'] ?? '', 'Priority tie-break failed.');

// Off-peak window crossing midnight
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Offpeak',
  'discount_type' => 'percent',
  'discount_value' => 5,
  'offpeak_start_hhmm' => '22:00',
  'offpeak_end_hhmm' => '06:00',
]);
$result = $engine->evaluate($buildContext(10000.0, false, new \DateTimeImmutable('2024-01-01 23:00:00', new \DateTimeZone('UTC'))), 10000.0);
$assertEqual(true, $result->totalDiscount > 0, 'Off-peak window (late) failed.');
$result = $engine->evaluate($buildContext(10000.0, false, new \DateTimeImmutable('2024-01-02 05:00:00', new \DateTimeZone('UTC'))), 10000.0);
$assertEqual(true, $result->totalDiscount > 0, 'Off-peak window (early) failed.');

// Bundle discount eligibility
$db->execute("DELETE FROM pricing_discount_rules");
$db->execute("DELETE FROM quote");
$db->insert('quote', ['corp_id' => 1, 'created_at' => '2024-01-01 10:00:00']);
$db->insert('quote', ['corp_id' => 1, 'created_at' => '2024-01-01 11:00:00']);
$insertRule([
  'name' => 'Bundle',
  'discount_type' => 'flat_isk',
  'discount_value' => 500,
  'min_contracts_in_window' => 3,
  'window_hours' => 24,
]);
$result = $engine->evaluate($buildContext(10000.0, false, new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC'))), 10000.0);
$assertEqual(500.0, $result->totalDiscount, 'Bundle eligibility failed.');

// Stacking on/off behavior
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Stack Percent',
  'priority' => 10,
  'stackable' => 1,
  'discount_type' => 'percent',
  'discount_value' => 10,
]);
$insertRule([
  'name' => 'Stack Flat',
  'priority' => 20,
  'stackable' => 1,
  'discount_type' => 'flat_isk',
  'discount_value' => 500,
]);
$result = $engine->evaluate($buildContext(10000.0, false), 10000.0);
$assertEqual(1000.0, $result->totalDiscount, 'Non-stacking should pick best rule.');
$result = $engine->evaluate($buildContext(10000.0, true), 10000.0);
$assertEqual(1500.0, $result->totalDiscount, 'Stacking should apply cumulative discounts.');

// Global floor enforced
$db->execute("DELETE FROM pricing_discount_rules");
$insertRule([
  'name' => 'Floor',
  'discount_type' => 'flat_isk',
  'discount_value' => 200,
]);
$result = $engine->evaluate($buildContext(1000.0, false), 1000.0);
$assertEqual(100.0, $result->totalDiscount, 'Global floor enforcement failed.');
$assertEqual(900.0, $result->finalPrice, 'Global floor final price failed.');

echo "DiscountEngine tests passed.\n";
