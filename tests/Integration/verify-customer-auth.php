<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Customer Auth.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: integration checks for the customer auth service using the real schema.
// WHY:  authentication depends on password hashing, unique indexes, CHECK
//       constraints, transactions, and sessions that mocks would not truly test.
// The CLI verifier uses a workspace-local session folder because its sandbox
// cannot write to XAMPP's normal C:\xampp\tmp directory.
require_once __DIR__ . '/../../app/config/paths.php';
$_SERVER['REMOTE_ADDR'] = '2001:db8::' . bin2hex(random_bytes(2));
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/customer-auth.php';

use App\Auth\CustomerAuthService;
use App\Auth\CustomerSession;

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

// Use a changing suffix and always delete the test customer in finally, so this
// verification is repeatable and never creates demo/customer data permanently.
$suffix = substr((string) hrtime(true), -6);
$email = "auth-{$suffix}@example.test";
$phone = '98' . $suffix;
$rollbackEmail = "rollback-{$suffix}@example.test";
$rollbackPhone = '97' . $suffix;
$customerId = null;
$signupPassword = str_repeat('Student123!', 7);

try {
    $registration = [
        'first_name' => '  Auth   Student ',
        'last_name' => ' Test ',
        'email' => strtoupper($email),
        'phone_number' => '+216 ' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . '-' . substr($phone, 5),
        'second_phone_number' => '+216 ',
        'password' => $signupPassword,
        'password_confirmation' => $signupPassword,
        'address_label' => 'Home',
        'street_address' => '1 Verification Street, Apartment 2',
        'governorate' => 'Tunis',
        'delegation' => 'Tunis',
        'postal_code' => '1000',
    ];

    $created = $customerAuth->register($registration);
    $check($created['errors'] === [] && isset($created['customer_id']), 'Valid signup creates a customer account.');
    $customerId = (int) $created['customer_id'];
    $newAccount = $customerAccounts->findByEmail($email);
    $check(strlen($signupPassword) > 72 && password_get_info((string) $newAccount['password_hash'])['algoName'] === 'argon2id'
        && !password_verify(substr($signupPassword, 0, 72) . 'X' . substr($signupPassword, 73), $newAccount['password_hash']),
        'Signup stores an Argon2id hash that distinguishes bytes after 72.');
    $oversizedSignup = $customerAuth->register(array_replace($registration, [
        'password' => str_repeat('A1', 2048) . 'X',
        'password_confirmation' => str_repeat('A1', 2048) . 'X',
    ]));
    $check(isset($oversizedSignup['errors']['password']), 'Signup rejects a password over 4,096 bytes.');

    $addressStatement = $databaseConnection->prepare(
        'SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :customer_id AND is_primary = 1'
    );
    $addressStatement->execute(['customer_id' => $customerId]);
    $check((int) $addressStatement->fetchColumn() === 1, 'Valid signup creates exactly one primary address.');
    $optionalPhoneStatement = $databaseConnection->prepare(
        'SELECT second_phone_number FROM customers_accounts WHERE id = :customer_id'
    );
    $optionalPhoneStatement->execute(['customer_id' => $customerId]);
    $optionalPhoneRow = $optionalPhoneStatement->fetch();
    $check(is_array($optionalPhoneRow) && $optionalPhoneRow['second_phone_number'] === null, 'Secondary phone number is optional and stores NULL when omitted.');

    // Force the second INSERT to violate the postal-code CHECK. The repository
    // transaction must remove the first INSERT automatically.
    try {
        $customerAccounts->createWithPrimaryAddress([
            'first_name' => 'Rollback', 'last_name' => 'Test', 'email' => $rollbackEmail,
            'phone_number' => $rollbackPhone, 'password_hash' => password_hash('Student123!', PASSWORD_DEFAULT),
        ], [
            'street_address' => 'Invalid address', 'governorate' => 'Tunis',
            'delegation' => 'Tunis', 'postal_code' => 'BAD',
        ]);
        throw new RuntimeException('The invalid address unexpectedly succeeded.');
    } catch (\PDOException) {
        // Expected: the schema rejects the address and TransactionManager rolls back.
    }
    $rollbackStatement = $databaseConnection->prepare('SELECT COUNT(*) FROM customers_accounts WHERE email = :email');
    $rollbackStatement->execute(['email' => $rollbackEmail]);
    $check((int) $rollbackStatement->fetchColumn() === 0, 'Address failure rolls back customer creation.');

    $duplicateEmail = $customerAuth->register(array_replace($registration, [
        'phone_number' => '96' . $suffix,
    ]));
    $check(isset($duplicateEmail['errors']['email']), 'Duplicate email is rejected.');
    $duplicatePhone = $customerAuth->register(array_replace($registration, [
        'email' => "other-{$suffix}@example.test",
    ]));
    $check(isset($duplicatePhone['errors']['phone_number']), 'Duplicate primary phone is rejected.');

    $invalid = $customerAuth->register(array_replace($registration, [
        'email' => 'not-an-email', 'phone_number' => '12AB5678',
    ]));
    $check(isset($invalid['errors']['email']) && isset($invalid['errors']['phone_number']), 'Invalid email and phone are rejected server-side.');
    $invalidSecondPhone = $customerAuth->register(array_replace($registration, [
        'second_phone_number' => 'phone-letters',
    ]));
    $sameSecondPhone = $customerAuth->register(array_replace($registration, [
        'second_phone_number' => $registration['phone_number'],
    ]));
    $check(
        isset($invalidSecondPhone['errors']['second_phone_number'])
        && isset($sameSecondPhone['errors']['second_phone_number']),
        'Optional secondary phone is validated and must differ from the primary phone.'
    );

    $oldSessionId = session_id();
    $emailLogin = $customerAuth->signIn(strtoupper($email), $signupPassword);
    $check($emailLogin['errors'] === [], 'Login succeeds with a normalized email address.');
    $check(session_id() !== $oldSessionId, 'Session ID changes after successful login.');

    $phoneLogin = $customerAuth->signIn('+216 ' . substr($phone, 0, 2) . '-' . substr($phone, 2), $signupPassword);
    $check($phoneLogin['errors'] === [], 'Login succeeds with a formatted Tunisian phone number.');
    $lastLoginStatement = $databaseConnection->prepare('SELECT last_login_at FROM customers_accounts WHERE id = :id');
    $lastLoginStatement->execute(['id' => $customerId]);
    $check($lastLoginStatement->fetchColumn() !== null, 'Successful authentication updates last_login_at.');
    $check(
        CustomerAuthService::normalizeTunisianPhone('00216 ' . $phone) === $phone
        && CustomerAuthService::normalizeTunisianPhone('12AB5678') === null,
        'Phone normalization accepts 00216 formatting and rejects letters.'
    );

    $wrong = $customerAuth->signIn($email, 'WrongPassword1');
    $unknownEmail = $customerAuth->signIn("unknown-{$suffix}@example.test", 'WrongPassword1');
    $unknownPhone = $customerAuth->signIn('22' . $suffix, 'WrongPassword1');
    $expected = CustomerAuthService::GENERIC_LOGIN_ERROR;
    $check(
        ($wrong['errors']['authentication'] ?? null) === $expected
        && ($unknownEmail['errors']['authentication'] ?? null) === $expected
        && ($unknownPhone['errors']['authentication'] ?? null) === $expected,
        'Wrong password and unknown identifiers return the same generic message.'
    );

    $suspend = $databaseConnection->prepare("UPDATE customers_accounts SET account_status = 'SUSPENDED' WHERE id = :id");
    $suspend->execute(['id' => $customerId]);
    $suspended = $customerAuth->signIn($email, $signupPassword);
    $check(($suspended['errors']['authentication'] ?? null) === CustomerAuthService::SUSPENDED_LOGIN_ERROR, 'Suspended customer cannot sign in.');
    $activate = $databaseConnection->prepare("UPDATE customers_accounts SET account_status = 'ACTIVE' WHERE id = :id");
    $activate->execute(['id' => $customerId]);

    $check($csrf->validate($csrf->token()), 'Valid CSRF token is accepted.');
    $check(!$csrf->validate(str_repeat('0', 64)), 'Invalid CSRF token is rejected.');

    $authKeys = array_keys($_SESSION['customer_auth'] ?? []);
    sort($authKeys);
    $check($authKeys === ['authenticated_at', 'customer_id', 'last_activity_at'], 'Session stores only the required customer identity timestamps.');
    $check($customerSession->isAuthenticated(), 'Authenticated sessions are recognized by protected-page guard logic.');

    $cookie = session_get_cookie_params();
    $check($cookie['httponly'] && ($cookie['samesite'] ?? null) === 'Lax', 'Session cookie uses HttpOnly and SameSite=Lax.');

    $_SESSION['customer_auth']['last_activity_at'] = time() - 10;
    $shortSession = new CustomerSession('/homas-candles/public/', 1);
    $check($shortSession->hasExpired() && !$shortSession->isAuthenticated(), 'Inactive authentication expires and becomes anonymous.');

    // Sign in once more so the explicit logout behavior can be checked too.
    $activeAgain = $customerAuth->signIn($email, $signupPassword);
    $check($activeAgain['errors'] === [], 'Customer can sign in again after an inactivity timeout.');

    $customerSession->signOut();
    $check(session_status() !== PHP_SESSION_ACTIVE && ($_SESSION ?? []) === [], 'Logout destroys the session and clears its values.');
    $check(!$customerSession->isAuthenticated(), 'Unauthenticated sessions fail the protected-page guard check.');
} finally {
    if ($customerId !== null) {
        $delete = $databaseConnection->prepare('DELETE FROM customers_accounts WHERE id = :id');
        $delete->execute(['id' => $customerId]);
    }
    $cleanup = $databaseConnection->prepare(
        'DELETE FROM customers_accounts WHERE email IN (:rollback_email, :other_email)'
    );
    $cleanup->execute([
        'rollback_email' => $rollbackEmail,
        'other_email' => "other-{$suffix}@example.test",
    ]);
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Customer authentication verification completed without leaving test rows.' . PHP_EOL;
