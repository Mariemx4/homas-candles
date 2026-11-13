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

require_once $root . '/app/Bootstrap/autoload.php';
$check(class_exists(\Firebase\JWT\JWT::class), 'locked Composer JWT dependency is installed');

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
    $check(in_array('pgsql', PDO::getAvailableDrivers(), true), 'PDO PostgreSQL driver is available');
}
$check(
    defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true),
    'Argon2id password hashing is available'
);

// Root can write anywhere, so `is_writable()` proves nothing when preflight is
// run as root (e.g. `docker exec`). In that case, check the permission bits
// for the account Apache actually runs as (override with HOMAS_WEB_USER).
$webUser = getenv('HOMAS_WEB_USER') ?: 'www-data';
$runningAsRoot = function_exists('posix_getpwnam') && posix_geteuid() === 0;
$writableByWebUser = static function (string $directory) use ($webUser): bool {
    $account = posix_getpwnam($webUser);
    $info = @stat($directory);
    if ($account === false || $info === false) {
        return false;
    }
    $directoryGroup = posix_getgrgid($info['gid']);
    $inGroup = $info['gid'] === $account['gid']
        || ($directoryGroup !== false && in_array($webUser, $directoryGroup['members'], true));
    // Writing into a directory needs both "write" and "search" (execute).
    $mode = $info['mode'];
    if ($info['uid'] === $account['uid']) {
        return ($mode & 0300) === 0300;
    }
    if ($inGroup) {
        return ($mode & 0030) === 0030;
    }
    return ($mode & 0003) === 0003;
};
if ($runningAsRoot) {
    echo 'Running as root: directory checks use the permissions of ' . $webUser . PHP_EOL;
}

foreach ([
    'private incident logs' => $root . '/app/storage/logs',
    'private payment uploads' => $root . '/app/storage/uploads/payments',
    'public product images' => $root . '/public/assets/products',
] as $label => $directory) {
    $writable = is_dir($directory)
        && ($runningAsRoot ? $writableByWebUser($directory) : is_writable($directory));
    $check($writable, $label . ' directory is writable');
}

$check(is_file($root . '/app/config/database.local.php'), 'private database configuration exists');
$check(is_file($root . '/app/database/reference.sql'), 'reference catalogue data exists');
try {
    require $root . '/app/config/login-throttle.php';
    $check(true, 'private login-throttle key is configured');
} catch (Throwable) {
    $check(false, 'private login-throttle key is configured');
}
if ($errors === []) {
    require_once $root . '/app/config/database.php';
    try {
        $check((int) database()->query('SELECT 1')->fetchColumn() === 1, 'PostgreSQL connection works');
    } catch (Throwable $exception) {
        // Database exceptions can include credentials or hostnames; do not print them.
        $check(false, 'PostgreSQL connection works');
    }
}

echo $errors === [] ? "Preflight passed.\n" : 'Preflight failed: ' . count($errors) . " check(s).\n";
exit($errors === [] ? 0 : 1);
