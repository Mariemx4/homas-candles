<?php

declare(strict_types=1);

// Operator-only recovery for an existing active account. Passwords are read
// from a terminal without echo and never accepted as command-line arguments.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = "Run from your project terminal:\n  docker exec -it homas-test php scripts/reset-admin-password.php\n";
if (($argv[1] ?? '') === '--help') {
    echo $usage;
    exit(0);
}
if ($argc !== 1 || PHP_OS_FAMILY === 'Windows' || !stream_isatty(STDIN)) {
    fwrite(STDERR, "An interactive container terminal is required.\n" . $usage);
    exit(1);
}

require_once __DIR__ . '/../app/config/database.php';

use App\Auth\CustomerAuthService;
use App\Auth\PasswordPolicy;
use App\Repositories\AdministratorAccountRepository;

/** Run fixed terminal-control arguments without invoking a shell. */
function admin_recovery_terminal(array $arguments): string
{
    $process = proc_open(
        ['stty', ...$arguments],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Secure terminal input is unavailable.');
    }
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Secure terminal input is unavailable.');
    }
    return trim((string) $output);
}

function admin_recovery_secret(string $prompt): string
{
    $mode = admin_recovery_terminal(['-g']);
    if ($mode === '') {
        throw new RuntimeException('Secure terminal input is unavailable.');
    }
    admin_recovery_terminal(['-echo']);
    try {
        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('Password entry was cancelled.');
        }
        return rtrim($line, "\r\n");
    } finally {
        admin_recovery_terminal([$mode]);
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    fwrite(STDOUT, 'Administrator primary email or phone: ');
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Account selection was cancelled.');
    }
    $identifier = trim($line);
    $accounts = new AdministratorAccountRepository(database());
    if (strlen($identifier) <= 255 && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $account = $accounts->findByEmail(strtolower($identifier));
    } else {
        $phone = CustomerAuthService::normalizeTunisianPhone($identifier);
        $account = $phone === null ? null : $accounts->findByPhoneNumber($phone);
    }
    if ($account === null || $account['account_status'] !== 'ACTIVE') {
        throw new RuntimeException('An active administrator account was not found. No password was changed.');
    }
    fwrite(STDOUT, 'Resetting the existing administrator account #' . (int) $account['id'] . PHP_EOL);
    $password = admin_recovery_secret('New password: ');
    $error = PasswordPolicy::newPasswordError($password);
    if ($error !== null) {
        throw new RuntimeException($error);
    }
    $confirmation = admin_recovery_secret('Confirm new password: ');
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('Passwords did not match. No password was changed.');
    }
    $hash = PasswordPolicy::hashNew($password);
    unset($password, $confirmation);
    if (!$accounts->upgradePasswordHashIfCurrent((int) $account['id'], (string) $account['password_hash'], $hash)) {
        throw new RuntimeException('The account changed during recovery. Run the command again.');
    }
    echo "Administrator password updated. Sign in at http://localhost/admin/login.php\n";
} catch (PDOException) {
    fwrite(STDERR, "Recovery could not complete because the database was unavailable or rejected the change.\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
