<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Migrations.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: runs every migration file from scratch against a throwaway,
//       randomly-named database, then drops it.
// WHY:  proves the *.sql files themselves are valid and apply cleanly in
//       order — without touching your real homas_candles database. This is
//       the script that would have caught the invalid-syntax bugs in
//       migrations 002/003 before they ever reached scripts/migrate.php.
$config = require __DIR__ . '/../../app/config/database.local.php';
$host = $config['host'] ?? '127.0.0.1';
$port = (int) ($config['port'] ?? 3306);
$username = $config['username'] ?? $config['user_name'] ?? '';
$password = $config['password'] ?? $config['psswd'] ?? '';
$databaseName = 'homas_candles_migration_check_' . bin2hex(random_bytes(4));

// WHAT: double-check the generated name is exactly the safe shape expected.
// WHY:  $databaseName is interpolated directly into SQL below (identifiers
//       can't be bound as PDO parameters) — this guard is what makes that
//       safe despite not being a prepared statement.
if (!preg_match('/^homas_candles_migration_check_[a-f0-9]{8}$/', $databaseName)) {
    throw new RuntimeException('Unsafe temporary database name.');
}

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    $connection->exec(
        "CREATE DATABASE `{$databaseName}` "
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $connection->exec("USE `{$databaseName}`");

    $bootstrap = file_get_contents(__DIR__ . '/../../app/database/schema_migrations.sql');
    if ($bootstrap === false || trim($bootstrap) === '') {
        throw new RuntimeException('Migration bootstrap is unreadable.');
    }
    $connection->exec($bootstrap);

    $migrationFiles = glob(__DIR__ . '/../../app/database/migrations/*.sql');
    if ($migrationFiles === false) {
        throw new RuntimeException('Migration directory is unreadable.');
    }
    sort($migrationFiles, SORT_STRING);

    foreach ($migrationFiles as $migrationFile) {
        $sql = file_get_contents($migrationFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Unreadable migration: ' . basename($migrationFile));
        }

        $connection->exec($sql);
        echo '[VALID] ' . basename($migrationFile) . PHP_EOL;
    }

    // WHAT: sanity-check the final table count matches expectations.
    // WHY:  catches a migration that silently created the wrong number of
    //       tables (e.g. a typo'd CREATE TABLE that never ran).
    $tableCount = (int) $connection->query(
        'SELECT COUNT(*) FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
    )->fetchColumn();

    // 30 tables up to migration 016, plus order_item_materials (017) and
    // product_stock_movements (018).
    if ($tableCount !== 32) {
        throw new RuntimeException("Expected 32 tables, found {$tableCount}.");
    }

    echo '[VALID] 32 tables created.' . PHP_EOL;
} finally {
    // WHAT: always clean up the throwaway database, success or failure.
    $connection->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
}
