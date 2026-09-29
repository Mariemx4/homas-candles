<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Runtime configuration for Paths; the one place that knows where the project lives on disk.
 *
 * DATA FLOW: app/Bootstrap/autoload.php loads this file first, so every
 * bootstrap, layout, script, and test can use these constants instead of
 * climbing directories with __DIR__ or dirname(). Keep request or rendering
 * logic out of this layer.
 */

// WHAT: every filesystem location the application reads or writes, derived
//       from a single project root.
// WHY:  bootstrap files used to rebuild paths with dirname(__DIR__, N). When
//       those files moved into app/Bootstrap/admin/, the same N silently
//       pointed at a different folder (product images under app/public/,
//       payment evidence resolving relative to app/). Deriving everything
//       from PROJECT_ROOT means moving a bootstrap, view, or script can no
//       longer change where files are stored.
//
//       This file lives at app/config/paths.php, so the root is exactly two
//       levels up. If this file itself is ever moved, the marker check below
//       fails loudly instead of letting uploads land in the wrong place.
//
//       Public vs private:
//         - PUBLIC_PATH is the web root: anything below it can be requested
//           by URL (product photos are meant to be public).
//         - STORAGE_PATH is outside the web root: payment evidence, database
//           backups, and test sessions must never be reachable by URL.
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
