<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Administrator Session.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

final class AdministratorSession
{
    private const SESSION_KEY = 'administrator_auth';
    private bool $expired = false;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly string $cookiePath,
        private readonly string $loginUrl,
        private readonly int $inactivityTimeout = 10800
    ) {
        $this->start();
        $this->expireInactiveAuthentication();
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signIn` (sign in) and return its result.
     */
    public function signIn(int $administratorId): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION[self::SESSION_KEY] = [
            'administrator_id' => $administratorId,
            'authenticated_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->expired = false;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `administratorId` (administrator id) and return its result.
     */
    public function administratorId(): ?int
    {
        $auth = $_SESSION[self::SESSION_KEY] ?? null;
        if (
            !is_array($auth)
            || !isset($auth['administrator_id'], $auth['authenticated_at'], $auth['last_activity_at'])
            || !is_int($auth['administrator_id'])
            || $auth['administrator_id'] < 1
        ) {
            return null;
        }

        $_SESSION[self::SESSION_KEY]['last_activity_at'] = time();
        return $auth['administrator_id'];
    }

    /**
     * FUNCTION PURPOSE: Answer whether authenticated satisfies this condition.
     */
    public function isAuthenticated(): bool
    {
        return $this->administratorId() !== null;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `requireAdministrator` (require administrator) and return its result.
     */
    public function requireAdministrator(): int
    {
        $administratorId = $this->administratorId();
        if ($administratorId !== null) {
            return $administratorId;
        }

        $query = $this->expired ? '?session_expired=1' : '';
        header('Location: ' . $this->loginUrl . $query, true, 303);
        exit;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `clearAuthentication` (clear authentication) and return its result.
     */
    public function clearAuthentication(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signOut` (sign out) and return its result.
     */
    public function signOut(): void
    {
        $this->clearAuthentication();
    }

    /**
     * FUNCTION PURPOSE: Answer whether expired exists or is present.
     */
    public function hasExpired(): bool
    {
        return $this->expired;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `start` (start) and return its result.
     */
    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $usesHttps = isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'domain' => '',
            'secure' => $usesHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `expireInactiveAuthentication` (expire inactive authentication) and return its result.
     */
    private function expireInactiveAuthentication(): void
    {
        $lastActivity = $_SESSION[self::SESSION_KEY]['last_activity_at'] ?? null;
        if (!is_int($lastActivity) || time() - $lastActivity <= $this->inactivityTimeout) {
            return;
        }

        $this->expired = true;
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
