<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Unit verification for Verify Pricing Rules.
 *
 * DATA FLOW: This executable test isolates a small behavior and fails with a non-zero result when its contract is broken.
 */

// WHAT: checks PricingService::calculate(), the pure function holding every
//       documented pricing rule, with hand-worked expected amounts.
// WHY:  prices are what customers pay. Each rule, its rounding, and each
//       "cannot price this yet" case is pinned here so a later change to the
//       rules is always a deliberate, visible decision.
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Database\TransactionManager;
use App\Repositories\PricingRepository;
use App\Services\PricingService;

// calculate() never touches the database; the PDO only satisfies the constructor.
$unused = new PDO('sqlite::memory:');
$pricing = new PricingService(new PricingRepository($unused), new TransactionManager($unused));
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$products = [];
$product = static function (int $id, ?string $category, ?int $reference = null) use (&$products): void {
    $products[] = ['id' => $id, 'category_slug' => $category, 'pricing_reference_product_id' => $reference];
};
$lines = [];
$line = static function (int $productId, string $quantity, string $unitCost, string $type = 'ACTUAL') use (&$lines): void {
    $lines[] = ['product_id' => $productId, 'recipe_type' => $type, 'quantity_required' => $quantity, 'unit_cost' => $unitCost];
};

// Candle 1: 0.200 kg wax × 25.000 + 1 wick × 0.350 + 0.5 h labour × 4.000 = 7.350
$product(1, 'candles');
$line(1, '0.200', '25.000');
$line(1, '1.000', '0.350');
$line(1, '0.500', '4.000');
// Candle 2: 1 × 5.125 = 5.125
$product(2, 'candles');
$line(2, '1.000', '5.125');
// Accessory 3: purchase cost 3.000
$product(3, 'accessories');
$line(3, '1.000', '3.000');
// Set 4: 2 × candle 1, 1 × candle 2, 1 × accessory 3, plus its own gift box 1.500
$product(4, 'candle-sets');
$line(4, '1.000', '1.500');
// Bouquet 5: actual cost 10.000
$product(5, 'bouquets');
$line(5, '2.000', '5.000');
// Event favor 6: unit cost 2.000; event favor 7: unit cost 1.111 (rounding)
$product(6, 'event-favors');
$line(6, '1.000', '2.000');
$product(7, 'event-favors');
$line(7, '1.000', '1.111');
// Diffuser 8 linked to candle 1 (own cost 4.000); diffuser 9 priced from its candle-equivalent recipe
$product(8, 'home-diffusers', 1);
$line(8, '1.000', '4.000');
$product(9, 'home-diffusers');
$line(9, '1.000', '4.500');
$line(9, '1.000', '6.000', 'CANDLE_EQUIVALENT');
// Unpriceable cases
$product(10, 'candles');                       // no recipe
$product(11, 'candle-sets');                   // contains candle 10, which has no cost
$product(12, 'home-diffusers', 10);            // linked to the unpriced candle
$product(13, 'candles');                       // zero cost
$line(13, '1.000', '0.000');
$product(14, 'misc');                          // no documented rule
$product(15, 'candle-sets');                   // no components
// Rounding: three materials of 0.333 × 1.001 = 0.999999 → 1.000 (rounded once, on the sum;
// rounding each line first would give 3 × 0.333 = 0.999)
$product(16, 'candles');
for ($material = 0; $material < 3; $material++) {
    $line(16, '0.333', '1.001');
}

$components = [
    ['set_product_id' => 4, 'component_product_id' => 1, 'quantity' => 2],
    ['set_product_id' => 4, 'component_product_id' => 2, 'quantity' => 1],
    ['set_product_id' => 4, 'component_product_id' => 3, 'quantity' => 1],
    ['set_product_id' => 11, 'component_product_id' => 10, 'quantity' => 2],
];
$r = $pricing->calculate($products, $lines, $components);

