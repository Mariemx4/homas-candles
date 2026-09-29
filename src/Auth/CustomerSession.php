<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Customer Session.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

// WHAT: the only class allowed to configure, start, or destroy PHP sessions.
// WHY:  cookie security must be configured before session_start(), and keeping
//       that work here stops individual pages from starting sessions differently.
final class CustomerSession
{
    private bool $expired = false;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly string $cookiePath,
        private readonly int $inactivityTimeout = 1800
    ) {
        $this->start();
        $this->expireInactiveAuthentication();
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signIn` (sign in) and return its result.
     */
    public function signIn(int $customerId): void
    {
        // WHAT: replace the anonymous session ID after authentication.
        // WHY:  this prevents an attacker from choosing a session ID before the
        //       customer signs in and then reusing it (session fixation).
        session_regenerate_id(true);
        $now = time();
        $_SESSION['customer_auth'] = [
            'customer_id' => $customerId,
            'authenticated_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->expired = false;
    }

    /**
     * FUNCTION PURPOSE: Answer whether authenticated satisfies this condition.
     */
    public function isAuthenticated(): bool
    {
        $auth = $_SESSION['customer_auth'] ?? null;

        if (!is_array($auth)
            || !isset($auth['customer_id'], $auth['authenticated_at'], $auth['last_activity_at'])
            || !is_int($auth['customer_id'])
            || $auth['customer_id'] < 1
        ) {
            return false;
        }

        // WHAT: refresh the inactivity clock whenever an authenticated request
        //       actually checks the session.
        // WHY:  active customers stay signed in while abandoned sessions expire.
        $_SESSION['customer_auth']['last_activity_at'] = time();
        return true;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `customerId` (customer id) and return its result.
     */
    public function customerId(): ?int
    {
        return $this->isAuthenticated()
            ? $_SESSION['customer_auth']['customer_id']
            : null;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `requireCustomer` (require customer) and return its result.
     */
    public function requireCustomer(): int
    {
        $customerId = $this->customerId();

        if ($customerId !== null) {
            return $customerId;
        }

        $query = $this->expired ? '?session_expired=1' : '';
        header('Location: ' . $this->cookiePath . 'login.php' . $query, true, 303);
        exit;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signOut` (sign out) and return its result.
     */
    public function signOut(): void
    {
        $_SESSION = [];

        // WHAT: expire the browser cookie as well as destroying server data.
        // WHY:  session_destroy() alone leaves the old cookie in the browser.
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
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
        $lastActivity = $_SESSION['customer_auth']['last_activity_at'] ?? null;

        if (!is_int($lastActivity) || time() - $lastActivity <= $this->inactivityTimeout) {
            return;
        }

        // Preserve non-authentication flash/form data in a fresh anonymous
        // session, but remove every authenticated value and the old session ID.
        $this->expired = true;
        $this->signOut();
        $this->start();
    }
}
