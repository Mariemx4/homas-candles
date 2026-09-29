<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Command-line maintenance tool for Backup Database.
 *
 * DATA FLOW: An operator runs this file from the project root to perform an explicit development or maintenance task outside an HTTP request.
 */

// WHAT: dumps the live homas_candles database to app/storage/backups/ via
//       mysqldump, and writes a .sha256 checksum file next to it.
// WHY:  that checksum file is what rebuild-development-database.php checks
//       before it's willing to DROP the real database — this script and
//       that one are a matched pair.
// SETUP STEP: BACKUP_PATH and PROJECT_ROOT come from the one shared path definition.
require_once __DIR__ . '/../app/config/paths.php';
$config = require APP_PATH . '/config/database.local.php';
$host = $config['host'] ?? '127.0.0.1';
$port = (int) ($config['port'] ?? 3306);
$databaseName = $config['database'] ?? $config['db_name'] ?? null;
$username = $config['username'] ?? $config['user_name'] ?? null;
$password = $config['password'] ?? $config['psswd'] ?? null;

// WHAT: only ever back up the one expected database, with real credentials.
if (!is_string($databaseName) || $databaseName !== 'homas_candles'
    || !is_string($username) || !is_string($password)
) {
    throw new RuntimeException('Refusing to back up an unexpected database configuration.');
}

$dumpExecutable = 'C:/xampp/mysql/bin/mysqldump.exe';
$backupDirectory = BACKUP_PATH;

if (!is_file($dumpExecutable)) {
    throw new RuntimeException('mysqldump was not found.');
}

if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0770, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Could not create the backup directory.');
}

$backupDirectory = realpath($backupDirectory);
if ($backupDirectory === false) {
    throw new RuntimeException('Could not resolve the backup directory.');
}

$timestamp = date('Ymd_His');
$backupPath = $backupDirectory . DIRECTORY_SEPARATOR . "{$databaseName}_{$timestamp}.sql";
$checksumPath = $backupPath . '.sha256';

$command = [
    $dumpExecutable,
    '--host=' . $host,
    '--port=' . $port,
    '--user=' . $username,
    '--single-transaction',
    '--routines',
    '--triggers',
    '--events',
    '--hex-blob',
    '--default-character-set=utf8mb4',
    '--result-file=' . $backupPath,
    $databaseName,
];

// WHAT: pass the password via the MYSQL_PWD env var instead of a CLI flag.
// WHY:  a --password=... argument would be visible to anyone who can list
//       running processes on the machine; an env var set just for this
//       child process isn't. It's restored (or unset) right after, either
//       way the dump turns out.
$previousMysqlPassword = getenv('MYSQL_PWD');
putenv('MYSQL_PWD=' . $password);
$pipes = [];
$process = proc_open(
    $command,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    PROJECT_ROOT,
    null,
    ['bypass_shell' => true]
);

if (!is_resource($process)) {
    $previousMysqlPassword === false
        ? putenv('MYSQL_PWD')
        : putenv('MYSQL_PWD=' . $previousMysqlPassword);
    throw new RuntimeException('Could not start mysqldump.');
}

$standardOutput = stream_get_contents($pipes[1]);
$standardError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$previousMysqlPassword === false
    ? putenv('MYSQL_PWD')
    : putenv('MYSQL_PWD=' . $previousMysqlPassword);

if ($exitCode !== 0 || !is_file($backupPath) || filesize($backupPath) === 0) {
    if (is_file($backupPath)) {
        unlink($backupPath);
    }
    throw new RuntimeException(
        'Database backup failed.'
        . ($standardError !== '' ? ' ' . trim($standardError) : '')
        . ($standardOutput !== '' ? ' ' . trim($standardOutput) : '')
    );
}

$checksum = hash_file('sha256', $backupPath);
if ($checksum === false || file_put_contents($checksumPath, $checksum . '  ' . basename($backupPath) . PHP_EOL) === false) {
    throw new RuntimeException('The backup was created, but its checksum could not be written.');
}

echo $backupPath . PHP_EOL;
echo 'bytes=' . filesize($backupPath) . PHP_EOL;
echo 'sha256=' . $checksum . PHP_EOL;
