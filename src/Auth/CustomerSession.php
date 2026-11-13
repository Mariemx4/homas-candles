<?php

declare(strict_types=1);

namespace App\Auth;

// Customer authentication shares a browser session with staff and OAuth drafts.
// Clearing customer access must never destroy the other realm's identity or state.
final class CustomerSession
{
    private bool $expired = false;


    public function __construct(
        private readonly string $cookiePath,
        private readonly int $inactivityTimeout = 1800
    ) {
        $this->start();
        $this->expireInactiveAuthentication();
    }

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

    public function customerId(): ?int
    {
        return $this->isAuthenticated()
            ? $_SESSION['customer_auth']['customer_id']
            : null;
    }

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

    public function signOut(): void
    {
        unset($_SESSION['customer_auth'], $_SESSION['google_oauth_pending'], $_SESSION['_csrf_token']);
        foreach ($_SESSION['google_oauth_flows'] ?? [] as $state => $flow) {
            if (!is_array($flow) || ($flow['purpose'] ?? 'customer') !== 'administrator') {
                unset($_SESSION['google_oauth_flows'][$state]);
            }
        }
        // Invalidate the old cookie ID while retaining independent staff access,
        // locale/cart preferences and an in-progress administrator Google flow.
        session_regenerate_id(true);
    }


    public function hasExpired(): bool
    {
        return $this->expired;
    }

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

    private function expireInactiveAuthentication(): void
    {
        $lastActivity = $_SESSION['customer_auth']['last_activity_at'] ?? null;

        if (!is_int($lastActivity) || time() - $lastActivity <= $this->inactivityTimeout) {
            return;
        }

        // A customer timeout must not erase a still-valid administrator identity
        // or OAuth challenge while the browser is returning from Google.
        $this->expired = true;
        unset($_SESSION['customer_auth']);
        session_regenerate_id(true);
    }
}
