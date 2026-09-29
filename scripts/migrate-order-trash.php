<?php
declare(strict_types=1);

// Targeted, additive migration for installations whose older SQL files were
// edited after application. Never changes or accepts those older checksums.
require __DIR__ . '/../app/config/database.php';
$connection = database();
$file = __DIR__ . '/../app/database/migrations/014_add_order_trash.sql';
$checksum = hash_file('sha256', $file);
$statement = $connection->prepare('SELECT file_checksum FROM schema_migrations WHERE version = :version');
$statement->execute(['version' => '014']);
$applied = $statement->fetchColumn();
if ($applied !== false) {
    if (!hash_equals($applied, $checksum)) {
        throw new RuntimeException('Order Trash migration checksum mismatch.');
    }
    exit("Order Trash migration is already applied.\n");
}
$connection->exec(file_get_contents($file));
$statement = $connection->prepare('INSERT INTO schema_migrations (version, migration_name, file_checksum) VALUES (:version, :name, :checksum)');
$statement->execute(['version' => '014', 'name' => basename($file), 'checksum' => $checksum]);
echo "Applied 014_add_order_trash.sql; existing migration checksums were preserved.\n";
