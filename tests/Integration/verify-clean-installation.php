<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-database.php';
require_once SCRIPTS_PATH . '/migrate.php';
require_once __DIR__ . '/../Support/TestCheck.php';
[$db, $name] = create_throwaway_database('install', 0);
$checks = 0;
$check = TestCheck::printing($checks);
try {
    $db->exec('DROP TABLE schema_migrations; DROP COLLATION homas_unicode_ci');
    migration_runner($db);
    $check((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === count(glob(APP_PATH . '/database/migrations/*.sql')), 'A completely empty PostgreSQL database receives its ICU collation and every current migration.');
    ob_start();
    migration_runner($db);
    ob_end_clean();
    $check((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === count(glob(APP_PATH . '/database/migrations/*.sql')), 'Running migrations again preserves the applied versions and checksums.');
    $seed = (string)file_get_contents(APP_PATH . '/database/reference.sql');
    $db->exec($seed);
    $db->exec($seed);
    $check((int)$db->query('SELECT COUNT(*) FROM languages')->fetchColumn() === 3 && (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 6, 'The repository reference data seeds languages and catalogue sections idempotently.');
    $check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM materials')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM administrators_accounts')->fetchColumn() === 0, 'Reference setup creates no fake inventory, products or staff accounts.');
    $check((int)$db->query("SELECT COUNT(*) FROM category_translations WHERE language_code='ar' AND name<>''")->fetchColumn() === 6, 'A clean installation retains the Arabic reference names as valid Unicode.');
    echo 'Completed ' . $checks . ' clean-installation assertions.' . PHP_EOL;
} finally {
    drop_throwaway_database($db, $name);
}
