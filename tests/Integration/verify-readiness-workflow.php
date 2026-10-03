<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Readiness Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: proves that activation requirements stay true after activation,
//       using the real admin services against a throwaway MySQL/MariaDB
//       database (tests/Support/throwaway-database.php).
// WHY:  readiness used to be checked only when a product was switched on.
//       Every mutation now goes through ProductReadinessService: edits that
//       would break an active product are rejected with nothing saved, and
//       products broken indirectly return to draft in the same transaction.
// SETUP STEP: Load the application code and the throwaway-database helper.
require_once __DIR__ . '/../Support/throwaway-database.php';

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminProductRepository;
use App\Repositories\MaterialMovementRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRecipeRepository;
use App\Repositories\TaxonomyRepository;
use App\Services\AccessoryStockService;
use App\Services\AdminProductService;
use App\Services\MaterialService;
use App\Services\PricingService;
use App\Services\ProductImageStorage;
use App\Services\ProductReadinessService;
use App\Services\ProductRecipeService;
use App\Services\TaxonomyService;

// DATABASE SETUP: A fresh database with every migration and seed.sql.
[$db, $databaseName] = create_throwaway_database('readiness');
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
};

try {
    // Reference data: wax 25.000/kg, two colors, two scents.
    $db->exec("INSERT INTO materials (id, material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
        VALUES (1, 1, 'WAX', 'Soy wax', 'kg', NULL, 25.000, 0, 1)");
    $db->exec("INSERT INTO colors (id, slug, hex_code, is_active) VALUES (1, 'ivory', '#FFFFF0', 1), (2, 'blush', '#F4DADA', 1)");
    $db->exec("INSERT INTO scents (id, slug, is_active) VALUES (1, 'vanilla', 1), (2, 'rose', 1)");

    // A complete, sellable product: translations, main image, one photographed
    // color, one scent, and (for candles) an actual recipe.
    $makeProduct = static function (int $id, int $categoryId, ?int $typeId, string $slug) use ($db): void {
        $db->prepare("INSERT INTO products (id, category_id, product_type_id, slug, minimum_quantity, purchase_mode, main_image_path, is_active)
            VALUES (:id, :category, :type, :slug, 1, 'CART', :image, 0)")
            ->execute(['id' => $id, 'category' => $categoryId, 'type' => $typeId, 'slug' => $slug, 'image' => "assets/products/{$slug}.jpg"]);
        foreach (['en', 'fr', 'ar'] as $language) {
            $db->prepare("INSERT INTO product_translations (product_id, language_code, name, description) VALUES (:id, :language, :name, 'Handmade')")
                ->execute(['id' => $id, 'language' => $language, 'name' => ucwords(str_replace('-', ' ', $slug))]);
        }
        $db->prepare('INSERT INTO product_colors (product_id, color_id, image_path) VALUES (:id, 1, :image)')
            ->execute(['id' => $id, 'image' => "assets/products/{$slug}-ivory.jpg"]);
        $db->prepare('INSERT INTO product_scents (product_id, scent_id) VALUES (:id, 1)')->execute(['id' => $id]);
    };
    $makeProduct(1, 1, 1, 'rose-candle');
    $makeProduct(2, 1, 1, 'bubble-candle');
    $makeProduct(3, 2, null, 'duo-set');
    $makeProduct(4, 1, 1, 'lonely-candle');
    $db->exec("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES
        (1, 1, 'ACTUAL', 0.200), (2, 1, 'ACTUAL', 0.100), (4, 1, 'ACTUAL', 0.300)");
    $db->exec('INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (3, 1, 1), (3, 2, 1)');

    // The real services, wired as in app/Bootstrap/admin/*.php.
    $transactions = new TransactionManager($db);
    $pricing = new PricingService(new PricingRepository($db), $transactions);
    $readiness = new ProductReadinessService(new AdminProductRepository($db), $pricing, $transactions);
    $products = new AdminProductService(
        new AdminProductRepository($db),
        new TaxonomyRepository($db),
        $transactions,
        new ProductImageStorage(PRODUCT_IMAGE_PATH),
        $pricing,
        $readiness,
        new AccessoryStockService(new AccessoryStockRepository($db), $transactions)
    );
    $recipes = new ProductRecipeService(new ProductRecipeRepository($db), $pricing, $readiness);
    $taxonomy = new TaxonomyService(new TaxonomyRepository($db), $readiness);
    $materials = new MaterialService(
        new MaterialRepository($db),
        new MaterialMovementRepository($db),
        new MaterialStockRepository($db, $transactions),
        $transactions,
        $readiness
    );
    $isActive = static fn (int $id): bool => (int) $db->query("SELECT is_active FROM products WHERE id = {$id}")->fetchColumn() === 1;
    $activateAll = static function (array $ids) use ($products, $check): void {
        foreach ($ids as $id) {
            $result = $products->toggleActive($id, true);
            if (!$result['success']) {
                throw new RuntimeException("Product {$id} could not be activated: " . json_encode($result['errors']));
            }
        }
    };

    // 0. Baseline: prices are calculated and every product can be activated.
    $readiness->settle();
    $activateAll([1, 2, 3, 4]);
    $check($isActive(1) && $isActive(2) && $isActive(3) && $isActive(4), 'Complete products can be activated.');

    // 1. Direct edits that would break an active product are rejected, and nothing is saved.
    $result = $products->saveTranslation(1, 'fr', '', '');
    $frenchKept = (int) $db->query("SELECT COUNT(*) FROM product_translations WHERE product_id = 1 AND language_code = 'fr'")->fetchColumn() === 1;
    $check(!$result['success'] && str_contains($result['errors']['form'], 'French') && $frenchKept && $isActive(1), 'Clearing a required translation is rejected and rolled back.');

    $result = $products->removeColor(1, 1);
    $colorKept = (int) $db->query('SELECT COUNT(*) FROM product_colors WHERE product_id = 1')->fetchColumn() === 1;
    $check(!$result['success'] && isset($result['errors']['color']) && $colorKept, 'Removing the last active color is rejected.');

    $result = $products->removeScent(1, 1);
    $check(!$result['success'] && isset($result['errors']['scent']), 'Removing the last active scent is rejected.');

    $result = $recipes->removeLine(1, 'ACTUAL', 1);
    $recipeKept = (int) $db->query('SELECT COUNT(*) FROM product_materials WHERE product_id = 1')->fetchColumn() === 1;
    $priceKept = $db->query('SELECT selling_price FROM products WHERE id = 1')->fetchColumn() === '10.000';
    $check(!$result['success'] && $recipeKept && $priceKept, 'Removing the last recipe line is rejected; recipe and price are unchanged.');

    $result = $products->removeComponent(3, 1);
    $componentKept = (int) $db->query('SELECT COUNT(*) FROM set_components WHERE set_product_id = 3')->fetchColumn() === 2;
    $check(!$result['success'] && isset($result['errors']['component']) && $componentKept, 'Leaving an active set with one candle is rejected.');

    $result = $products->saveCore(1, ['category_id' => '3', 'product_type_id' => '4', 'slug' => 'rose-candle', 'minimum_quantity' => '1']);
    $categoryKept = (int) $db->query('SELECT category_id FROM products WHERE id = 1')->fetchColumn() === 1;
    $check(!$result['success'] && $categoryKept, 'A core change that breaks readiness (bouquet without candle count) is rejected.');

    // 2. Edits that keep the product complete still succeed.
    $result = $products->saveTranslation(1, 'fr', 'Bougie rose', 'Faite main');
    $check($result['success'] && $isActive(1), 'A valid translation edit leaves the active product available.');

    // 3. Adding a color returns the product to draft until its photo exists —
    //    and the set that contains it follows, in the same transaction.
    $result = $products->addColor(1, 2);
    $check($result['success'] && !$isActive(1) && !$isActive(3), 'Adding an unphotographed color returns the product and its set to draft.');
    $check(str_contains((string) $result['notice'], 'Rose Candle') && str_contains((string) $result['notice'], 'Duo Set'), 'The notice names every product returned to draft.');
    $db->exec("UPDATE product_colors SET image_path = 'assets/products/rose-candle-blush.jpg' WHERE product_id = 1 AND color_id = 2");
    $activateAll([1, 3]);

    // 4. Deactivating a component returns its set to draft.
    $result = $products->toggleActive(2, false);
    $check($result['success'] && !$isActive(3) && str_contains((string) $result['notice'], 'Every set component is active'), 'Deactivating a component returns its set to draft.');
    $activateAll([2, 3]);

    // 5. Retiring a scent returns every product that relied on it to draft.
    $result = $taxonomy->toggleActive('scents', 1, false);
    $check($result['success'] && !$isActive(1) && !$isActive(2) && !$isActive(3) && !$isActive(4), 'Retiring the only scent returns its products to draft.');
    $check(str_contains((string) $result['notice'], 'At least one active scent'), 'The notice explains which requirement failed.');
    $taxonomy->toggleActive('scents', 1, true);
    $activateAll([1, 2, 3, 4]);

    // 6. A material cost of zero leaves candles and the set unpriced: all return to draft.
    $result = $materials->save(1, ['name' => 'Soy wax', 'unit_cost' => '0.000', 'minimum_stock_level' => '2.000', 'is_active' => '1']);
    $check($result['success'] && !$isActive(1) && !$isActive(3) && is_string($result['notice']), 'A cost change that makes products unpriceable returns them to draft.');
    $materials->save(1, ['name' => 'Soy wax', 'unit_cost' => '25.000', 'minimum_stock_level' => '2.000', 'is_active' => '1']);
    $activateAll([1, 2, 3, 4]);

    // 7. A change with no consequence reports nothing.
    $result = $products->toggleActive(4, false);
    $check($result['success'] && $result['notice'] === null && $isActive(3), 'Deactivating an unrelated product affects nothing else.');
} finally {
    // Always remove the throwaway database, success or failure.
    drop_throwaway_database($db, $databaseName);
}

// RESULT STEP: Report success only after every assertion above has passed.
echo "Readiness workflow checks passed.\n";
