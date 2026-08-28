<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\TransactionManager;
use App\Mail\PasswordResetMailer;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\PasswordResetRepository;

// WHAT: validates reset requests, issues email codes, and changes passwords.
// WHY:  controllers handle HTTP only; this service owns the security rules.
final class PasswordResetService
{
    public const REQUEST_MESSAGE = 'A verification code has been sent to your email address.';
    public const INVALID_CODE_MESSAGE = 'The verification code is invalid or expired.';
    public const CODE_MINUTES = 10;
    private const REQUEST_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly CustomerAccountRepository $customers,
        private readonly PasswordResetRepository $resets,
        private readonly TransactionManager $transactions,
        private readonly PasswordResetMailer $mailer
    ) {
    }

    /** @return array{errors: array<string, string>, email?: string} */
    public function requestCode(string $rawEmail): array
    {
        $email = strtolower(trim($rawEmail));
        if ($email === '') {
            return ['errors' => ['email' => 'Email address is required.']];
        }
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['errors' => ['email' => 'Enter a valid email address, such as name@example.com.']];
        }

        $customer = $this->customers->findByEmail($email);

        // WHAT: report an unknown email directly beside the field.
        // WHY:  this is an explicit product choice; note that it allows people
        //       to discover whether an email is registered on this website.
        if ($customer === null) {
            return ['errors' => ['email' => 'No account exists with this email address.']];
        }

        if ((string) $customer['account_status'] !== 'ACTIVE') {
            return ['errors' => ['form' => 'Password reset is unavailable for this account.']];
        }
        if ($this->resets->hasRecentRequest((int) $customer['id'], self::REQUEST_COOLDOWN_SECONDS)) {
            return ['errors' => ['form' => 'Please wait one minute before requesting another code.']];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            throw new \RuntimeException('Could not hash a password reset code.');
        }

        $resetId = $this->resets->create((int) $customer['id'], $codeHash);
        if (!$this->mailer->sendCode($email, $code, self::CODE_MINUTES)) {
            // A code that was not delivered must never remain usable.
            $this->resets->revoke($resetId);
            error_log('Password reset email delivery failed.');
            return ['errors' => ['form' => 'We could not send the email right now. Please try again later.']];
        }

        return ['errors' => [], 'email' => $email];
    }

    /** @return array{errors: array<string, string>} */
    public function resetPassword(
        string $rawEmail,
        string $code,
        string $password,
        string $confirmation
    ): array {
        $errors = [];
        $email = strtolower(trim($rawEmail));

        if (preg_match('/^[0-9]{6}$/', trim($code)) !== 1) {
            $errors['code'] = 'Enter the 6-digit verification code.';
        }
        $passwordLength = strlen($password);
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif ($passwordLength < 8 || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1) {
            $errors['password'] = 'Use at least 8 characters, including a letter and a number.';
        } elseif ($passwordLength > 4096) {
            $errors['password'] = 'Password is too long.';
        }
        if ($confirmation === '') {
            $errors['password_confirmation'] = 'Password confirmation is required.';
        } elseif (!hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $customer = $this->customers->findByEmail($email);
        if ($customer === null || (string) $customer['account_status'] !== 'ACTIVE') {
            return ['errors' => ['code' => self::INVALID_CODE_MESSAGE]];
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash === false) {
            throw new \RuntimeException('Could not hash the replacement password.');
        }

        return $this->transactions->run(function () use ($customer, $code, $newHash): array {
            $reset = $this->resets->findLatestUsableForUpdate((int) $customer['id']);
            if ($reset === null || (int) $reset['is_expired'] === 1
                || (int) $reset['attempts_remaining'] < 1
            ) {
                if ($reset !== null) {
                    $this->resets->markUsed((int) $reset['id']);
                }
                return ['errors' => ['code' => self::INVALID_CODE_MESSAGE]];
            }

            if (!password_verify(trim($code), (string) $reset['code_hash'])) {
                $this->resets->decrementAttempts((int) $reset['id']);
                return ['errors' => ['code' => self::INVALID_CODE_MESSAGE]];
            }

            // Updating the password and consuming the code happen in one
            // transaction: both succeed, or neither change is committed.
            $this->customers->updatePasswordHash((int) $customer['id'], $newHash);
            $this->resets->markUsed((int) $reset['id']);
            return ['errors' => []];
        });
    }
}
