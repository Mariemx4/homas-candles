<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../../scripts/migrate.php';

$migrationCount = count(glob(APP_PATH . '/database/migrations/*.sql') ?: []);

// A fresh database must be installable through the checksum-enforcing runner.
[$fresh, $freshName] = create_throwaway_database('freshmigration', 0);
try {
    migration_runner($fresh);
    $count = (int) $fresh->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($count !== $migrationCount) {
        throw new RuntimeException("Fresh install recorded {$count} migrations, expected {$migrationCount}.");
    }
    migration_runner($fresh);
    echo '[PASS] Fresh install and repeat run.' . PHP_EOL;
} finally {
    drop_throwaway_database($fresh, $freshName);
}

// Simulate an installation that ran the comment-only 001-013 files, then
// upgrade through all later migrations without changing its audit checksums.
[$upgrade, $upgradeName] = create_throwaway_database('oldmigration', 13);
try {
    $known = require APP_PATH . '/database/known_comment_only_checksums.php';
    $update = $upgrade->prepare('UPDATE schema_migrations SET file_checksum = ? WHERE version = ?');
    foreach ($known as $version => $hashes) {
        $update->execute([$hashes['comment_only'], (string) $version]);
    }
    migration_runner($upgrade);
    $count = (int) $upgrade->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($count !== $migrationCount) {
        throw new RuntimeException("Upgrade recorded {$count} migrations, expected {$migrationCount}.");
    }
    $recorded = $upgrade->query("SELECT file_checksum FROM schema_migrations WHERE version = '001'")->fetchColumn();
    if ($recorded !== $known['001']['comment_only']) {
        throw new RuntimeException('The existing migration checksum was rewritten.');
    }
    $update->execute([str_repeat('0', 64), '001']);
    try {
        migration_runner($upgrade);
        throw new RuntimeException('Unknown migration checksum was accepted.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Applied migration was modified')) {
            throw $exception;
        }
    }
    echo '[PASS] Existing database upgrade, preserved history, unknown checksum rejected.' . PHP_EOL;
} finally {
    drop_throwaway_database($upgrade, $upgradeName);
}
