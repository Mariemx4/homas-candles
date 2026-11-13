<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Maintenance\PostgresBackup;

[$db, $name] = create_throwaway_database('backup');
$checks = 0;
$directory = sys_get_temp_dir() . '/homas-backup-check-' . bin2hex(random_bytes(8));
$check = TestCheck::printing($checks);
try {
    $db->exec("INSERT INTO customers_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Backup','Crème شمعة','restore@example.test','98117731','sensitive-fixture-hash')");
    $config = require APP_PATH . '/config/database.local.php';
    $config['database'] = $name;
    $service = new PostgresBackup($config, $directory);
    $archive = $service->create();
    $check(is_file($archive) && str_starts_with((string)file_get_contents($archive), 'PGDMP'), 'The real PostgreSQL client produces a custom-format archive of the disposable database.');
    $metadata = json_decode((string)file_get_contents($archive . '.json'), true, 512, JSON_THROW_ON_ERROR);
    $check(!str_contains((string)file_get_contents($archive . '.json'), 'sensitive-fixture-hash') && !str_contains((string)file_get_contents($archive . '.json'), 'restore@example.test'), 'Verification metadata contains row digests, without copied account values or credentials.');
    $receipt = $service->checkRestore($archive);
    $check($receipt['tables_verified'] === count($metadata['tables']) && $receipt['tables_verified'] > 30, 'A separate PostgreSQL database restores every application table and matches all exported row hashes, including Unicode.');
    $check(PostgresBackup::latestVerified($directory)['sha256'] === $receipt['sha256'], 'Only the archive with a matching successful restore receipt is advertised as verified.');
    $check((int)$db->query("SELECT COUNT(*) FROM pg_database WHERE datname COLLATE \"C\" ~ '^homas_candles_restore_check_[a-f0-9]{16}$'")->fetchColumn() === 0, 'The generated restore database is removed after successful verification.');
    $original = file_get_contents($archive);
    file_put_contents($archive, $original . 'tampered');
    try {
        $service->checkRestore($archive);
        $rejected = false;
    } catch (RuntimeException) {
        $rejected = true;
    }
    $check($rejected && PostgresBackup::latestVerified($directory) === null, 'A changed archive fails checksum validation and immediately loses its verified status.');
    file_put_contents($archive, $original);
    try {
        $service->checkRestore(APP_PATH . '/config/database.local.php');
        $rejected = false;
    } catch (RuntimeException) {
        $rejected = true;
    }
    $check($rejected, 'Verification refuses files outside private archive storage.');
    $check($service->fingerprint($db) === $metadata['tables'], 'Backing up and checking a restore leaves the original disposable database unchanged.');
    echo 'Completed ' . $checks . ' PostgreSQL backup/restore assertions.' . PHP_EOL;
} finally {
    drop_throwaway_database($db, $name);
    if (preg_match('~^' . preg_quote(sys_get_temp_dir(), '~') . '/homas-backup-check-[a-f0-9]{16}$~D', $directory)) {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
