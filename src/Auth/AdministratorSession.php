<?php

declare(strict_types=1);

namespace App\Auth;

final class AdministratorSession
{
    private const SESSION_KEY = 'administrator_auth';
    private bool $expired = false;


    public function __construct(
        private readonly string $cookiePath,
        private readonly string $loginUrl,
        private readonly int $inactivityTimeout = 10800
    ) {
        $this->start();
        $this->expireInactiveAuthentication();
    }

    public function signIn(int $administratorId): void
    {
        unset($_SESSION['_admin_form_tokens'],$_SESSION['_admin_work_drafts']);
        session_regenerate_id(true);
        $now = time();
        $_SESSION[self::SESSION_KEY] = [
            'administrator_id' => $administratorId,
            'authenticated_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->expired = false;
    }

    public function inactivityMinutes(): int
    {
        return (int) ceil($this->inactivityTimeout / 60);
    }

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


    public function isAuthenticated(): bool
    {
        return $this->administratorId() !== null;
    }

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

    public function clearAuthentication(): void
    {
        unset($_SESSION['_admin_form_tokens'],$_SESSION['_admin_work_drafts']);
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public function signOut(): void
    {
        $this->clearAuthentication();
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
        $lastActivity = $_SESSION[self::SESSION_KEY]['last_activity_at'] ?? null;
        if (!is_int($lastActivity) || time() - $lastActivity <= $this->inactivityTimeout) {
            return;
        }

        $this->expired = true;
        unset($_SESSION['_admin_form_tokens'],$_SESSION['_admin_work_drafts']);
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
