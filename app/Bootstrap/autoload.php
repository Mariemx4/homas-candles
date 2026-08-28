<?php

declare(strict_types=1);

/** Lightweight PSR-4 autoloader used when Composer is unavailable. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
        . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
