<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
ini_set('session.save_path', TEST_SESSION_PATH);
ob_start();

use App\Auth\AdministratorAuthService;
use App\Auth\AdministratorSession;
use App\Auth\CustomerAuthService;
use App\Auth\CustomerSession;
use App\Auth\LoginThrottle;
use App\Auth\PasswordPolicy;
use App\Database\TransactionManager;
use App\Repositories\AdministratorAccountRepository;
use App\Repositories\CustomerAccountRepository;

[$db, $schemaName] = create_throwaway_auth_schema();
$logPath = LOG_PATH . '/login-throttle-test.log';
ini_set('error_log', $logPath);
$check = TestCheck::printing();

try {
    $customerEmail = 'throttle-customer@example.test';
    $adminEmail = 'throttle-admin@example.test';
    $password = 'CorrectPassword123!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insertCustomer = $db->prepare(
        "INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES ('Test', 'Customer', :email, '12345678', :hash, 'ACTIVE')"
    );
    $insertCustomer->execute(['email' => $customerEmail, 'hash' => $hash]);
    $customerId = (int) $db->lastInsertId();
    $insertAdmin = $db->prepare(
        "INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES ('Test', 'Admin', :email, '87654321', :hash, 'ACTIVE')"
    );
    $insertAdmin->execute(['email' => $adminEmail, 'hash' => $hash]);
    $adminId = (int) $db->lastInsertId();

    $throttle = new LoginThrottle($db, str_repeat('test-key-', 5));
    $customerRepository = new CustomerAccountRepository($db, new TransactionManager($db));
    $adminRepository = new AdministratorAccountRepository($db);
    $customers = new CustomerAuthService(
        $customerRepository,
        new CustomerSession('/homas-candles/public/'),
        [],
        $throttle
    );
    $admins = new AdministratorAuthService(
        $adminRepository,
        new AdministratorSession('/homas-candles/public/', '/homas-candles/public/admin/login.php'),
        $throttle
    );
    $ip = '192.0.2.10';
    $first = $customers->signIn($customerEmail, 'wrong', $ip);
    $unknown = $customers->signIn('unknown@example.test', 'wrong', '192.0.2.11');
    $check($first['errors'] === $unknown['errors'], 'Unknown accounts and wrong passwords have the same response.');
    for ($attempt = 2; $attempt <= 5; $attempt++) {
        $customers->signIn($customerEmail, 'wrong', $ip);
    }
    $accountBucket = $throttle->accountBucket($customerId, $customerEmail);
    $row = $db->prepare("SELECT failures, blocked_until > statement_timestamp() AS blocked FROM login_throttle
                         WHERE realm = 'CUSTOMER' AND scope = 'ACCOUNT' AND bucket_hash = :hash");
    $row->execute(['hash' => $accountBucket]);
    $state = $row->fetch();
    $check(
        (int) $state['failures'] === 5 && (int) $state['blocked'] === 1,
        'Five customer failures activate the account delay.'
    );
    $blocked = $customers->signIn($customerEmail, $password, '192.0.2.12');
    $check($blocked['errors'] === $first['errors'], 'A correct password cannot bypass an active account delay.');

    $db->prepare("UPDATE login_throttle SET blocked_until = statement_timestamp() - INTERVAL '1 second'
                  WHERE realm = 'CUSTOMER' AND scope = 'ACCOUNT' AND bucket_hash = :hash")
        ->execute(['hash' => $accountBucket]);
    $customers->signIn($customerEmail, 'wrong', $ip);
    $row = $db->prepare("SELECT failures, FLOOR(EXTRACT(EPOCH FROM (blocked_until - statement_timestamp()))) AS delay_seconds
                         FROM login_throttle WHERE realm = 'CUSTOMER' AND scope = 'ACCOUNT' AND bucket_hash = :hash");
    $row->execute(['hash' => $accountBucket]);
    $state = $row->fetch();
    $check(
        (int) $state['failures'] === 6 && (int) $state['delay_seconds'] >= 3,
        'The next failure increases the delay progressively.'
    );
    $db->prepare("UPDATE login_throttle SET blocked_until = statement_timestamp() - INTERVAL '1 second'
                  WHERE realm = 'CUSTOMER' AND scope = 'ACCOUNT' AND bucket_hash = :hash")
        ->execute(['hash' => $accountBucket]);
    $check(
        $customers->signIn('12345678', $password, '192.0.2.13')['errors'] === [],
        'An account delay is shared by email and phone, then cleared after success.'
    );
    $customerHash = $db->query("SELECT password_hash FROM customers_accounts WHERE id = {$customerId}")->fetchColumn();
    $check(
        password_get_info((string) $customerHash)['algoName'] === 'argon2id',
        'Successful customer login upgrades a legacy bcrypt hash.'
    );
    $check(
        !$customerRepository->upgradePasswordHashIfCurrent($customerId, $hash, PasswordPolicy::hashNew('AnotherPassword123!'))
        && $db->query("SELECT password_hash FROM customers_accounts WHERE id = {$customerId}")->fetchColumn() === $customerHash,
        'A stale customer hash cannot overwrite a newer password.'
    );
    $row->execute(['hash' => $accountBucket]);
    $check($row->fetch() === false, 'Successful authentication clears account failures.');

    $adminIp = '198.51.100.5';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $admins->signIn("unknown-{$attempt}@example.test", 'wrong', $adminIp);
    }
    $adminIpBucket = $throttle->ipBucket($adminIp);
    $ipRow = $db->prepare("SELECT failures, blocked_until > statement_timestamp() AS blocked FROM login_throttle
                           WHERE realm = 'ADMIN' AND scope = 'IP' AND bucket_hash = :hash");
    $ipRow->execute(['hash' => $adminIpBucket]);
    $ipState = $ipRow->fetch();
    $check(
        (int) $ipState['failures'] === 10 && (int) $ipState['blocked'] === 1,
        'Password spraying triggers the administrator IP delay.'
    );
    $blockedAdmin = $admins->signIn($adminEmail, $password, $adminIp);
    $check(
        $blockedAdmin['errors'] === $first['errors']
        && $admins->signIn($adminEmail, $password, '198.51.100.6')['errors'] === [],
        'An IP delay blocks that source without locking the account everywhere.'
    );
    $adminHash = $db->query("SELECT password_hash FROM administrators_accounts WHERE id = {$adminId}")->fetchColumn();
    $check(
        password_get_info((string) $adminHash)['algoName'] === 'argon2id',
        'Successful administrator login upgrades a legacy bcrypt hash.'
    );
    $check(
        !$adminRepository->upgradePasswordHashIfCurrent($adminId, $hash, PasswordPolicy::hashNew('AnotherPassword123!'))
        && $db->query("SELECT password_hash FROM administrators_accounts WHERE id = {$adminId}")->fetchColumn() === $adminHash,
        'A stale administrator hash cannot overwrite a newer password.'
    );
    $check(
        $admins->signIn('+216 87 654 321', $password, '198.51.100.7')['errors'] === [],
        'Administrator login also accepts a formatted primary phone number.'
    );

    // Account columns use case-insensitive ICU equality; hash comparisons must not.
    $caseVariant = static fn (string $value): string => preg_replace_callback(
        '/[a-z]/',
        static fn (array $match): string => strtoupper($match[0]),
        $value,
        1
    );
    $check(
        !$customerRepository->upgradePasswordHashIfCurrent($customerId, $caseVariant($customerHash), $hash)
        && !$adminRepository->upgradePasswordHashIfCurrent($adminId, $caseVariant($adminHash), $hash),
        'Password upgrades compare hashes case-sensitively despite the account collation.'
    );

    $customers->signIn($customerEmail, 'wrong', '192.0.2.30');
    $db->prepare("UPDATE login_throttle SET failures = 100, last_failure_at = statement_timestamp() - INTERVAL '16 minutes',
                  blocked_until = statement_timestamp() - INTERVAL '1 second'
                  WHERE realm = 'CUSTOMER' AND scope = 'ACCOUNT' AND bucket_hash = :hash")
        ->execute(['hash' => $accountBucket]);
    $customers->signIn($customerEmail, 'wrong', '192.0.2.31');
    $row->execute(['hash' => $accountBucket]);
    $state = $row->fetch();
    $check(
        (int) $state['failures'] === 1 && (int) $state['delay_seconds'] <= 0,
        'Account failures reset after the inactivity window.'
    );

    $legacyPassword = str_repeat('A1', 36);
    $legacyHash = password_hash($legacyPassword, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash)
                  VALUES ('Legacy', 'Customer', 'legacy-customer@example.test', '12345679', :hash)")
        ->execute(['hash' => $legacyHash]);
    $db->prepare("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash)
                  VALUES ('Legacy', 'Admin', 'legacy-admin@example.test', '87654322', :hash)")
        ->execute(['hash' => $legacyHash]);
    $check(
        password_verify($legacyPassword . 'X', $legacyHash)
        && $customers->signIn('legacy-customer@example.test', $legacyPassword . 'X', '192.0.2.20')['errors'] === $first['errors']
        && $admins->signIn('legacy-admin@example.test', $legacyPassword . 'X', '198.51.100.20')['errors'] === $first['errors'],
        'Both login realms reject a suffix that legacy bcrypt would ignore.'
    );
    $check(
        $customers->signIn('legacy-customer@example.test', $legacyPassword, '192.0.2.21')['errors'] === $first['errors']
        && $admins->signIn('legacy-admin@example.test', $legacyPassword, '198.51.100.21')['errors'] === $first['errors'],
        'Ambiguous 72-byte legacy passwords require a reset or administrator rotation.'
    );
    $check(
        PasswordPolicy::newPasswordError(str_repeat('A1', 2048)) === null
        && PasswordPolicy::newPasswordError(str_repeat('A1', 2048) . 'X') !== null,
        'The shared new-password limit is measured in bytes.'
    );
    $check(
        PasswordPolicy::newPasswordError(str_repeat('é', 3) . 'A1') !== null,
        'The eight-character minimum is not confused with UTF-8 byte count.'
    );

    $logs = (string) file_get_contents($logPath);
    $check(
        !str_contains($logs, $customerEmail) && !str_contains($logs, $adminEmail)
        && !str_contains($logs, $adminIp) && !str_contains($logs, $password),
        'Authentication logs contain no raw identifiers, IPs, or passwords.'
    );
} finally {
    drop_throwaway_auth_schema($db, $schemaName);
    if (is_file($logPath)) {
        unlink($logPath);
    }
    ob_end_flush();
}
