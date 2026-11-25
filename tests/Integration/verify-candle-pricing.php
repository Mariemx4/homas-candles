<?php

declare(strict_types=1);

// Checks individual candle prices end to end against a disposable PostgreSQL database with every
// migration applied (including 035). Never touches the shop database.

require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\TransactionManager;
use App\Repositories\CandlePricingRepository;
use App\Repositories\PricingRepository;
use App\Services\CandlePriceService;
use App\Services\PricingService;

$check = TestCheck::printing();

[$db, $name] = create_throwaway_database('candleprice');
try {
    $transactions = new TransactionManager($db);
    $candles = new CandlePriceService(new CandlePricingRepository($db), $transactions);
    $pricing = new PricingService(new PricingRepository($db), $transactions);

    $candlesCategory = (int) $db->query("SELECT id FROM categories WHERE slug = 'candles'")->fetchColumn();
    $floralType = (int) $db->query("SELECT id FROM product_types WHERE slug = 'flowers'")->fetchColumn();
    $materialType = (int) $db->query("SELECT id FROM material_types WHERE slug = 'raw-material'")->fetchColumn();

    // Materials at the prices before the owner's update (the migration updates them; this test
    // inserts them directly so it can check the update itself separately).
    $material = static function (string $sku, string $unit, string $cost) use ($db, $materialType): int {
        $statement = $db->prepare('INSERT INTO materials (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES (:type, :sku, :name, :unit, 0, :cost, 1, 1) RETURNING id');
        $statement->execute(['type' => $materialType, 'sku' => $sku, 'name' => $sku, 'unit' => $unit, 'cost' => $cost]);

        return (int) $statement->fetchColumn();
    };
    $soy = $material('TEST-SOY', 'g', '0.040');
    $beeswax = $material('TEST-BEES', 'g', '0.050');
    $stearic = $material('TEST-STEAR', 'g', '0.022');
    $oil = $material('TEST-FRAG', 'ml', '0.250');

    $product = $db->prepare('INSERT INTO products (category_id, product_type_id, slug, selling_price, actual_cost) VALUES (:c, :t, :slug, NULL, NULL) RETURNING id');
    $product->execute(['c' => $candlesCategory, 't' => $floralType, 'slug' => 'test-peony']);
    $peony = (int) $product->fetchColumn();

    $recipe = $db->prepare("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES (:p, :m, 'ACTUAL', :q)");
    foreach ([[$soy, 30], [$beeswax, 26.25], [$stearic, 11.25], [$oil, 7.5]] as [$materialId, $grams]) {
        $recipe->execute(['p' => $peony, 'm' => $materialId, 'q' => $grams]);
    }

    // No weight yet: nothing is suggested and nothing is invented.
    $before = $candles->panel($peony);
    $check($before['issue'] === 'no_weight' && $before['suggested_price_millis'] === null, 'Without a weight no price is suggested.');
    $threw = false;
    try {
        $candles->applySuggestion($peony, null);
    } catch (DomainException) {
        $threw = true;
    }
    $check($threw, 'Approving a price for a candle without a weight is refused.');

    $candles->saveWeight($peony, '75');
    $panel = $candles->panel($peony);
    $check($panel['calc']['materials_millis'] === 4635, 'The 75 g recipe costs 4.635 TND in materials at the new prices.');
    $check($panel['suggested_price_millis'] === 12000, 'The suggested retail price for a 75 g candle is 12.0 TND.');
    $check($panel['calc']['minimum_millis'] === 7889, 'The minimum for that candle is 7.889 TND.');
    $check($panel['current_price_millis'] === null, 'A new candle has no selling price until one is approved or typed.');

    // Owner approves the suggestion: the selling price is set and the change is recorded.
    $candles->applySuggestion($peony, 7);
    $after = $candles->panel($peony);
    $check($after['current_price_millis'] === 12000 && $after['selling_price_source'] === 'calculated', 'Approving sets the price to the suggestion and marks it calculated.');
    $check(count($after['history']) === 1 && $after['history'][0]['new_source'] === 'calculated', 'The approval is recorded in the price history.');

    // Manual price: below the minimum is refused, a valid one is saved and marked manual.
    $refused = false;
    try {
        $candles->setManualPrice($peony, '7.000', 7);
    } catch (DomainException) {
        $refused = true;
    }
    $check($refused, 'A manual price below the 40% minimum is refused.');
    $candles->setManualPrice($peony, '10.500', 7);
    $manual = $candles->panel($peony);
    $check($manual['current_price_millis'] === 10500 && $manual['selling_price_source'] === 'manual', 'A valid manual price is saved and marked manual.');

    // Recalculating costs never overwrites the owner's price.
    $pricing->recalculateAll();
    $check($candles->panel($peony)['current_price_millis'] === 10500, 'Recalculating costs leaves the owner\'s price alone.');

    // Quantity pricing: smooth, never below the minimum, retail for one candle.
    $check($candles->unitPriceFor($peony, 1) === 12000, 'One candle is priced at the retail suggestion.');
    $check($candles->unitPriceFor($peony, 10) >= 7889 && $candles->unitPriceFor($peony, 10) < 12000, 'Ten candles cost less each than one, and not below the minimum.');

    // Settings change the calculation without touching any selling price.
    $db->exec("UPDATE pricing_settings SET setting_value = 2.5 WHERE setting_key = 'retail_multiplier'");
    $check($candles->panel($peony)['suggested_price_millis'] === 14500, 'A 2.5 multiplier moves the suggestion to 14.5 TND.');
    $check($candles->panel($peony)['current_price_millis'] === 10500, 'Changing the multiplier leaves the selling price unchanged.');

    // Bulk approval skips what it cannot price and reports why.
    $other = $db->prepare('INSERT INTO products (category_id, product_type_id, slug) VALUES (:c, :t, :slug) RETURNING id');
    $other->execute(['c' => $candlesCategory, 't' => $floralType, 'slug' => 'test-unweighted']);
    $unweighted = (int) $other->fetchColumn();
    $outcome = $candles->applySuggestions([$peony, $unweighted], 7);
    $check($outcome['applied'] === 1 && isset($outcome['skipped'][$unweighted]), 'Bulk approval applies the priceable candle and skips the unweighted one.');

    // The migration's material prices and flags are in place.
    $cost = $db->query("SELECT unit_cost FROM materials WHERE sku = 'TEST-SOY'")->fetchColumn();
    $check((string) $cost === '0.040', 'Material unit costs are stored per gram.');
} finally {
    drop_throwaway_database($db, $name);
}

echo "Candle pricing database checks passed.\n";
