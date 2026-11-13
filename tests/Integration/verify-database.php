<?php

declare(strict_types=1);



// WHAT: a battery of checks against the live database — not just "does it
//       connect" (check-database.php) but "is it in exactly the state we
//       expect": right tables, right migrations recorded, right
//       constraints present, right reference data, no leftover demo data.
// WHY:  the single script to run before trusting a database as production-
//       ready, or after any migration/seed change, to catch drift early.
require_once __DIR__ . '/../../app/config/database.php';

function assert_database(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = database();

// WHAT: exactly these application tables should exist, no more, no fewer.
$expectedTables = [
    'administrators_accounts',
    'categories',
    'category_translations',
    'colors',
    'color_translations',
    'customer_addresses',
    'customer_password_reset_codes',
    'customers_accounts',
    'custom_product_requests',
    'languages',
    'login_throttle',
    'materials',
    'material_stock_movements',
    'material_types',
    'material_type_translations',
    'orders',
    'order_items',
    'order_item_materials',
    'order_status_history',
    'payments',
    'products',
    'product_colors',
    'product_materials',
    'product_scents',
    'product_stock_movements',
    'product_translation_reviews',
    'product_translations',
    'product_types',
    'product_type_translations',
    'request_contact_attempts',
    'schema_migrations',
    'scents',
    'scent_translations',
    'set_components',
];
sort($expectedTables, SORT_STRING);

$actualTables = $connection->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);
sort($actualTables, SORT_STRING);

assert_database(
    $actualTables === $expectedTables,
    'The database table set does not match the expected ' . count($expectedTables) . ' tables. Missing: '
    . implode(', ', array_diff($expectedTables, $actualTables))
    . '. Unexpected: '
    . implode(', ', array_diff($actualTables, $expectedTables))
);
echo '[PASS] Exact ' . count($expectedTables) . '-table schema.' . PHP_EOL;

// WHAT: every migration file on disk must have a matching, checksum-correct
//       row in schema_migrations — same tamper check migrate.php does, run
//       independently here as a verification step.
$migrationFiles = glob(__DIR__ . '/../../app/database/migrations/*.sql');
assert_database($migrationFiles !== false, 'Could not read migration files.');
sort($migrationFiles, SORT_STRING);

$appliedRows = $connection->query(
    'SELECT version, migration_name, file_checksum FROM schema_migrations ORDER BY version'
)->fetchAll();
$commentOnlyChecksums = require __DIR__ . '/../../app/database/known_comment_only_checksums.php';
assert_database(count($appliedRows) === count($migrationFiles), 'Migration record count is incorrect.');

foreach ($migrationFiles as $index => $migrationFile) {
    $filename = basename($migrationFile);
    assert_database(
        preg_match('/^(\d{3,})_[a-z0-9_]+\.sql$/', $filename, $matches) === 1,
        "Invalid migration filename: {$filename}"
    );
    $checksum = hash_file('sha256', $migrationFile);
    $row = $appliedRows[$index];
    assert_database(
        $row['version'] === $matches[1]
        && $row['migration_name'] === $filename
        && is_string($checksum)
        && (hash_equals($row['file_checksum'], $checksum)
            || (isset($commentOnlyChecksums[$matches[1]])
                && hash_equals($checksum, $commentOnlyChecksums[$matches[1]]['canonical'])
                && hash_equals($row['file_checksum'], $commentOnlyChecksums[$matches[1]]['comment_only']))),
        "Migration tracking mismatch: {$filename}"
    );
}
echo '[PASS] All migration versions and SHA-256 checksums.' . PHP_EOL;

// WHAT: every CONSTRAINT named in the migration files must actually exist
//       in the live database's information_schema.
// WHY:  catches a constraint that was silently dropped/renamed by a manual
//       change outside of a migration file.
$declaredConstraints = [];
foreach ($migrationFiles as $migrationFile) {
    $sql = file_get_contents($migrationFile);
    assert_database(is_string($sql), 'Could not inspect migration constraints.');
    preg_match_all('/\bCONSTRAINT\s+([a-z0-9_]+)/i', $sql, $matches);
    array_push($declaredConstraints, ...$matches[1]);
}
$declaredConstraints = array_values(array_unique($declaredConstraints));
sort($declaredConstraints, SORT_STRING);

$actualNamedConstraints = $connection->query(
    "SELECT constraint_name FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_type IN ('FOREIGN KEY', 'CHECK')
     ORDER BY constraint_name"
)->fetchAll(PDO::FETCH_COLUMN);
$actualNamedConstraints = array_values(array_unique($actualNamedConstraints));
sort($actualNamedConstraints, SORT_STRING);

