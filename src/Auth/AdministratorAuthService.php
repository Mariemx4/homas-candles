<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\AdministratorAccountRepository;

final class AdministratorAuthService
{
    public const GENERIC_LOGIN_ERROR = 'Invalid email or phone number, or password.';
    public const UNAVAILABLE_ERROR = 'This administrator account is not active.';

    public function __construct(
        private readonly AdministratorAccountRepository $administrators,
        private readonly AdministratorSession $session
    ) {
    }

    /** @return array{errors: array<string, string>} */
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

    /** @return array{type: 'email'|'phone', value: string}|null */
    private function normalizeIdentifier(string $rawIdentifier): ?array
    {
        $identifier = trim($rawIdentifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false && strlen($identifier) <= 255) {
            return ['type' => 'email', 'value' => strtolower($identifier)];
        }

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

        return preg_match('/^[0-9]{8}$/', $phone) === 1
            ? ['type' => 'phone', 'value' => $phone]
            : null;
    }
}
