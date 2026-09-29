<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Catalogue Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-catalogue.php';

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$administratorId = null;
$materialId = null;
$colorId = null;
$scentId = null;
$productIds = [];

try {
    $administrator = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, \'ACTIVE\')'
    );
    $administrator->execute([
        'first_name' => 'Catalogue', 'last_name' => 'Verifier',
        'email' => "catalogue-{$suffix}@example.test", 'phone' => '7' . substr($suffix, -7),
        'password_hash' => password_hash('CatalogueVerifier123!', PASSWORD_DEFAULT),
    ]);
    $administratorId = (int) $databaseConnection->lastInsertId();

    // === Taxonomy: colors ===
    $colorSave = $taxonomyService->save('colors', null, [
        'slug' => "m06-color-{$suffix}", 'name_en' => "Test Color {$suffix}",
        'hex_code' => '#F4DADA', 'is_active' => '1',
    ]);
    $check($colorSave['success'], 'A color can be created with a valid hex code.');
    $colorId = $colorSave['id'];

    $duplicateColor = $taxonomyService->save('colors', null, [
        'slug' => "m06-color-{$suffix}", 'name_en' => 'Duplicate', 'is_active' => '1',
    ]);
    $check(!$duplicateColor['success'] && isset($duplicateColor['errors']['slug']), 'A duplicate color slug is rejected.');

    $badHex = $taxonomyService->save('colors', null, [
        'slug' => "m06-color-bad-{$suffix}", 'name_en' => 'Bad Hex', 'hex_code' => 'not-a-color', 'is_active' => '1',
    ]);
    $check(!$badHex['success'] && isset($badHex['errors']['hex_code']), 'An invalid hex code is rejected.');

    // === Taxonomy: scents linked to a material ===
    $material = $databaseConnection->prepare(
        "INSERT INTO materials (material_type_id, sku, name, unit, unit_cost, tracks_stock, is_active)
         VALUES (1, :sku, :name, 'g', 1.000, 0, 1)"
    );
    $material->execute(['sku' => "M06-MAT-{$suffix}", 'name' => "Fragrance {$suffix}"]);
    $materialId = (int) $databaseConnection->lastInsertId();

    $scentSave = $taxonomyService->save('scents', null, [
        'slug' => "m06-scent-{$suffix}", 'name_en' => "Test Scent {$suffix}",
        'fragrance_material_id' => (string) $materialId, 'is_active' => '1',
    ]);
    $check($scentSave['success'], 'A scent can be linked to an active material.');
    $scentId = $scentSave['id'];

    $secondScentAttempt = $taxonomyService->save('scents', null, [
        'slug' => "m06-scent-2-{$suffix}", 'name_en' => 'Second Scent', 'fragrance_material_id' => (string) $materialId, 'is_active' => '1',
    ]);
    $check(
        !$secondScentAttempt['success'] && isset($secondScentAttempt['errors']['fragrance_material_id']),
        'A material already linked to a scent cannot be linked to a second scent.'
    );

    $toggle = $taxonomyService->toggleActive('colors', $colorId, false);
    $check($toggle['success'], 'A taxonomy record can be deactivated.');
    $reactivate = $taxonomyService->toggleActive('colors', $colorId, true);
    $check($reactivate['success'], 'A taxonomy record can be reactivated.');

    // === Products: accessory draft -> readiness -> activation ===
    $accessoryCore = $adminProductService->saveCore(null, [
        'category_id' => '6', // accessories (seeded)
        'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check(!$accessoryCore['success'] && isset($accessoryCore['errors']['stock_quantity']), 'An accessory requires a stock quantity to be recorded.');
    $check(isset($accessoryCore['errors']['product_type_id']), 'A non-set product requires a product type.');

    $accessoryCore = $adminProductService->saveCore(null, [
        'category_id' => '6', 'product_type_id' => '7', 'stock_quantity' => '25',
        'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($accessoryCore['success'], 'A valid accessory draft can be created.');
    $accessoryId = $accessoryCore['id'];
    $productIds[] = $accessoryId;

    $notReady = $adminProductService->toggleActive($accessoryId, true);
    $check(!$notReady['success'], 'A draft missing slug, price, translations, and an image cannot be activated.');

    $slugSave = $adminProductService->saveCore($accessoryId, [
        'category_id' => '6', 'product_type_id' => '7', 'stock_quantity' => '25',
        'slug' => "m06-accessory-{$suffix}", 'selling_price' => '12.500',
        'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($slugSave['success'], 'A slug can be saved on an existing draft.');
    // Prices come only from PricingService; a typed selling_price is ignored.
    $check($adminProductService->detail($accessoryId)['product']['selling_price'] === null, 'A submitted selling price is ignored; prices are calculated.');

    // The accessory's purchase cost is its actual recipe: 12.5 × the 1.000 test material.
    $databaseConnection->prepare(
        "INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES (:product, :material, 'ACTUAL', 12.500)"
    )->execute(['product' => $accessoryId, 'material' => $materialId]);
    $pricingService->recalculateAll();
    $priced = $adminProductService->detail($accessoryId);
    $check(
        $priced['product']['selling_price'] === '12.500' && $priced['product']['price_rule'] === 'ACCESSORY_PURCHASE_COST',
        'The accessory is priced at its purchase cost, with no markup.'
    );

    foreach (['en', 'fr', 'ar'] as $language) {
        $translationSave = $adminProductService->saveTranslation($accessoryId, $language, "Name {$language} {$suffix}", "Description {$language} {$suffix}");
        $check($translationSave['success'], "The {$language} translation can be saved.");
    }

    $mismatchedTranslation = $adminProductService->saveTranslation($accessoryId, 'en', 'Only a name', '');
    $check(
        !$mismatchedTranslation['success'] && isset($mismatchedTranslation['errors']['form']),
        'A translation cannot have a name without a description, or vice versa.'
    );

    $stillNotReady = $adminProductService->toggleActive($accessoryId, true);
    $check(!$stillNotReady['success'], 'A product without a main image still cannot be activated.');

    $plantedImage = bin2hex(random_bytes(10)) . '.jpg';
    $imagesDirectory = PRODUCT_IMAGE_PATH;
    file_put_contents($imagesDirectory . DIRECTORY_SEPARATOR . $plantedImage, 'not a real jpeg, just proving the round trip');
    $databaseConnection->prepare('UPDATE products SET main_image_path = :path WHERE id = :id')
        ->execute(['path' => 'assets/products/' . $plantedImage, 'id' => $accessoryId]);

    $readyNow = $adminProductService->toggleActive($accessoryId, true);
    $check($readyNow['success'], 'A fully-configured accessory can be activated once every readiness item is met.');

    $doubleCheck = $adminProductService->detail($accessoryId);
    $check((int) $doubleCheck['product']['is_active'] === 1, 'The activated accessory is marked active.');

    $deactivate = $adminProductService->toggleActive($accessoryId, false);
    $check($deactivate['success'], 'An active product can always be deactivated without a readiness gate.');

    // === Products: color assignment and per-color image readiness ===
    $candleCore = $adminProductService->saveCore(null, [
        'category_id' => '1', 'product_type_id' => '3', 'slug' => "m06-candle-{$suffix}",
        'selling_price' => '18.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($candleCore['success'], 'A candle product can be created.');
    $candleId = $candleCore['id'];
    $productIds[] = $candleId;

    $addColor = $adminProductService->addColor($candleId, $colorId);
    $check($addColor['success'], 'An active color can be assigned to a product.');
    $duplicateAssign = $adminProductService->addColor($candleId, $colorId);
    $check(!$duplicateAssign['success'], 'The same color cannot be assigned to a product twice.');

    $detailWithColor = $adminProductService->detail($candleId);
    $colorCheck = array_values(array_filter($detailWithColor['readiness']['items'], static fn (array $item): bool => $item['key'] === 'color_images'))[0];
    $check(!$colorCheck['met'], 'Readiness reports a missing photo for an assigned color with no image.');

    $removeColor = $adminProductService->removeColor($candleId, $colorId);
    $check($removeColor['success'], 'A color assignment can be removed.');
    $detailAfterRemoval = $adminProductService->detail($candleId);
    $check($detailAfterRemoval['assignedColors'] === [], 'Removing a color assignment clears it from the product.');

    // === Products: candle-set component rules ===
    $secondCandleCore = $adminProductService->saveCore(null, [
        'category_id' => '1', 'product_type_id' => '3', 'slug' => "m06-candle-2-{$suffix}",
        'selling_price' => '18.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $secondCandleId = $secondCandleCore['id'];
    $productIds[] = $secondCandleId;
    $databaseConnection->prepare("UPDATE products SET is_active = 1 WHERE id IN (:a, :b)")
        ->execute(['a' => $candleId, 'b' => $secondCandleId]);

    $setCore = $adminProductService->saveCore(null, [
        'category_id' => '2', 'product_type_id' => '3', 'slug' => "m06-set-{$suffix}",
        'selling_price' => '30.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($setCore['success'], 'A candle-set product can be created.');
    $setId = $setCore['id'];
    $productIds[] = $setId;
    $setAfterSave = $adminProductService->detail($setId);
    $check($setAfterSave['product']['product_type_id'] === null, 'A candle-sets product never stores a product type, even if one was submitted.');

    $selfComponent = $adminProductService->addComponent($setId, $setId, 1);
    $check(!$selfComponent['success'], 'A set cannot contain itself as a component.');

    $addComponent1 = $adminProductService->addComponent($setId, $candleId, 1);
    $check($addComponent1['success'], 'An active candle can be added as a set component.');
    $notEnoughCandles = $adminProductService->toggleActive($setId, true);
    $check(!$notEnoughCandles['success'], 'A set with only one candle component cannot be activated.');

    $addComponent2 = $adminProductService->addComponent($setId, $secondCandleId, 1);
    $check($addComponent2['success'], 'A second active candle can be added as a set component.');

    $inactiveComponent = $adminProductService->addComponent($setId, $accessoryId, 1);
    // accessoryId was deactivated above.
    $check(!$inactiveComponent['success'], 'An inactive product cannot be added as a set component.');

    $secondSetCore = $adminProductService->saveCore(null, [
        'category_id' => '2', 'slug' => "m06-set-2-{$suffix}",
        'selling_price' => '30.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $secondSetId = $secondSetCore['id'];
    $productIds[] = $secondSetId;
    $nestedSet = $adminProductService->addComponent($setId, $secondSetId, 1);
    $check(!$nestedSet['success'], 'A candle set cannot contain another candle set as a component.');

    // === CSRF and authorization at the controller layer ===
    $invalidCsrf = $taxonomyController->processSave('colors', [
        'csrf_token' => str_repeat('0', 64), 'slug' => 'irrelevant', 'name_en' => 'Irrelevant', 'is_active' => '1',
    ]);
    $check(!$invalidCsrf['success'], 'Invalid CSRF prevents a taxonomy record from being saved.');

    $invalidCsrfProduct = $adminProductController->processAction(
        ['csrf_token' => str_repeat('0', 64), 'action' => 'activate', 'id' => (string) $candleId],
        [],
        $administratorId
    );
    $check(!$invalidCsrfProduct['success'], 'Invalid CSRF prevents a product action from being processed.');

    $activeAdministrator = $administratorAuthorization->activeAdministrator($administratorId);
    $check(is_array($activeAdministrator), 'An active administrator passes the server-side authorization check.');
    $databaseConnection->prepare("UPDATE administrators_accounts SET account_status = 'SUSPENDED' WHERE id = :id")
        ->execute(['id' => $administratorId]);
    $check(
        $administratorAuthorization->activeAdministrator($administratorId) === null,
        'A suspended administrator is rejected before catalogue routes can proceed.'
    );

    // === Injection safety in list filters ===
    $unsafeList = $adminProductService->list(['category' => "1' OR '1'='1", 'active' => 'yes', 'page' => '-1']);
    $check(
        $unsafeList['filters']['category'] === null && $unsafeList['filters']['active'] === null && $unsafeList['page'] === 1,
        'Unsupported category, status, and page values fall back through allow lists instead of reaching SQL.'
    );
} finally {
    if ($productIds !== []) {
        $ids = array_values(array_unique($productIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM set_components WHERE set_product_id IN ({$placeholders}) OR component_product_id IN ({$placeholders})")
            ->execute(array_merge($ids, $ids));
        $databaseConnection->prepare("DELETE FROM product_colors WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM product_scents WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM product_translations WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM products WHERE id IN ({$placeholders})")->execute($ids);
    }
    if (isset($plantedImage, $imagesDirectory)) {
        @unlink($imagesDirectory . DIRECTORY_SEPARATOR . $plantedImage);
    }
    if ($scentId !== null) {
        $databaseConnection->prepare('DELETE FROM scent_translations WHERE scent_id = :id')->execute(['id' => $scentId]);
        $databaseConnection->prepare('DELETE FROM scents WHERE id = :id')->execute(['id' => $scentId]);
    }
    if ($colorId !== null) {
        $databaseConnection->prepare('DELETE FROM color_translations WHERE color_id = :id')->execute(['id' => $colorId]);
        $databaseConnection->prepare('DELETE FROM colors WHERE id = :id')->execute(['id' => $colorId]);
    }
    if ($materialId !== null) {
        $databaseConnection->prepare('DELETE FROM materials WHERE id = :id')->execute(['id' => $materialId]);
    }
    if ($administratorId !== null) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Catalogue workflow verification completed without leaving test rows.' . PHP_EOL;
