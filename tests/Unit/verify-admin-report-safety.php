<?php

declare(strict_types=1);



require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Services\AdminReportCsvService;
use App\Services\AdminSettingsService;

$check = TestCheck::printing();

$csv = new AdminReportCsvService();
$check($csv->safeCell('=2+2') === "'=2+2", 'CSV formulas beginning with equals are neutralized.');
$check($csv->safeCell(" \t+SUM(A1:A2)") === "' \t+SUM(A1:A2)", 'CSV formulas hidden behind whitespace are neutralized.');
$check($csv->safeCell('ORD-100') === 'ORD-100', 'Ordinary report values are not altered.');

$stream = fopen('php://temp', 'w+b');
if ($stream === false) {
    throw new RuntimeException('Could not open the in-memory CSV stream.');
}
$csv->write($stream, ['name' => 'Name', 'amount' => 'Amount TND'], [
    ['name' => '@malicious', 'amount' => '12.000'],
]);
rewind($stream);
$contents = stream_get_contents($stream);
fclose($stream);
$check(is_string($contents) && str_starts_with($contents, "\xEF\xBB\xBF"), 'CSV output declares UTF-8 with a byte-order mark.');
$check(is_string($contents) && str_contains($contents, "'@malicious"), 'CSV writing applies formula protection to exported rows.');

// The same constants app/Bootstrap/admin/admin-settings.php uses (app/config/paths.php).
$protected = new AdminSettingsService(
    PUBLIC_PATH,
    BACKUP_PATH,
    SCRIPTS_PATH . '/backup-database.php'
);
$public = new AdminSettingsService(
    PUBLIC_PATH,
    PUBLIC_PATH . '/assets',
    SCRIPTS_PATH . '/backup-database.php'
);
$check($protected->backupStorageProtected(), 'The existing backup directory resolves outside the public web root.');
$check(!$public->backupStorageProtected(), 'A backup directory under the public web root is rejected.');
$scratch = sys_get_temp_dir() . '/homas-backup-capabilities-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$capabilities = (new AdminSettingsService(PUBLIC_PATH, $scratch, SCRIPTS_PATH . '/backup-database.php'))->capabilities();
rmdir($scratch);
$check(
    $capabilities['storeSettingsWritable'] === false
    && $capabilities['administratorManagementAvailable'] === true
    && $capabilities['backupMechanismAvailable'] === false,
    'Settings distinguish implemented team management from unavailable settings writes and unverified backups.'
);
