<?php

declare(strict_types=1);

namespace App\Auth;

use DomainException;

/** Server-side one-use challenges and a short-lived, unauthenticated signup draft. */
final class GoogleOAuthSession
{
    public const LIFETIME = 600;
    private const FLOWS = 'google_oauth_flows';
    private const PENDING = 'google_oauth_pending';

    public function begin(string $origin, string $purpose = 'customer'): array
    {
        if (!in_array($purpose, ['customer', 'administrator'], true)) {
            throw new DomainException('Invalid Google sign-in purpose.');
        }
        $flows = is_array($_SESSION[self::FLOWS] ?? null) ? $_SESSION[self::FLOWS] : [];
        $flows = array_filter($flows, static fn ($flow) => is_array($flow) && ($flow['expires_at'] ?? 0) > time());
        while (count($flows) >= 5) {
            array_shift($flows);
        }
        $flow = ['state' => ($purpose === 'administrator' ? 'admin.' : '') . self::random(), 'nonce' => self::random(), 'verifier' => self::random(64),
            'purpose' => $purpose,
            'origin' => $purpose === 'administrator' ? 'admin/login.php'
                : (in_array($origin, ['login.php', 'signup.php'], true) ? $origin : 'login.php'),
            'expires_at' => time() + self::LIFETIME];
        $flows[$flow['state']] = $flow;
        $_SESSION[self::FLOWS] = $flows;
        if ($purpose === 'customer') {
            unset($_SESSION[self::PENDING]);
        }
        return $flow;
    }

    /** The prefix selects only the error destination; consume() still verifies all authorization. */
    public function isAdministratorFlow(mixed $state): bool
    {
        return is_string($state) && (preg_match('/^admin\.[A-Za-z0-9_-]{43}$/D', $state) === 1
            || (preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) === 1
                && ($_SESSION[self::FLOWS][$state]['purpose'] ?? null) === 'administrator'));
    }

    public function consume(mixed $state, string $purpose = 'customer'): array
    {
        if (!is_string($state) || preg_match('/^(?:admin\.)?[A-Za-z0-9_-]{43}$/D', $state) !== 1) {
            throw new DomainException('Invalid Google sign-in state.');
        }
        $flow = $_SESSION[self::FLOWS][$state] ?? null;
        unset($_SESSION[self::FLOWS][$state]);
        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !hash_equals($flow['state'], $state)
            || !is_string($flow['nonce'] ?? null) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $flow['nonce']) !== 1
            || !is_string($flow['verifier'] ?? null) || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $flow['verifier']) !== 1
            || !in_array($purpose, ['customer', 'administrator'], true)
            || ($flow['purpose'] ?? 'customer') !== $purpose
            || !in_array($flow['origin'] ?? null, $purpose === 'administrator' ? ['admin/login.php'] : ['login.php', 'signup.php'], true)
            || ($flow['expires_at'] ?? 0) <= time() || ($flow['expires_at'] ?? PHP_INT_MAX) > time() + self::LIFETIME) {
            throw new DomainException('Expired Google sign-in state.');
        }
        return $flow;
    }

    public function remember(GoogleIdentity $identity, ?int $existingCustomer = null): void
    {
        $_SESSION[self::PENDING] = ['identity' => $identity->toArray(), 'existing_customer' => $existingCustomer,
            'expires_at' => time() + self::LIFETIME];
    }

    public function pending(): ?array
    {
        $pending = $_SESSION[self::PENDING] ?? null;
        if (!is_array($pending) || !is_array($pending['identity'] ?? null) || ($pending['expires_at'] ?? 0) <= time()
            || !array_key_exists('existing_customer', $pending)
            || ($pending['existing_customer'] !== null && (!is_int($pending['existing_customer']) || $pending['existing_customer'] < 1))) {
            $this->clear();
            return null;
        }
        $data = $pending['identity'];
        foreach (['subject', 'email', 'first_name', 'last_name'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                $this->clear();
                return null;
            }
        }
        if (isset($data['hosted_domain']) && !is_string($data['hosted_domain'])) {
            $this->clear();
            return null;
        }
        return ['identity' => new GoogleIdentity($data['subject'], $data['email'], $data['first_name'], $data['last_name'], $data['hosted_domain'] ?? null),
            'existing_customer' => $pending['existing_customer']];
    }

    public function needsLinkFor(int $customer): bool
    {
        return ($this->pending()['existing_customer'] ?? null) === $customer;
    }

    public function clear(): void
    {
        unset($_SESSION[self::PENDING]);
    }

    private static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
