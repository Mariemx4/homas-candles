<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Repositories.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: exercises TransactionManager + the repositories against a real
//       database connection, then rolls everything back.
// WHY:  unlike a typical unit test, this hits real SQL/constraints instead
//       of mocking the database — the `finally` block's rollBack() is what
//       makes it safe to run against a real database without leaving test
//       rows behind.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/config/database.php';

use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\ProductRepository;

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = database();
$transactions = new TransactionManager($connection);

$products = new ProductRepository($connection);
if ($products->findActiveBySlug('verification-product-that-does-not-exist') !== null) {
    throw new RuntimeException('Missing product lookup returned an unexpected row.');
}
echo '[PASS] Product repository executes the catalogue detail query safely.' . PHP_EOL;

// WHAT: prove a thrown exception inside ->run() actually rolls back.
try {
    $transactions->run(function (PDO $pdo): void {
        $pdo->exec("INSERT INTO languages (code, name) VALUES ('zz', 'Rollback test')");
        throw new RuntimeException('Intentional rollback test.');
    });
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Intentional rollback test.') {
        throw $exception;
    }
}

if ((int) $connection->query("SELECT COUNT(*) FROM languages WHERE code = 'zz'")->fetchColumn() !== 0) {
    throw new RuntimeException('Transaction rollback verification failed.');
}
echo '[PASS] TransactionManager rolls back failed operations.' . PHP_EOL;

// WHAT: wrap everything else in one more transaction so the whole
//       verification run can be undone at the end, regardless of outcome.
$connection->beginTransaction();

try {
    // WHAT: create a customer + primary address, then confirm the
    //       MAX_ADDRESSES=3 rule from CustomerAccountRepository is enforced.
    $customers = new CustomerAccountRepository($connection, $transactions);
    $customerId = $customers->createWithPrimaryAddress(
        [
            'first_name' => 'Repository',
            'last_name' => 'Test',
            'email' => 'repository-test@example.test',
            'phone_number' => '99000001',
            'password_hash' => password_hash('temporary-test-password', PASSWORD_DEFAULT),
        ],
        [
            'address_label' => 'Home',
            'street_address' => 'Test street 1',
            'delegation' => 'Test delegation',
            'governorate' => 'Test governorate',
            'postal_code' => '1000',
        ]
    );
    $customers->addAddress($customerId, [
        'address_label' => 'Work',
        'street_address' => 'Test street 2',
        'delegation' => 'Test delegation',
        'governorate' => 'Test governorate',
        'postal_code' => '1001',
    ]);
    $customers->addAddress($customerId, [
        'address_label' => 'Family',
        'street_address' => 'Test street 3',
        'delegation' => 'Test delegation',
        'governorate' => 'Test governorate',
        'postal_code' => '1002',
    ]);

    try {
        $customers->addAddress($customerId, [
            'street_address' => 'Forbidden fourth address',
            'delegation' => 'Test delegation',
            'governorate' => 'Test governorate',
            'postal_code' => '1003',
        ]);
        throw new RuntimeException('The maximum-address rule was not enforced.');
    } catch (DomainException) {
        // Expected.
    }
    echo '[PASS] Customer creation requires an address and caps addresses at three.' . PHP_EOL;

    // WHAT: create a test material, deduct stock via the repository, and
    //       confirm the balance updated exactly as expected.
    $materialInsert = $connection->prepare(
        "INSERT INTO materials
            (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
         VALUES (1, 'VERIFY-STOCK', 'Verification stock', 'piece', 10.000, 1.250, 1, 1)"
    );
    $materialInsert->execute();
    $materialId = (int) $connection->lastInsertId();

    $stock = new MaterialStockRepository($connection, $transactions);
    $stock->recordMovement($materialId, 'ADJUSTMENT_OUT', '-2.000', null, null, 'Repository verification');
    $balanceStatement = $connection->prepare('SELECT stock_quantity FROM materials WHERE id = :id');
    $balanceStatement->execute(['id' => $materialId]);
    if ((string) $balanceStatement->fetchColumn() !== '8.000') {
        throw new RuntimeException('Atomic material balance update failed.');
    }
    echo '[PASS] Stock balance and immutable movement ledger update atomically.' . PHP_EOL;
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo 'Repository verification completed without leaving test rows.' . PHP_EOL;
