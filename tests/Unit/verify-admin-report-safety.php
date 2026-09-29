<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Unit verification for Verify Admin Report Safety.
 *
 * DATA FLOW: This executable test isolates a small behavior and fails with a non-zero result when its contract is broken.
 */

// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\AdminReportCsvService;
use App\Services\AdminSettingsService;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

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
$capabilities = $protected->capabilities();
$check(
    $capabilities['storeSettingsWritable'] === false
    && $capabilities['administratorManagementAvailable'] === false,
    'Unsupported settings and administrator mutations remain unavailable.'
);
