<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use DomainException;
use Throwable;

/** Reuses Google verification, but accepts only staff-bound state and existing active staff. */
final class AdministratorGoogleController
{
    private const ERROR = 'Google could not sign you in. Use the Google account matching your active administrator email, or sign in with your password.';

    public function __construct(
        private readonly GoogleOidcClient $client,
        private readonly GoogleOAuthSession $google,
        private readonly AdministratorAuthService $auth,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly LoginThrottle $throttle,
        private readonly string $baseUrl
    ) {
    }

    public function start(array $post): never
    {
        $valid = $this->csrf->validate(FormInput::csrfToken($post));
        $this->csrf->rotate();
        [$account, $ip] = $this->buckets();
        if (!$valid || $this->throttle->isBlocked('ADMIN', $account, $ip)) {
            $this->fail();
        }
        try {
            $url = $this->client->authorizationUrl($this->google->begin('admin/login.php', 'administrator'));
        } catch (Throwable) {
            $this->fail();
        }
        header('Location: ' . $url, true, 303);
        exit;
    }

    public function callback(array $query): never
    {
        [$account, $ip] = $this->buckets();
        try {
            $flow = $this->google->consume($query['state'] ?? null, 'administrator');
            if (($query['error'] ?? null) === 'access_denied') {
                $this->fail('Google sign-in was cancelled. You can try again or use your password.');
            }
            if (isset($query['error']) || !is_string($query['code'] ?? null)
                || $this->throttle->isBlocked('ADMIN', $account, $ip)) {
                throw new DomainException('Invalid administrator Google callback.');
            }
            $identity = $this->client->exchange($query['code'], $flow);
            $result = $this->auth->signInWithGoogle($identity);
            if ($result['errors'] !== []) {
                $this->fail();
            }
            $this->throttle->succeeded('ADMIN', $account, $ip);
            $this->csrf->rotate();
            header('Location: ' . $this->baseUrl . 'admin/index.php', true, 303);
            exit;
        } catch (Throwable $error) {
            // Never log claims, authorization codes, secrets, tokens or provider bodies.
            error_log('Administrator Google sign-in rejected (' . get_class($error) . ').');
            $this->throttle->failed('ADMIN', $account, $ip);
            $this->fail();
        }
    }

    private function buckets(): array
    {
        return [$this->throttle->accountBucket(null, 'google-admin-session:' . session_id()),
            $this->throttle->ipBucket($_SERVER['REMOTE_ADDR'] ?? null)];
    }

    private function fail(string $message = self::ERROR): never
    {
        $this->flash->put('administrator_login', ['form' => $message]);
        header('Location: ' . $this->baseUrl . 'admin/login.php', true, 303);
        exit;
    }
}
