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
    public const GENERIC_LOGIN_ERROR = 'Unable to sign in. Check your details or try again later.';
    public const UNAVAILABLE_ERROR = self::GENERIC_LOGIN_ERROR;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AdministratorAccountRepository $administrators,
        private readonly AdministratorSession $session,
        private readonly LoginThrottle $throttle
    ) {
    }

    /**
     * @return array{errors: array<string, string>}
     */
    public function signIn(string $rawIdentifier, string $password, ?string $remoteAddress = null): array
    {
        $identifier = $this->normalizeIdentifier($rawIdentifier);
        $administrator = $identifier === null ? null : ($identifier['type'] === 'email'
            ? $this->administrators->findByEmail($identifier['value'])
            : $this->administrators->findByPhoneNumber($identifier['value']));
        $accountBucket = $this->throttle->accountBucket($administrator === null ? null : (int) $administrator['id'],
            $identifier['value'] ?? $rawIdentifier);
        $ipBucket = $this->throttle->ipBucket($remoteAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null));
        if ($this->throttle->isBlocked('ADMIN', $accountBucket, $ipBucket)) {
            PasswordPolicy::verify(strlen($password) <= PasswordPolicy::MAX_BYTES ? $password : '', PasswordPolicy::DUMMY_HASH);
            $this->throttle->blockedAttempt('ADMIN', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        $validInput = $identifier !== null && $password !== '' && strlen($password) <= PasswordPolicy::MAX_BYTES;
        // Verify a fixed dummy hash for unknown accounts to reduce identity timing differences.
        $oldHash = (string) ($administrator['password_hash'] ?? PasswordPolicy::DUMMY_HASH);
        $verified = PasswordPolicy::verify($validInput ? $password : '', $oldHash);
        if (!$validInput || !$verified || (string) ($administrator['account_status'] ?? '') !== 'ACTIVE') {
            $this->throttle->failed('ADMIN', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }

        $administratorId = (int) $administrator['id'];
        if (PasswordPolicy::needsRehash($oldHash)
            && !$this->administrators->upgradePasswordHashIfCurrent($administratorId, $oldHash, PasswordPolicy::hashVerified($password))) {
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        $this->throttle->succeeded('ADMIN', $accountBucket, $ipBucket);
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
