<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\AdministratorAccountRepository;

final class AdministratorAuthService
{
    public const GENERIC_LOGIN_ERROR = 'Unable to sign in. Check your details or try again later.';
    public const UNAVAILABLE_ERROR = self::GENERIC_LOGIN_ERROR;


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
        $accountBucket = $this->throttle->accountBucket(
            $administrator === null ? null : (int) $administrator['id'],
            $identifier['value'] ?? $rawIdentifier
        );
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


    /** Only the signed-token verifier supplies this identity; customer records confer no staff access. */
    public function signInWithGoogle(GoogleIdentity $identity, ?string $remoteAddress = null): array
    {
        $administrator = $this->administrators->findByEmail(strtolower($identity->email));
        $accountBucket = $this->throttle->accountBucket(
            $administrator === null ? null : (int) $administrator['id'],
            $identity->email
        );
        $ipBucket = $this->throttle->ipBucket($remoteAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null));
        if ($this->throttle->isBlocked('ADMIN', $accountBucket, $ipBucket)) {
            $this->throttle->blockedAttempt('ADMIN', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        // Gmail/Workspace ownership is sufficient only when that same primary
        // email already belongs to active staff. Other providers use password login.
        if (!$identity->ownsEmailAddress() || $administrator === null
            || $administrator['account_status'] !== 'ACTIVE'
            || !$this->administrators->recordGoogleSignIn((int) $administrator['id'], $identity->email)) {
            $this->throttle->failed('ADMIN', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        $this->throttle->succeeded('ADMIN', $accountBucket, $ipBucket);
        $this->session->signIn((int) $administrator['id']);
        return ['errors' => []];
    }

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
