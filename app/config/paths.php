<?php

declare(strict_types=1);

// Derive paths once from the project root. Public assets belong under PUBLIC_PATH;
// payment evidence and logs belong under STORAGE_PATH, outside the web root.
if (!defined('PROJECT_ROOT')) {
    $projectRoot = dirname(__DIR__, 2);

    // Markers that only exist at the real project root.
    foreach (['/src', '/public/index.php', '/app/config/paths.php'] as $marker) {
        if (!file_exists($projectRoot . $marker)) {
            throw new RuntimeException('The project root could not be determined; check app/config/paths.php.');
        }
    }

    define('PROJECT_ROOT', $projectRoot);
    unset($projectRoot, $marker);

    // Application code and configuration (never web-accessible).
    define('APP_PATH', PROJECT_ROOT . '/app');
    // Views use this root instead of fragile ../../ paths when folders move.
    define('VIEW_PATH', APP_PATH . '/Views');
    define('SOURCE_PATH', PROJECT_ROOT . '/src');
    define('SCRIPTS_PATH', PROJECT_ROOT . '/scripts');

    // Public web root and the public upload folder inside it.
    define('PUBLIC_PATH', PROJECT_ROOT . '/public');
    define('PRODUCT_IMAGE_PATH', PUBLIC_PATH . '/assets/products');

    // Private storage, deliberately outside PUBLIC_PATH.
    define('STORAGE_PATH', APP_PATH . '/storage');
    define('PAYMENT_EVIDENCE_PATH', STORAGE_PATH . '/uploads/payments');
    define('BACKUP_PATH', STORAGE_PATH . '/backups');
    define('TEST_SESSION_PATH', STORAGE_PATH . '/test-sessions');
    // Application incident log (App\Http\ErrorBoundary); app/.htaccess denies web access.
    define('LOG_PATH', STORAGE_PATH . '/logs');
}
