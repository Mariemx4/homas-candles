<?php

declare(strict_types=1);

// WHAT: integration checks for reset-code hashing, limits, and password change.
// WHY:  a fake mailer captures the code in memory without sending/logging it,
//       while every database operation still runs against the real schema.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../../app/config/database.php';

use App\Auth\PasswordResetService;
use App\Database\TransactionManager;
use App\Mail\PasswordResetMailer;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\PasswordResetRepository;

final class CapturingResetMailer implements PasswordResetMailer
{
    public array $deliveries = [];

    public function sendCode(string $recipientEmail, string $code, int $validMinutes): bool
    {
        $this->deliveries[] = compact('recipientEmail', 'code', 'validMinutes');
        return true;
    }
}

$connection = database();
$transactions = new TransactionManager($connection);
$customers = new CustomerAccountRepository($connection, $transactions);
$resets = new PasswordResetRepository($connection, $transactions);
$mailer = new CapturingResetMailer();
$service = new PasswordResetService($customers, $resets, $transactions, $mailer);
$suffix = substr((string) hrtime(true), -6);
$email = "reset-{$suffix}@example.test";
$customerId = null;
$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

try {
    $customerId = $customers->createWithPrimaryAddress([
        'first_name' => 'Reset',
        'last_name' => 'Test',
        'email' => $email,
        'phone_number' => '90' . $suffix,
        'password_hash' => password_hash('OldPassword1', PASSWORD_DEFAULT),
    ], [
        'street_address' => 'Reset test street',
        'delegation' => 'Tunis',
        'governorate' => 'Tunis',
        'postal_code' => '1000',
    ]);

    $unknown = $service->requestCode("unknown-{$suffix}@example.test");
    $check(
        isset($unknown['errors']['email']) && $mailer->deliveries === [],
        'Unknown email receives a field error without sending mail.'
    );

    $requested = $service->requestCode(strtoupper($email));
    $check($requested['errors'] === [] && count($mailer->deliveries) === 1, 'Known active email receives one reset code.');
    $delivery = $mailer->deliveries[0];
    $check(preg_match('/^[0-9]{6}$/', $delivery['code']) === 1, 'Generated reset code contains exactly six digits.');

    $record = $connection->query(
        'SELECT code_hash, attempts_remaining, used_at FROM customer_password_reset_codes '
        . 'WHERE customer_id = ' . (int) $customerId . ' ORDER BY id DESC LIMIT 1'
    )->fetch();
    $check(
        is_array($record)
        && $record['code_hash'] !== $delivery['code']
        && password_verify($delivery['code'], $record['code_hash']),
        'Database stores only a password_hash() of the email code.'
    );

    $cooldown = $service->requestCode($email);
    $check(
        count($mailer->deliveries) === 1 && isset($cooldown['errors']['form']),
        'One-minute cooldown prevents repeated email delivery and explains the wait.'
    );

    $wrong = $service->resetPassword($email, '999999', 'NewPassword2', 'NewPassword2');
    $check(isset($wrong['errors']['code']), 'Wrong code returns the generic invalid-or-expired message.');
    $attempts = $connection->query(
        'SELECT attempts_remaining FROM customer_password_reset_codes '
        . 'WHERE customer_id = ' . (int) $customerId . ' ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    $check((int) $attempts === 4, 'Wrong code consumes one of five attempts.');

    $completed = $service->resetPassword($email, $delivery['code'], 'NewPassword2', 'NewPassword2');
    $check($completed['errors'] === [], 'Correct unexpired code resets the password.');
    $account = $customers->findByEmail($email);
    $check(
        is_array($account)
        && password_verify('NewPassword2', $account['password_hash'])
        && !password_verify('OldPassword1', $account['password_hash']),
        'New hash accepts the replacement password and rejects the old password.'
    );

    $reused = $service->resetPassword($email, $delivery['code'], 'AnotherPassword3', 'AnotherPassword3');
    $check(isset($reused['errors']['code']), 'A used reset code cannot be reused.');
} finally {
    if ($customerId !== null) {
        $delete = $connection->prepare('DELETE FROM customers_accounts WHERE id = :id');
        $delete->execute(['id' => $customerId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Password reset verification completed without sending email or leaving test rows.' . PHP_EOL;
