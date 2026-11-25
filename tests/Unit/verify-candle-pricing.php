<?php

declare(strict_types=1);

// Checks the automatic candle price arithmetic against the owner's worked example (no database).

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Services\CandlePricing;

$check = TestCheck::printing();

// Standard recipe for 75 g (mg): soy 30 000, beeswax 26 250, stearic acid 11 250, fragrance 7 500.
// Costs in nano-millimes per mg: soy 0.040 TND/g, beeswax 0.050, stearic 0.022, fragrance 0.250 per ml.
$recipe = [
    ['grams_milli' => 30000, 'cost_nano_per_milligram' => 40000, 'fragrance' => false],
    ['grams_milli' => 26250, 'cost_nano_per_milligram' => 50000, 'fragrance' => false],
    ['grams_milli' => 11250, 'cost_nano_per_milligram' => 22000, 'fragrance' => false],
    ['grams_milli' => 7500, 'cost_nano_per_milligram' => 250000, 'fragrance' => true],
];
$settings = [
    'retail_multiplier_bp' => 21000,   // 2.10
    'minimum_markup_bp' => 4000,       // 40%
    'quantity_exponent' => 0.23,
    'retail_step_millis' => 500,       // 0.50 TND
    'quantity_step_millis' => 50,      // 0.05 TND
    'packaging_millis' => 1000,        // 1 TND
    'density_g_per_ml' => 1.0,
    'fragrance_density_confirmed' => false,
];

$one = CandlePricing::price(75000, $recipe, 1, $settings);
$check($one['materials_millis'] === 4635, 'Materials for a 75 g candle cost 4.635 TND.');
$check($one['known_unit_cost_millis'] === 5635, 'With 1 TND packaging the known unit cost is 5.635 TND.');
$check($one['retail_millis'] === 12000, 'Retail is 5.635 x 2.10 = 11.834, rounded up to 12.0 TND.');
$check($one['minimum_millis'] === 7889, 'The minimum is 1.40 x 5.635 = 7.889 TND.');
$check($one['unit_price_millis'] === 12000, 'Quantity 1 is exactly the retail price.');
$check($one['costing'] === 'provisional_materials_and_packaging', 'Costs are labelled provisional.');
$check($one['fragrance_density_provisional'] === true, 'Fragrance is labelled provisional until density is confirmed.');

// Scaling the weight scales each material: a 37.5 g candle uses half the grams.
$half = CandlePricing::price(37500, $recipe, 1, $settings);
$check($half['materials_millis'] === 2318, 'Half the weight costs 2.3175 TND in materials, rounded half up to 2.318 TND.');

// A denser fragrance means fewer millilitres of oil and a lower cost.
$dense = CandlePricing::price(75000, $recipe, 1, array_replace($settings, ['density_g_per_ml' => 2.0]));
$check($dense['materials_millis'] < $one['materials_millis'], 'A denser fragrance (2 g/ml) costs less per gram.');

$confirmed = CandlePricing::price(75000, $recipe, 1, array_replace($settings, ['fragrance_density_confirmed' => true]));
$check($confirmed['fragrance_density_provisional'] === false, 'Once density is confirmed the fragrance is no longer provisional.');

$previous = null;
foreach ([2, 10, 50, 100, 1000] as $qty) {
    $r = CandlePricing::price(75000, $recipe, $qty, $settings);
    $unit = $r['unit_price_millis'];
    $check($unit >= $one['minimum_millis'], "Quantity $qty never goes below the minimum price.");
    $check($unit % 50 === 0, "Quantity $qty is rounded up to 0.05 TND.");
    $check($previous === null || $unit <= $previous, "Quantity $qty is not dearer per candle than a smaller order.");
    $check($r['order_total_millis'] === $unit * $qty, "Quantity $qty total is unit price times quantity.");
    $previous = $unit;
}

$check(CandlePricing::unitPrice(2, 7889, 12000, $settings) === 11400, 'A pair is 4.111 / 2^0.23 above the minimum, rounded up: 11.400 TND each.');
$check(CandlePricing::price(75000, $recipe, 100000000, $settings)['unit_price_millis'] === 7950, 'A 100 million candle order settles just above the minimum, rounded up to 7.950 TND.');

$threw = 0;
foreach ([
    static fn () => CandlePricing::price(0, $recipe, 1, $settings),
    static fn () => CandlePricing::price(75000, $recipe, 0, $settings),
    static fn () => CandlePricing::price(75000, [], 1, $settings),
] as $bad) {
    try {
        $bad();
    } catch (DomainException) {
        $threw++;
    }
}
$check($threw === 3, 'Zero weight, zero quantity and an empty recipe are rejected.');

echo "Candle pricing checks passed.\n";
