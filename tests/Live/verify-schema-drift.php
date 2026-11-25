<?php

declare(strict_types=1);

// Read-only health check of the REAL shop database (the PostgreSQL successor
// of the MySQL-era verify-database.php).
//
// It builds a throwaway database from the migration files, then compares the
// shop's schema with it: tables, columns, constraints, indexes, triggers,
// views and functions. Any difference means someone changed the database
// outside a migration ("drift"). It also checks that every migration is
// recorded with the checksum of its file, and that the reference languages exist.
//
// The shop connection is switched to read-only before the first query, so
// this check cannot change shop data. Run it in the application container:
//   docker exec homas-test php tests/Live/verify-schema-drift.php

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

$check = TestCheck::printing();

$shop = database();
$shop->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
$check(
    $shop->query('SHOW transaction_read_only')->fetchColumn() === 'on',
    'The shop connection is read-only for this check.'
);

/**
 * A comparable description of one database's schema.
 *
 * @return array<string, list<string>> category => sorted rows
 */
$describe = static function (PDO $connection): array {
    $schema = (string) $connection->query('SELECT current_schema()')->fetchColumn();
    $rows = static function (string $sql) use ($connection, $schema): array {
        $statement = $connection->prepare($sql);
        $statement->execute(['schema' => $schema]);
        $list = array_map(
            static fn (array $row): string => implode(' | ', array_map(static fn ($v): string => (string) $v, $row)),
            $statement->fetchAll(PDO::FETCH_NUM)
        );
        sort($list, SORT_STRING);
        return $list;
    };

    return [
        'tables' => $rows("SELECT tablename FROM pg_tables WHERE schemaname = :schema"),
        'columns' => $rows(
            "SELECT table_name, column_name, data_type, is_nullable, coalesce(column_default, '')
             FROM information_schema.columns WHERE table_schema = :schema"
        ),
        'constraints' => $rows(
            "SELECT conrelid::regclass::text, conname, pg_get_constraintdef(oid)
             FROM pg_constraint WHERE connamespace = (SELECT oid FROM pg_namespace WHERE nspname = :schema)"
        ),
        'indexes' => $rows("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = :schema"),
        'triggers' => $rows(
            "SELECT event_object_table, trigger_name, action_timing, event_manipulation, action_statement
             FROM information_schema.triggers WHERE trigger_schema = :schema"
        ),
        'views' => $rows("SELECT viewname, md5(definition) FROM pg_views WHERE schemaname = :schema"),
        'functions' => $rows(
            "SELECT proname, md5(prosrc) FROM pg_proc
             WHERE pronamespace = (SELECT oid FROM pg_namespace WHERE nspname = :schema)"
        ),
    ];
};

// 1. Every migration file is recorded with the checksum the runner accepts.
$migrationFiles = glob(APP_PATH . '/database/migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);
$recorded = $shop->query('SELECT version, file_checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
$mismatched = [];
foreach ($migrationFiles as $file) {
    $version = substr(basename($file), 0, 3);
    $checksum = (string) hash_file('sha256', $file);
    $accepted = isset($recorded[$version]) && hash_equals($recorded[$version], $checksum);
    if (!$accepted) {
        $mismatched[] = basename($file);
    }
}
$check(
    $mismatched === [] && count($recorded) === count($migrationFiles),
    'All ' . count($migrationFiles) . ' migrations are recorded with their file checksum'
        . ($mismatched === [] ? '.' : ' (mismatch: ' . implode(', ', $mismatched) . ').')
);

// 2. The shop's schema equals a fresh install from the migration files.
[$reference, $referenceName] = create_throwaway_database('schemadrift');
try {
    $expected = $describe($reference);
    $actual = $describe($shop);
    foreach ($expected as $category => $rows) {
        $missing = array_values(array_diff($rows, $actual[$category]));
        $extra = array_values(array_diff($actual[$category], $rows));
        $detail = '';
        if ($missing !== [] || $extra !== []) {
            $detail = "\n  missing in shop: " . implode("\n    ", array_slice($missing, 0, 10))
                . "\n  only in shop: " . implode("\n    ", array_slice($extra, 0, 10));
        }
        $check($missing === [] && $extra === [], 'Shop ' . $category . ' match the migrations (' . count($rows) . ').' . $detail);
    }

    // 3. Reference data that the code relies on.
    $languages = static fn (PDO $c): array => $c->query('SELECT code FROM languages ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
    $check($languages($shop) === $languages($reference), 'The shop has exactly the reference languages (' . implode(', ', $languages($reference)) . ').');
} finally {
    drop_throwaway_database($reference, $referenceName);
}

echo "Live schema check passed: no drift from the migrations.\n";
