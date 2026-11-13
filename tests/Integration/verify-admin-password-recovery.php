<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Auth\PasswordPolicy;

[$db, $schema] = create_throwaway_auth_schema();
$checks = 0;
$check = TestCheck::printing($checks);

/** Drive a real pseudo-terminal, responding only after each prompt appears. */
$run = static function (array $answers) use ($schema): array {
    $environment = getenv();
    $environment['PGOPTIONS'] = '-c search_path=' . $schema;
    unset($environment['HOMAS_VERIFICATION_DATABASE']);
    $process = proc_open(
        [PHP_BINARY, SCRIPTS_PATH . '/reset-admin-password.php'],
        [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
        $pipes,
        PROJECT_ROOT,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Recovery test terminal could not start.');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $output = '';
    $answer = 0;
    $deadline = microtime(true) + 10;
    $exit = null;
    try {
        while (microtime(true) < $deadline) {
            $output .= (string) @fread($pipes[1], 8192);
            if (isset($answers[$answer]) && str_contains($output, $answers[$answer][0])) {
                fwrite($pipes[0], $answers[$answer][1] . "\n");
                ++$answer;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $output .= (string) @stream_get_contents($pipes[1]);
                $exit = $status['exitcode'];
                break;
            }
            usleep(10000);
        }
        if ($exit === null) {
            throw new RuntimeException('Recovery test timed out at prompt ' . $answer . '.');
        }
        return [$exit, $output];
    } finally {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        if ($exit === null) {
            proc_terminate($process);
        }
        proc_close($process);
    }
};

try {
    $oldPassword = 'OldRecovery-' . bin2hex(random_bytes(12)) . '1';
    $newPassword = 'NewRecovery-' . bin2hex(random_bytes(12)) . '2';
    $oldHash = PasswordPolicy::hashNew($oldPassword);
    $insert = $db->prepare("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash,account_status)
        VALUES('Recovery','Test',:email,:phone,:hash,:status) RETURNING id");
    $insert->execute(['email' => 'recover@example.test', 'phone' => '98110021', 'hash' => $oldHash, 'status' => 'ACTIVE']);
    $active = (int) $insert->fetchColumn();
    $insert->execute(['email' => 'suspended@example.test', 'phone' => '98110022', 'hash' => $oldHash, 'status' => 'SUSPENDED']);
    $suspended = (int) $insert->fetchColumn();
    $stored = static function (int $id) use ($db): string {
        $query = $db->prepare('SELECT password_hash FROM administrators_accounts WHERE id=:id');
        $query->execute(['id' => $id]);
        return (string) $query->fetchColumn();
    };

    [$exit, $output] = $run([
        ['Administrator primary email or phone: ', 'recover@example.test'],
        ['New password: ', $newPassword],
        ['Confirm new password: ', $newPassword],
    ]);
    $hash = $stored($active);
    $check($exit === 0 && str_contains($output, 'Administrator password updated.'), 'An operator can reset the selected active administrator in a real terminal.');
    $check(PasswordPolicy::verify($newPassword, $hash) && !PasswordPolicy::verify($oldPassword, $hash)
        && password_get_info($hash)['algoName'] === 'argon2id', 'The new credential uses the application policy and replaces the previous password.');
    $check(!str_contains($output, $newPassword), 'Neither password prompt echoes the entered secret.');
    $check(
        $stored($suspended) === $oldHash && (int) $db->query('SELECT COUNT(*) FROM administrators_accounts')->fetchColumn() === 2,
        'Recovery changes only the selected password and creates no extra accounts.'
    );

    [$exit, $output] = $run([
        ['Administrator primary email or phone: ', '+216 98 110 021'],
        ['New password: ', $oldPassword],
        ['Confirm new password: ', 'MismatchPassword123'],
    ]);
    $check(
        $exit === 1 && str_contains($output, 'Passwords did not match.') && $stored($active) === $hash,
        'Formatted primary-phone selection works; a confirmation mismatch preserves the saved password.'
    );
    $check(!str_contains($output, $oldPassword) && !str_contains($output, 'MismatchPassword123'), 'Rejected password entries are not echoed either.');

    [$exit, $output] = $run([
        ['Administrator primary email or phone: ', 'recover@example.test'],
        ['New password: ', 'short'],
    ]);
    $check(
        $exit === 1 && str_contains($output, 'Use at least 8 characters') && $stored($active) === $hash,
        'A weak password is rejected without changing the account.'
    );

    foreach (['suspended@example.test', 'missing@example.test'] as $identifier) {
        [$exit, $output] = $run([['Administrator primary email or phone: ', $identifier]]);
        $check($exit === 1 && str_contains($output, 'An active administrator account was not found.')
            && !str_contains($output, 'New password: '), 'Unavailable accounts are rejected before password entry: ' . $identifier);
    }
    $check(
        $stored($suspended) === $oldHash && $db->query('SELECT account_status FROM administrators_accounts WHERE id=' . $suspended)->fetchColumn() === 'SUSPENDED',
        'Recovery never reactivates a suspended administrator.'
    );
    echo 'Verified ' . $checks . ' administrator recovery assertions.' . PHP_EOL;
} finally {
    drop_throwaway_auth_schema($db, $schema);
}
