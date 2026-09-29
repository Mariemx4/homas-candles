<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Administrator Auth Service.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

use App\Repositories\AdministratorAccountRepository;

final class AdministratorAuthService
{
    public const GENERIC_LOGIN_ERROR = 'Invalid email or phone number, or password.';
    public const UNAVAILABLE_ERROR = 'This administrator account is not active.';

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AdministratorAccountRepository $administrators,
        private readonly AdministratorSession $session
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signIn` (sign in) and return its result.
     *
     * @return array{errors: array<string, string>}
     */
    public function signIn(string $rawIdentifier, string $password): array
    {
        $identifier = $this->normalizeIdentifier($rawIdentifier);
        if (trim($rawIdentifier) === '') {
            return ['errors' => ['login_identifier' => 'Email or phone number is required.']];
        }
        if ($identifier === null) {
            return ['errors' => ['login_identifier' => 'Enter a valid email address or Tunisian phone number.']];
        }
        if ($password === '') {
            return ['errors' => ['password' => 'Password is required.']];
        }
        if (strlen($password) > 4096) {
            return ['errors' => ['password' => 'Password is too long.']];
        }

        $administrator = $identifier['type'] === 'email'
            ? $this->administrators->findByEmail($identifier['value'])
            : $this->administrators->findByPhoneNumber($identifier['value']);

        if ($administrator === null
            // PASSWORD IDIOM: Compare plain submitted text with the stored one-way hash without decrypting the hash.
            || !password_verify($password, (string) $administrator['password_hash'])
        ) {
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        if ((string) $administrator['account_status'] !== 'ACTIVE') {
            return ['errors' => ['authentication' => self::UNAVAILABLE_ERROR]];
        }

        $administratorId = (int) $administrator['id'];
        $this->administrators->updateLastLogin($administratorId);
        $this->session->signIn($administratorId);

        return ['errors' => []];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert identifier into a consistent internal shape.
     *
     * @return array{type: 'email'|'phone', value: string}|null
     */
    private function normalizeIdentifier(string $rawIdentifier): ?array
    {
        $identifier = trim($rawIdentifier);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false && strlen($identifier) <= 255) {
            return ['type' => 'email', 'value' => strtolower($identifier)];
        }

        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if ($identifier === '' || preg_match('/^(?:\+)?[0-9\s-]+$/', $identifier) !== 1) {
            return null;
        }
        $phone = preg_replace('/[\s-]+/', '', $identifier);
        if (!is_string($phone)) {
            return null;
        }
        if (str_starts_with($phone, '+216')) {
            $phone = substr($phone, 4);
        } elseif (str_starts_with($phone, '00216')) {
            $phone = substr($phone, 5);
        }

        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        return preg_match('/^[0-9]{8}$/', $phone) === 1
            ? ['type' => 'phone', 'value' => $phone]
            : null;
    }
}
