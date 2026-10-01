<?php

declare(strict_types=1);

// Run with the same PHP installation used by the web server before deploying.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true);
if (!is_array($manifest) || !is_array($manifest['require'] ?? null)) {
    fwrite(STDERR, "FAIL: composer.json is missing or invalid.\n");
    exit(1);
}

$errors = [];
$check = static function (bool $passed, string $label) use (&$errors): void {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$passed) {
        $errors[] = $label;
    }
};

echo 'PHP binary: ' . PHP_BINARY . PHP_EOL;
echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
echo 'Loaded php.ini: ' . (php_ini_loaded_file() ?: '(none)') . PHP_EOL;
echo 'Additional ini files: ' . (php_ini_scanned_files() ?: '(none)') . PHP_EOL;

$check(PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 90000, 'PHP version satisfies ^8.2');
foreach ($manifest['require'] as $package => $constraint) {
    if (str_starts_with($package, 'ext-')) {
        $extension = substr($package, 4);
        $check(extension_loaded($extension), 'PHP extension ' . $extension);
    }
}

if (extension_loaded('pdo')) {
    $check(in_array('mysql', PDO::getAvailableDrivers(), true), 'PDO MySQL driver is available');
}
$check(defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true),
    'Argon2id password hashing is available');

foreach ([
    'private incident logs' => $root . '/app/storage/logs',
    'private payment uploads' => $root . '/app/storage/uploads/payments',
    'public product images' => $root . '/public/assets/products',
] as $label => $directory) {
    $check(is_dir($directory) && is_writable($directory), $label . ' directory is writable');
}

$check(is_file($root . '/app/config/database.local.php'), 'private database configuration exists');
if ($errors === []) {
    require_once $root . '/app/config/database.php';
    try {
        $check((int) database()->query('SELECT 1')->fetchColumn() === 1, 'MariaDB connection works');
    } catch (Throwable $exception) {
        // Database exceptions can include credentials or hostnames; do not print them.
        $check(false, 'MariaDB connection works');
    }
}

echo $errors === [] ? "Preflight passed.\n" : 'Preflight failed: ' . count($errors) . " check(s).\n";
exit($errors === [] ? 0 : 1);
