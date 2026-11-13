<?php

declare(strict_types=1);



// WHAT: define PROJECT_ROOT and every storage path before anything else runs.
// WHY:  every bootstrap requires this file first, so all later code can use
//       the constants from app/config/paths.php instead of climbing folders.
require_once __DIR__ . '/../config/paths.php';

// Composer supplies the maintained JWT verifier used by Google sign-in. The
// application's own autoloader below still supports non-OAuth maintenance tools.
if (is_file(PROJECT_ROOT . '/vendor/autoload.php')) {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
}

/** Lightweight PSR-4 autoloader used when Composer is unavailable. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = SOURCE_PATH . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
        . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/** Escape a translated public-site label at the HTML boundary. */
if (!function_exists('site_text')) {
    function site_text(string $key): string
    {
        return htmlspecialchars(App\Services\Locale::text($key), ENT_QUOTES, 'UTF-8');
    }
}

// WHAT: every web request gets App\Http\ErrorBoundary as its safety net.
// WHY:  a failure that escapes a page (for example the database being down
//       while a bootstrap connects) now becomes a logged incident and a
//       controlled 503/500 page instead of PHP's default error output.
//       CLI scripts and tests keep PHP's normal behaviour and exit codes.
if (PHP_SAPI !== 'cli') {
    App\Http\ErrorBoundary::register();
}
