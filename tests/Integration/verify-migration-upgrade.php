<?php

declare(strict_types=1);

// Checks the migration runner (scripts/migrate.php) against throwaway
// PostgreSQL databases: a fresh install, upgrades from older PostgreSQL
// installations, and refusal of an applied migration whose file changed.
// Never touches the shop database.

require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../../scripts/migrate.php';

$check = TestCheck::printing();
$migrationFiles = glob(APP_PATH . '/database/migrations/*.sql') ?: [];
$migrationCount = count($migrationFiles);

/** Runs the real runner while hiding its per-file progress lines. */
$runMigrations = static function (PDO $connection): void {
    ob_start();
    try {
        migration_runner($connection);
    } finally {
        ob_end_clean();
    }
};
$recorded = static fn (PDO $connection): array => $connection
    ->query('SELECT version, file_checksum FROM schema_migrations ORDER BY version')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

// 1. A fresh database is installable through the checksum-enforcing runner,
//    and running it again changes nothing.
[$fresh, $freshName] = create_throwaway_database('freshmigration', 0);
try {
    $runMigrations($fresh);
    $first = $recorded($fresh);
    $check(count($first) === $migrationCount, "A fresh install records all {$migrationCount} migrations.");
    $runMigrations($fresh);
    $check($recorded($fresh) === $first, 'Running the migrations again applies nothing and changes no checksum.');
    $fileChecksums = [];
    foreach ($migrationFiles as $file) {
        $fileChecksums[substr(basename($file), 0, 3)] = hash_file('sha256', $file);
    }
    ksort($fileChecksums);
    $check($first === $fileChecksums, 'Each recorded checksum is the SHA-256 of the migration file bytes.');
} finally {
    drop_throwaway_database($fresh, $freshName);
}

// 2. An existing PostgreSQL installation that stopped at an older migration
//    upgrades through every later one without rewriting its history.
//    023 is the last migration recorded with CRLF line endings on the live
//    database (see .gitattributes), so both sides of that boundary are covered.
foreach ([13, 23] as $through) {
    [$upgrade, $upgradeName] = create_throwaway_database('oldmigration', $through);
    try {
        $before = $recorded($upgrade);
        $check(count($before) === $through, "The simulated installation has applied migrations 001-" . sprintf('%03d', $through) . '.');
        $runMigrations($upgrade);
        $after = $recorded($upgrade);
        $check(count($after) === $migrationCount, "Upgrading from " . sprintf('%03d', $through) . " applies the remaining migrations.");
        $check(array_intersect_key($after, $before) === $before, 'The upgrade keeps every earlier recorded checksum unchanged.');
    } finally {
        drop_throwaway_database($upgrade, $upgradeName);
    }
}

// Normalize the former storefront slug in existing data, without reseeding IDs.
[$categories, $categoryDatabase] = create_throwaway_database('categorymigration', 32);
try {
    $categories->exec("INSERT INTO languages (code, name) VALUES ('en', 'English')");
    $categories->exec("INSERT INTO categories (id, slug, is_active) VALUES (51, 'candle-bouquets', 1)");
    $categories->exec("INSERT INTO category_translations (category_id, language_code, name) VALUES (51, 'en', 'Candle bouquets')");
    $categories->exec("INSERT INTO products (category_id, slug) VALUES (51, 'migration-bouquet')");
    $before = $recorded($categories);
    $runMigrations($categories);
    $check(
        $categories->query("SELECT slug FROM categories WHERE id = 51")->fetchColumn() === 'bouquets'
            && (int) $categories->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 1,
        'The stored bouquet slug is normalized without creating or deleting categories.'
    );
    $check(
        (int) $categories->query("SELECT category_id FROM products WHERE slug = 'migration-bouquet'")->fetchColumn() === 51
            && $categories->query("SELECT name FROM category_translations WHERE category_id = 51 AND language_code = 'en'")->fetchColumn() === 'Candle bouquets',
        'Category normalization preserves product relationships and translated names.'
    );
    $check(array_intersect_key($recorded($categories), $before) === $before, 'Category normalization preserves earlier migration checksums.');
} finally {
    drop_throwaway_database($categories, $categoryDatabase);
}

// 3. An applied migration whose recorded checksum no longer matches its file
//    (someone edited an applied migration) stops the runner.
[$tampered, $tamperedName] = create_throwaway_database('tamperedmigration', 13);
try {
    $tampered->prepare('UPDATE schema_migrations SET file_checksum = ? WHERE version = ?')
        ->execute([str_repeat('0', 64), '001']);
    $refused = false;
    try {
        $runMigrations($tampered);
    } catch (RuntimeException $exception) {
        $refused = str_contains($exception->getMessage(), 'Applied migration was modified: 001_');
    }
    $check($refused, 'A changed applied migration is refused, naming the file.');
    $check(count($recorded($tampered)) === 13, 'Nothing after the refused migration was applied.');
} finally {
    drop_throwaway_database($tampered, $tamperedName);
}

echo "Migration runner checks passed.\n";
