<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Command-line maintenance tool for Rebuild Development Database.
 *
 * DATA FLOW: An operator runs this file from the project root to perform an explicit development or maintenance task outside an HTTP request.
 */

// WHAT: DROPs and recreates the entire homas_candles database, empty.
// WHY:  destructive on purpose (start-fresh during schema development) —
//       every check below exists to make it hard to run this by accident
//       or against the wrong thing. Run with:
//       php scripts/rebuild-development-database.php --confirm=homas_candles --backup=<path-to-.sql>
// SETUP STEP: BACKUP_PATH comes from the same path definition backup-database.php uses,
// so the two scripts can never disagree about where backups live.
require_once __DIR__ . '/../app/config/paths.php';
$options = getopt('', ['confirm:', 'backup:']);
$config = require APP_PATH . '/config/database.local.php';
$databaseName = $config['database'] ?? $config['db_name'] ?? null;
$host = $config['host'] ?? '127.0.0.1';
$port = (int) ($config['port'] ?? 3306);
$username = $config['username'] ?? $config['user_name'] ?? null;
$password = $config['password'] ?? $config['psswd'] ?? null;

// WHAT: refuse unless the caller typed the exact database name back.
// WHY:  a plain --confirm (no value check) could be automated/scripted by
//       mistake; typing the name is a deliberate, hard-to-fat-finger step.
if ($databaseName !== 'homas_candles' || ($options['confirm'] ?? null) !== $databaseName) {
    throw new RuntimeException('Refusing to rebuild without the exact database confirmation.');
}

// WHAT: require a real, checksummed backup file before dropping anything.
// WHY:  --backup must point at a file that (a) actually lives in
//       app/storage/backups (dirname() check — no path traversal), (b) ends
//       in .sql, (c) isn't suspiciously tiny, and (d) has a matching
//       backup-database.php checksum file next to it. Any one of those
//       failing means "no proof this backup is real and intact" and this
//       script stops.
$backupPath = realpath((string) ($options['backup'] ?? ''));
$backupDirectory = realpath(BACKUP_PATH);
if ($backupPath === false || $backupDirectory === false
    || dirname($backupPath) !== $backupDirectory
    || pathinfo($backupPath, PATHINFO_EXTENSION) !== 'sql'
    || filesize($backupPath) < 1024
) {
    throw new RuntimeException('Refusing to rebuild without a valid project database backup.');
}

$checksumFile = $backupPath . '.sha256';
$checksumRecord = is_file($checksumFile) ? file_get_contents($checksumFile) : false;
$actualChecksum = hash_file('sha256', $backupPath);
if ($checksumRecord === false || $actualChecksum === false
    || !str_starts_with($checksumRecord, $actualChecksum . '  ')
) {
    throw new RuntimeException('The database backup checksum is missing or invalid.');
}

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    (string) $username,
    (string) $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$connection->exec('DROP DATABASE `homas_candles`');
$connection->exec(
    'CREATE DATABASE `homas_candles` '
    . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);

echo 'Rebuilt empty development database: homas_candles' . PHP_EOL;