// Candles: actual cost × 2.
$check($r[1]['actual_cost_millis'] === 7350 && $r[1]['selling_price_millis'] === 14700 && $r[1]['rule'] === 'CANDLE_COST_X2', 'Candle price is actual cost × 2.');
$check($r[2]['selling_price_millis'] === 10250, 'A second candle is priced independently.');
// Accessories: purchase cost, no markup.
$check($r[3]['selling_price_millis'] === 3000 && $r[3]['rule'] === 'ACCESSORY_PURCHASE_COST', 'Accessories are sold at purchase cost.');
// Sets: (1.500 box + 2 × 7.350 + 5.125) × 2 + 3.000 accessory = 45.650; cost 24.325.
$check($r[4]['selling_price_millis'] === 45650 && $r[4]['rule'] === 'SET_COMPONENTS', 'Set price marks up non-accessory costs only.');
$check($r[4]['actual_cost_millis'] === 24325, 'Set cost is the sum of component and own costs.');
// Bouquets: base price = cost × 2.
$check($r[5]['selling_price_millis'] === 20000 && $r[5]['rule'] === 'BOUQUET_BASE_COST_X2', 'Bouquet base price is cost × 2.');
// Event favors: catalogue shows the 10–19 per-favor price; batches follow the tiers.
$check($r[6]['selling_price_millis'] === 3500 && $r[6]['rule'] === 'EVENT_FAVOR_TIER', 'Event favors show the smallest-tier price per favor.');
$check(PricingService::eventFavorBatchMillis(2000, 10) === 35000, '10–19 favors: cost × quantity × 1.75.');
$check(PricingService::eventFavorBatchMillis(2000, 19) === 66500, '19 favors are still in the first tier.');
$check(PricingService::eventFavorBatchMillis(2000, 20) === 60000, '20–39 favors: × 1.5.');
$check(PricingService::eventFavorBatchMillis(2000, 40) === 104000, '40+ favors: × 1.3.');
$check($r[7]['selling_price_millis'] === 1944, 'Tier prices round half-up to the millime (1.111 × 1.75 = 1.944).');
$refused = false;
try {
    PricingService::eventFavorBatchMillis(2000, 9);
} catch (DomainException) {
    $refused = true;
}
$check($refused, 'Fewer than 10 favors cannot be priced.');
$check(array_column(PricingService::eventFavorTiers(2000), 'unit_price_millis') === [3500, 3000, 2600], 'Tier table lists 1.75, 1.5 and 1.3.');
// Diffusers: equivalent candle price, else candle-equivalent × 2; profit uses own cost.
$check($r[8]['selling_price_millis'] === 14700 && $r[8]['actual_cost_millis'] === 4000 && $r[8]['rule'] === 'DIFFUSER_REFERENCE_CANDLE', 'A linked diffuser shows the candle price but keeps its own cost.');
$check($r[9]['selling_price_millis'] === 12000 && $r[9]['actual_cost_millis'] === 4500 && $r[9]['rule'] === 'DIFFUSER_CANDLE_EQUIVALENT', 'An unlinked diffuser uses candle-equivalent cost × 2.');
// Missing inputs never invent a price.
foreach ([10, 11, 12, 13, 14, 15] as $id) {
    $check($r[$id]['selling_price_millis'] === null && $r[$id]['rule'] === null && is_string($r[$id]['issue']), "Product {$id} is reported as unpriced with a reason.");
}
$check(str_contains((string) $r[11]['issue'], '#10'), 'A set names the component that blocks its price.');
$check($r[13]['actual_cost_millis'] === 0, 'A zero cost is recorded but not turned into a free price.');
// Rounding happens once, on the recipe total.
$check($r[16]['actual_cost_millis'] === 1000 && $r[16]['selling_price_millis'] === 2000, 'Recipe costs round once on the total, like ROUND(SUM(...), 3).');

// RESULT STEP: Report success only after every assertion above has passed.
echo "Pricing rule checks passed.\n";