$retiredConstraints = ['chk_orders_delivery_fee'];
$missingConstraints = array_diff(
    $declaredConstraints,
    $actualNamedConstraints,
    $retiredConstraints
);
assert_database(
    $missingConstraints === [],
    'Missing declared constraints: ' . implode(', ', $missingConstraints)
);
$activeDeclaredConstraintCount = count(array_diff($declaredConstraints, $retiredConstraints));
echo '[PASS] All ' . $activeDeclaredConstraintCount . ' active declared foreign-key/check constraints exist.' . PHP_EOL;

// Every order must receive an explicit, non-negative manually entered fee.
$deliveryFeeClause = $connection->query(
    "SELECT check_clause FROM information_schema.check_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name = 'chk_orders_delivery_fee_nonnegative'"
)->fetchColumn();
$normalizedDeliveryClause = preg_replace('/\s+/', ' ', strtolower(str_replace('`', '', (string) $deliveryFeeClause)));
assert_database(
    is_string($normalizedDeliveryClause)
    && str_contains($normalizedDeliveryClause, 'delivery_fee >= 0'),
    'The explicit delivery-fee constraint is incorrect: ' . (string) $normalizedDeliveryClause
);
echo '[PASS] Delivery fee is explicitly supplied and may not be negative.' . PHP_EOL;

// WHAT: spot-check a couple of specific column definitions that matter for
//       correctness (password hash column width, default purchase mode).
$columnStatement = $connection->prepare(
    "SELECT column_type, column_default FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name"
);
foreach (['administrators_accounts', 'customers_accounts'] as $accountTable) {
    $columnStatement->execute(['table_name' => $accountTable, 'column_name' => 'password_hash']);
    $column = $columnStatement->fetch();
    assert_database($column !== false && strtolower($column['column_type']) === 'varchar(255)', "{$accountTable}.password_hash is not VARCHAR(255).");
}
$columnStatement->execute(['table_name' => 'products', 'column_name' => 'purchase_mode']);
$purchaseModeColumn = $columnStatement->fetch();
assert_database(
    $purchaseModeColumn !== false
    && trim((string) $purchaseModeColumn['column_default'], "'\"") === 'CART',
    'Product purchase mode default is not CART.'
);
$columnStatement->execute(['table_name' => 'orders', 'column_name' => 'delivery_fee']);
$deliveryFeeColumn = $columnStatement->fetch();
assert_database(
    $deliveryFeeColumn !== false && $deliveryFeeColumn['column_default'] === null,
    'Orders.delivery_fee must not have a default value.'
);
echo '[PASS] Correct account password capacity, CART product default, and no delivery-fee default.' . PHP_EOL;

// WHAT: the production-safe reference data (seed.sql) has exactly the
//       expected row counts.
$expectedReferenceCounts = [
    'languages' => 3,
    'categories' => 6,
    'category_translations' => 18,
    'product_types' => 7,
    'product_type_translations' => 21,
    'material_types' => 8,
    'material_type_translations' => 24,
];
foreach ($expectedReferenceCounts as $table => $expectedCount) {
    $count = (int) $connection->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    assert_database($count === $expectedCount, "Unexpected reference row count in {$table}.");
}
echo '[PASS] Reference seed counts.' . PHP_EOL;

// WHAT: none of demo_seed.sql's fake products/materials/etc. should be present.
// WHY:  this script is meant to also validate a database intended for
//       production — demo data leaking in there would mean fake products
//       show up to real customers.
$zeroDemoTables = [
    'products', 'product_translations', 'product_colors', 'product_scents',
    'product_materials', 'set_components', 'materials', 'scents', 'colors',
];
foreach ($zeroDemoTables as $table) {
    $count = (int) $connection->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    assert_database($count === 0, "Demo data remains in {$table}.");
}
echo '[PASS] Zero demo products and zero demo catalogue inventory.' . PHP_EOL;

// WHAT: ask MySQL itself to verify every table's storage integrity.
$quotedTables = implode(', ', array_map(static fn (string $table): string => "`{$table}`", $expectedTables));
$checkRows = $connection->query('CHECK TABLE ' . $quotedTables)->fetchAll();
foreach ($checkRows as $checkRow) {
    assert_database(
        strtolower((string) $checkRow['Msg_type']) === 'status'
        && strtolower((string) $checkRow['Msg_text']) === 'ok',
        'Storage check failed for ' . $checkRow['Table'] . ': ' . $checkRow['Msg_text']
    );
}
echo '[PASS] CHECK TABLE returned OK for all ' . count($expectedTables) . ' tables.' . PHP_EOL;

echo 'Database verification completed successfully.' . PHP_EOL;
