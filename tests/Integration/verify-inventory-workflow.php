<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Inventory Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-inventory.php';
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
$materialIds = [];
$productIds = [];

$materialRow = static function (int $materialId) use ($databaseConnection): array {
    $statement = $databaseConnection->prepare('SELECT * FROM materials WHERE id = :id');
    $statement->execute(['id' => $materialId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException("Material {$materialId} could not be reloaded.");
    }

    return $row;
};

try {
    $administrator = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, \'ACTIVE\')'
    );
    $administrator->execute([
        'first_name' => 'Inventory', 'last_name' => 'Verifier',
        'email' => "inventory-{$suffix}@example.test", 'phone' => '7' . substr($suffix, -7),
        'password_hash' => password_hash('InventoryVerifier123!', PASSWORD_DEFAULT),
    ]);
    $administratorId = (int) $databaseConnection->lastInsertId();

    // === Material creation ===
    $missingType = $materialService->save(null, [
        'sku' => "M07-{$suffix}", 'name' => 'Missing Type', 'unit' => 'g', 'unit_cost' => '1.000',
        'tracks_stock' => '1', 'is_active' => '1',
    ]);
    $check(!$missingType['success'] && isset($missingType['errors']['material_type_id']), 'A material without a type is rejected.');

    $created = $materialService->save(null, [
        'material_type_id' => '1', 'sku' => "M07-{$suffix}", 'name' => "Test Wax {$suffix}", 'unit' => 'g',
        'unit_cost' => '2.500', 'tracks_stock' => '1', 'initial_stock_quantity' => '100.000', 'is_active' => '1',
    ]);
    $check($created['success'], 'A material can be created with an opening stock quantity.');
    $materialId = $created['id'];
    $materialIds[] = $materialId;

    $row = $materialRow($materialId);
    $check($row['stock_quantity'] === '100.000', 'The opening stock quantity is applied to the new material.');
    $movementStatement = $databaseConnection->prepare("SELECT movement_type, stock_before, stock_after FROM material_stock_movements WHERE material_id = :id");
    $movementStatement->execute(['id' => $materialId]);
    $openingMovement = $movementStatement->fetch();
    $check(
        $openingMovement['movement_type'] === 'STOCK_IN' && $openingMovement['stock_before'] === '0.000' && $openingMovement['stock_after'] === '100.000',
        'Opening stock is recorded as a genuine STOCK_IN ledger movement, never a raw balance write.'
    );

    $duplicateSku = $materialService->save(null, [
        'material_type_id' => '1', 'sku' => "M07-{$suffix}", 'name' => 'Duplicate SKU', 'unit' => 'g',
        'unit_cost' => '1.000', 'tracks_stock' => '0', 'is_active' => '1',
    ]);
    $check(!$duplicateSku['success'] && isset($duplicateSku['errors']['sku']), 'A duplicate SKU is rejected.');

    // === Identity lock once referenced ===
    // The opening STOCK_IN recorded during creation already references this material.
    $detailAfterMovement = $materialService->detail($materialId);
    $check($detailAfterMovement['referenced'] === true, 'A material with a recorded movement is reported as referenced.');

    $lockedAttempt = $materialService->save($materialId, [
        'material_type_id' => '6', 'sku' => 'ATTEMPTED-CHANGE', 'name' => 'Renamed Wax', 'unit' => 'kg',
        'unit_cost' => '3.000', 'tracks_stock' => '0', 'is_active' => '1',
    ]);
    $check($lockedAttempt['success'], 'Editing a referenced material succeeds for its editable fields.');
    $lockedRow = $materialRow($materialId);
    $check(
        (int) $lockedRow['material_type_id'] === 1 && $lockedRow['sku'] === "M07-{$suffix}"
        && $lockedRow['unit'] === 'g' && (int) $lockedRow['tracks_stock'] === 1
        && $lockedRow['name'] === 'Renamed Wax' && $lockedRow['unit_cost'] === '3.000',
        'A referenced material keeps its locked identity fields while its name and cost still update.'
    );

    // === Manual stock movements ===
    $noteRequired = $materialController->processAction([
        'csrf_token' => $csrf->token(), 'action' => 'record_movement', 'id' => (string) $materialId,
        'movement_type' => 'ADJUSTMENT_OUT', 'quantity_change' => '5.000', 'movement_note' => '',
    ], $administratorId);
    $check(!$noteRequired['success'] && isset($noteRequired['errors']['movement_note']), 'An adjustment without a note is rejected.');

    $adjustOut = $materialController->processAction([
        'csrf_token' => $csrf->token(), 'action' => 'record_movement', 'id' => (string) $materialId,
        'movement_type' => 'ADJUSTMENT_OUT', 'quantity_change' => '5.000', 'movement_note' => 'Verification adjustment.',
    ], $administratorId);
    $check($adjustOut['success'], 'A noted adjustment succeeds.');
    $check($materialRow($materialId)['stock_quantity'] === '95.000', 'The adjustment reduces the balance by the requested amount.');

    $tooMuch = $materialController->processAction([
        'csrf_token' => $csrf->token(), 'action' => 'record_movement', 'id' => (string) $materialId,
        'movement_type' => 'DAMAGED_OR_WASTED', 'quantity_change' => '99999.000', 'movement_note' => 'Would go negative.',
    ], $administratorId);
    $check(!$tooMuch['success'], 'A movement that would make stock negative is rejected.');
    $check($materialRow($materialId)['stock_quantity'] === '95.000', 'A rejected movement leaves the balance unchanged.');

    // === Movement history ===
    $history = $materialService->movementHistory(['material' => (string) $materialId]);
    $check($history['total'] >= 2, 'Movement history lists every recorded movement for the material.');
    $typedHistory = $materialService->movementHistory(['material' => (string) $materialId, 'type' => 'ADJUSTMENT_OUT']);
    $check($typedHistory['total'] === 1, 'Movement history filters by movement type.');

    // === Recipe editor ===
    $toolMaterial = $materialService->save(null, [
        'material_type_id' => '8', 'sku' => "M07-TOOL-{$suffix}", 'name' => 'Test Tool', 'unit' => 'piece',
        'unit_cost' => '0.000', 'tracks_stock' => '0', 'is_active' => '1',
    ]);
    $check($toolMaterial['success'], 'A non-stock-tracked tool material can be created.');
    $toolMaterialId = $toolMaterial['id'];
    $materialIds[] = $toolMaterialId;

    $recipeProduct = $adminProductService->saveCore(null, [
        'category_id' => '1', 'product_type_id' => '3', 'slug' => "m07-candle-{$suffix}",
        'selling_price' => '20.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($recipeProduct['success'], 'A product can be created to attach a recipe to.');
    $recipeProductId = $recipeProduct['id'];
    $productIds[] = $recipeProductId;

    $addLine = $productRecipeService->addLine($recipeProductId, 'ACTUAL', $materialId, '10.000');
    $check($addLine['success'], 'A recipe-input material can be added to the ACTUAL recipe.');

    $duplicateLine = $productRecipeService->addLine($recipeProductId, 'ACTUAL', $materialId, '5.000');
    $check(!$duplicateLine['success'], 'The same material cannot be added twice to the same recipe type.');

    $toolRejected = $productRecipeService->addLine($recipeProductId, 'ACTUAL', $toolMaterialId, '1.000');
    $check(!$toolRejected['success'], 'A material whose type is not a recipe input is rejected from the recipe.');

    $badQuantity = $productRecipeService->addLine($recipeProductId, 'ACTUAL', $materialId, '0.000');
    $check(!$badQuantity['success'], 'A zero recipe quantity is rejected.');

    $detail = $productRecipeService->detail($recipeProductId);
    $check($detail['actualCost'] === '30.000', 'Actual recipe cost is derived from quantity times current unit cost (10.000 x 3.000).');
    // The submitted 20.000 is ignored: PricingService prices a candle at cost × 2.
    $check($detail['product']['selling_price'] === '60.000' && $detail['pricing']['rule'] === 'CANDLE_COST_X2', 'Adding a recipe line prices the candle at cost × 2 (30.000 × 2).');
    $check($detail['profit'] === '30.000', 'Profit is derived from selling price minus actual cost (60.000 - 30.000).');

    $updateLine = $productRecipeService->updateLine($recipeProductId, 'ACTUAL', $materialId, '2.000');
    $check($updateLine['success'], 'A recipe line quantity can be updated.');
    $detailAfterUpdate = $productRecipeService->detail($recipeProductId);
    $check($detailAfterUpdate['actualCost'] === '6.000', 'Cost recalculates after a recipe quantity changes (2.000 x 3.000).');
    $check($detailAfterUpdate['product']['selling_price'] === '12.000', 'The selling price follows the recipe change in the same save (6.000 × 2).');

    $candleEquivalentLine = $productRecipeService->addLine($recipeProductId, 'CANDLE_EQUIVALENT', $materialId, '4.000');
    $check($candleEquivalentLine['success'], 'A candle-equivalent recipe line can be added independently of the actual recipe.');
    $detailWithEquivalent = $productRecipeService->detail($recipeProductId);
    $check($detailWithEquivalent['candleEquivalentCost'] === '12.000', 'Candle-equivalent cost is tracked separately from the actual cost.');

    $removeLine = $productRecipeService->removeLine($recipeProductId, 'ACTUAL', $materialId);
    $check($removeLine['success'], 'A recipe line can be removed.');
    $detailAfterRemoval = $productRecipeService->detail($recipeProductId);
    $check($detailAfterRemoval['actualCost'] === '0.000', 'Removing the only actual recipe line resets actual cost to zero.');
    // Without a recipe the rules cannot price it: the last price is kept, flagged, and blocks activation.
    $check(
        $detailAfterRemoval['product']['selling_price'] === '12.000' && is_string($detailAfterRemoval['pricing']['issue']),
        'Without a recipe the product keeps its last price and explains what is missing.'
    );

    // === CSRF and authorization ===
    $invalidCsrf = $materialController->processAction([
        'csrf_token' => str_repeat('0', 64), 'action' => 'activate', 'id' => (string) $materialId,
    ], $administratorId);
    $check(!$invalidCsrf['success'], 'Invalid CSRF prevents a material action from being processed.');

    $invalidCsrfRecipe = $productRecipeController->processAction([
        'csrf_token' => str_repeat('0', 64), 'action' => 'add_line', 'product_id' => (string) $recipeProductId,
        'recipe_type' => 'ACTUAL', 'material_id' => (string) $materialId, 'quantity_required' => '1.000',
    ]);
    $check(!$invalidCsrfRecipe['success'], 'Invalid CSRF prevents a recipe action from being processed.');

    $activeAdministrator = $administratorAuthorization->activeAdministrator($administratorId);
    $check(is_array($activeAdministrator), 'An active administrator passes the server-side authorization check.');
    $databaseConnection->prepare("UPDATE administrators_accounts SET account_status = 'SUSPENDED' WHERE id = :id")
        ->execute(['id' => $administratorId]);
    $check(
        $administratorAuthorization->activeAdministrator($administratorId) === null,
        'A suspended administrator is rejected before inventory routes can proceed.'
    );

    // === Injection-safe filters ===
    $unsafeList = $materialService->list(['type' => "1' OR '1'='1", 'sort' => 'stock_quantity; DROP TABLE materials', 'page' => '-1']);
    $check(
        $unsafeList['filters']['type'] === null && $unsafeList['sort'] === 'name' && $unsafeList['page'] === 1,
        'Unsupported type, sort, and page values fall back through allow lists instead of reaching SQL.'
    );
    $unsafeHistory = $materialService->movementHistory(['type' => "ADJUSTMENT_OUT' OR 1=1 --", 'date_from' => '2026-99-99']);
    $check(
        $unsafeHistory['filters']['type'] === null && $unsafeHistory['filters']['date_from'] === null,
        'Unsupported movement-history filters fall back through allow lists instead of reaching SQL.'
    );
} finally {
    if ($productIds !== []) {
        $ids = array_values(array_unique($productIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM product_materials WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM product_translations WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM products WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($materialIds !== []) {
        $ids = array_values(array_unique($materialIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM product_materials WHERE material_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM material_stock_movements WHERE material_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM materials WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($administratorId !== null) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Inventory workflow verification completed without leaving test rows.' . PHP_EOL;
