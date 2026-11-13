<?php

declare(strict_types=1);



require_once __DIR__ . '/../../app/config/paths.php';
$_SERVER['REMOTE_ADDR'] = '2001:db8::' . bin2hex(random_bytes(2));
ini_set('session.save_path', TEST_SESSION_PATH);
require_once __DIR__ . '/../../app/Bootstrap/admin/administrator-auth.php';
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
// ISOLATION: switch the shared connection to a generated, fully migrated schema
// before anything is read or written. The suite refuses to run otherwise, and
// the schema is dropped on exit even when an assertion fails.
[$isolatedConnection, $testSchema] = isolate_application_database();

use App\Auth\AdministratorAuthService;

$results = [];
$check = TestCheck::collecting($results);

$suffix = substr((string) hrtime(true), -6);
$activeEmail = "admin-auth-{$suffix}@example.test";
$pendingEmail = "admin-pending-{$suffix}@example.test";
$activePhone = '91' . $suffix;
$pendingPhone = '92' . $suffix;
$administratorIds = [];

try {
    $insert = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES
            (:first_name, :last_name, :email, :phone_number, :password_hash, :account_status)'
    );
    foreach (
        [
            [$activeEmail, $activePhone, 'ACTIVE'],
            [$pendingEmail, $pendingPhone, 'PENDING_APPROVAL'],
        ] as [$email, $phone, $status]
    ) {
        $insert->execute([
            'first_name' => 'Admin',
            'last_name' => 'Verifier',
            'email' => $email,
            'phone_number' => $phone,
            'password_hash' => password_hash('Administrator123!', PASSWORD_DEFAULT),
            'account_status' => $status,
        ]);
        $administratorIds[] = (int) $databaseConnection->lastInsertId();
    }

    $wrong = $administratorAuth->signIn($activeEmail, 'WrongPassword1!');
    $unknown = $administratorAuth->signIn("unknown-{$suffix}@example.test", 'WrongPassword1!');
    $check(
        ($wrong['errors']['authentication'] ?? null) === AdministratorAuthService::GENERIC_LOGIN_ERROR
            && ($unknown['errors']['authentication'] ?? null) === AdministratorAuthService::GENERIC_LOGIN_ERROR,
        'Unknown identities and wrong passwords receive the same response.'
    );

    $pending = $administratorAuth->signIn($pendingEmail, 'Administrator123!');
    $check(
        ($pending['errors']['authentication'] ?? null) === AdministratorAuthService::UNAVAILABLE_ERROR,
        'Pending administrators cannot sign in.'
    );

    $oldSessionId = session_id();
    $login = $administratorAuth->signIn('+216 ' . substr($activePhone, 0, 2) . ' ' . substr($activePhone, 2), 'Administrator123!');
    $check($login['errors'] === [], 'An active administrator can sign in with a normalized phone number.');
    $check(session_id() !== $oldSessionId, 'Administrator login regenerates the session ID.');
    $check($administratorAuthorization->activeAdministrator() !== null, 'An active admin session passes authorization.');

    $lastLogin = $databaseConnection->prepare('SELECT last_login_at FROM administrators_accounts WHERE id = :id');
    $lastLogin->execute(['id' => $administratorIds[0]]);
    $check($lastLogin->fetchColumn() !== null, 'Successful login updates the audit timestamp.');

    $suspend = $databaseConnection->prepare("UPDATE administrators_accounts SET account_status = 'SUSPENDED' WHERE id = :id");
    $suspend->execute(['id' => $administratorIds[0]]);
    $check(
        $administratorAuthorization->activeAdministrator() === null && !$administratorSession->isAuthenticated(),
        'Suspension revokes an existing administrator session immediately.'
    );

    $check($csrf->validate($csrf->token()), 'Administrator forms accept a valid CSRF token.');
    $check(!$csrf->validate(str_repeat('0', 64)), 'Administrator forms reject an invalid CSRF token.');
} finally {
    if ($administratorIds !== []) {
        $placeholders = implode(',', array_fill(0, count($administratorIds), '?'));
        $delete = $databaseConnection->prepare("DELETE FROM administrators_accounts WHERE id IN ({$placeholders})");
        $delete->execute($administratorIds);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Administrator authentication verification completed without leaving test rows.' . PHP_EOL;
