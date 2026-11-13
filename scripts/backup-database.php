<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/Bootstrap/autoload.php';
try {
    $backup = new App\Maintenance\PostgresBackup(require APP_PATH . '/config/database.local.php', BACKUP_PATH);
    $options = getopt('', ['verify:','restore-check']);
    if (isset($options['verify'])) {
        $receipt = $backup->checkRestore((string)$options['verify']);
        echo 'Restore verified: ' . $receipt['tables_verified'] . ' tables match the archive snapshot.' . PHP_EOL;
    } else {
        $archive = $backup->create();
        echo 'Private PostgreSQL backup: ' . $archive . PHP_EOL;
        if (isset($options['restore-check'])) {
            $receipt = $backup->checkRestore($archive);
            echo 'Restore verified: ' . $receipt['tables_verified'] . ' tables match the archive snapshot.' . PHP_EOL;
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
