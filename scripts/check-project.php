<?php

declare(strict_types=1);

// Read-only structural checks. Run before syncing reorganized source to Docker.
// This command never connects to the database or changes application files.
require_once __DIR__ . '/../app/Bootstrap/autoload.php';

$errors = [];
$checked = 0;
$root = str_replace('\\', '/', PROJECT_ROOT);
$files = [];
foreach (['src', 'app/Bootstrap', 'app/config', 'app/Views', 'public', 'scripts', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.local.php')) {
            $files[] = $file->getPathname();
        }
    }
}

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $relative = substr(str_replace('\\', '/', $file), strlen($root) + 1);
    $tokens = token_get_all($source);
    $code = '';
    foreach ($tokens as $token) {
        $code .= is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
    }
    // A move must update its imports instead of leaving a forwarding file.
    preg_match_all('~(?:require|require_once|include|include_once)\s+(__DIR__|APP_PATH|PROJECT_ROOT)\s*\.\s*[\'"]([^\'"\r\n]+)[\'"]~', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (str_ends_with($match[2], '/') || !str_ends_with($match[2], '.php') || str_ends_with($match[2], '.local.php')) {
            continue;
        }
        $base = $match[1] === '__DIR__' ? dirname($file) : constant($match[1]);
        $checked++;
        if (!is_file($base . $match[2])) {
            $errors[] = $relative . ': missing import ' . $match[2];
        }
    }
    preg_match_all('~BASE_URL\s*\.\s*[\'"]([^\'"\r\n?]+\.php)(?:\?[^\'"\r\n]*)?[\'"]~', $code, $matches);
    foreach ($matches[1] as $route) {
        $checked++;
        if (!is_file(PUBLIC_PATH . '/' . ltrim($route, '/'))) {
            $errors[] = $relative . ': missing public route ' . $route;
        }
    }
    // Literal VIEW_PATH references cover templates, fragments and fallback views.
    // Directory prefixes in conditional includes are valid, too.
    preg_match_all('~VIEW_PATH\s*\.\s*[\'"](/[^\'"\r\n]+)[\'"]~', $source, $matches);
    foreach ($matches[1] as $view) {
        $checked++;
        if (str_contains($view, '..') || !file_exists(VIEW_PATH . $view)) {
            $errors[] = $relative . ': missing view ' . $view;
        }
    }
    preg_match_all('~[\'"](assets/(?:js|css)/[^\'"?]+\.(?:js|css))[\'"]~', $source, $matches);
    foreach ($matches[1] as $asset) {
        $checked++;
        if (!is_file(PUBLIC_PATH . '/' . $asset)) {
            $errors[] = $relative . ': missing asset ' . $asset;
        }
    }
    if (str_starts_with($relative, 'src/')) {
        preg_match('~namespace\s+([^;]+);~', $source, $namespace);
        $className = null;
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $next++;
            }
            if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                $className = $tokens[$next][1];
                break;
            }
        }
        if (isset($namespace[1]) && $className !== null) {
            $expected = 'src/' . str_replace('\\', '/', substr(trim($namespace[1]), strlen('App\\'))) . '/' . $className . '.php';
            $checked++;
            if ($relative !== $expected) {
                $errors[] = $relative . ': namespace/class expects ' . $expected;
            }
        }
    }
}

$styles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PUBLIC_PATH . '/assets/css', FilesystemIterator::SKIP_DOTS));
foreach ($styles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'css') {
        continue;
    }
    preg_match_all('~@import\s+url\([\'"]?([^\)\'"\s]+)~', (string) file_get_contents($file->getPathname()), $matches);
    foreach ($matches[1] as $import) {
        if (preg_match('~^https?://~', $import)) {
            continue;
        }
        $checked++;
        if (!is_file(dirname($file->getPathname()) . '/' . $import)) {
            $errors[] = $file->getFilename() . ': missing stylesheet ' . $import;
        }
    }
}

// Every integration suite must be isolated from the shop database. A suite
// passes when it uses a generated schema/database or in-memory SQLite.
// There are no exceptions.
foreach (glob(PROJECT_ROOT . '/tests/Integration/*.php') ?: [] as $suite) {
    $checked++;
    $source = (string) file_get_contents($suite);
    $isolated = preg_match('/isolate_application_database\(|create_throwaway_auth_schema\(|create_throwaway_database\(|sqlite::memory:/', $source) === 1;
    if (!$isolated) {
        $errors[] = 'tests/Integration/' . basename($suite) . ': not isolated from the shop database (see tests/README.md)';
    }
}

// Checks in tests/Live/ read the real shop database, so each must switch its
// connection to read-only before querying it.
foreach (glob(PROJECT_ROOT . '/tests/Live/*.php') ?: [] as $liveCheck) {
    $checked++;
    if (!str_contains((string) file_get_contents($liveCheck), "SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY")) {
        $errors[] = 'tests/Live/' . basename($liveCheck) . ': must make its shop connection read-only (see tests/README.md)';
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo 'Passed ' . $checked . ' import, route, view, asset, stylesheet and namespace checks.' . PHP_EOL;
