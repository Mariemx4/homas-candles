<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\Locale;
use DomainException;
use Throwable;

/** HTTP/session coordinator. Tokens stay in the verifier and never reach a template. */
final class GoogleSignInController
{
    public function __construct(
        private readonly GoogleOidcClient $client,
        private readonly GoogleOAuthSession $google,
        private readonly GoogleCustomerService $customers,
        private readonly CustomerSession $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly LoginThrottle $throttle,
        private readonly string $baseUrl
    ) {
    }

    public function start(array $post): never
    {
        $origin = ($post['origin'] ?? '') === 'signup.php' ? 'signup.php' : 'login.php';
        if (!$this->validCsrf($post, false)) {
            $this->fail('google.expired', $origin);
        }
        [$account, $ip] = $this->buckets();
        if ($this->throttle->isBlocked('CUSTOMER', $account, $ip)) {
            $this->fail('google.failed', $origin);
        }
        try {
            $url = $this->client->authorizationUrl($this->google->begin($origin));
        } catch (Throwable) {
            $this->fail('google.unavailable', $origin);
        }
        // The destination comes exclusively from Google's fixed authorization endpoint.
        header('Location: ' . $url, true, 303);
        exit;
    }

    public function callback(array $query): never
    {
        [$account, $ip] = $this->buckets();
        $origin = 'login.php';
        try {
            $flow = $this->google->consume($query['state'] ?? null);
            $origin = $flow['origin'];
            if (($query['error'] ?? null) === 'access_denied') {
                $this->fail('google.cancelled', $origin);
            }
            if (isset($query['error']) || !is_string($query['code'] ?? null)
                || $this->throttle->isBlocked('CUSTOMER', $account, $ip)) {
                throw new DomainException('Invalid Google callback.');
            }
            $identity = $this->client->exchange($query['code'], $flow);
            $result = $this->customers->resolve($identity);
            $this->throttle->succeeded('CUSTOMER', $account, $ip);
            if (isset($result['customer_id'])) {
                $this->finish((int) $result['customer_id']);
            }
            $existing = $result['link_customer_id'] ?? null;
            $this->google->remember($identity, $existing);
            if ($existing !== null) {
                $this->requestLinkLogin($identity, (int) $existing);
            }
            $this->redirect('auth/google-complete.php');
        } catch (Throwable $error) {
            // Do not log authorization codes, ID tokens, claims or provider responses.
            error_log('Google callback rejected (' . get_class($error) . ').');
            $this->throttle->failed('CUSTOMER', $account, $ip);
            $this->fail('google.failed', $origin);
        }
    }

    public function completion(array $post): never
    {
        $pending = $this->google->pending();
        if ($pending === null) {
            $this->fail('google.expired');
        }
        if ($pending['existing_customer'] !== null) {
            $this->requestLinkLogin($pending['identity'], $pending['existing_customer']);
        }
        $safe = [];
        foreach (['first_name', 'last_name', 'phone_number', 'address_label', 'street_address', 'governorate', 'delegation', 'postal_code'] as $key) {
            $safe[$key] = is_string($post[$key] ?? null) ? mb_substr($post[$key], 0, 512) : '';
        }
        if (!$this->validCsrf($post)) {
            $result = ['errors' => ['form' => 'google.expired']];
        } else {
            try {
                $result = $this->customers->register($pending['identity'], $safe);
            } catch (Throwable $error) {
                error_log('Google registration rejected (' . get_class($error) . ').');
                $result = ['errors' => ['form' => 'google.failed']];
            }
        }
        if ($result['errors'] !== []) {
            $this->flash->put('google_complete', $result['errors'], $safe);
            $this->redirect('auth/google-complete.php');
        }
        if (isset($result['link_customer_id'])) {
            $this->google->remember($pending['identity'], (int) $result['link_customer_id']);
            $this->requestLinkLogin($pending['identity'], (int) $result['link_customer_id']);
        }
        $this->finish((int) $result['customer_id']);
    }

    public function confirmLink(int $customer, array $post): never
    {
        $pending = $this->google->pending();
        if (!$this->validCsrf($post) || $pending === null || $pending['existing_customer'] !== $customer
            || ($post['confirm_link'] ?? '') !== '1') {
            $this->fail('google.link_failed');
        }
        try {
            $this->customers->link($customer, $pending['identity']);
        } catch (Throwable $error) {
            error_log('Google account linking rejected (' . get_class($error) . ').');
            $this->fail('google.link_failed');
        }
        $this->flash->put('customer_profile', [], [], Locale::text('google.linked'));
        $this->finish($customer, 'profile.php');
    }

    private function requestLinkLogin(GoogleIdentity $identity, int $existing): never
    {
        if ($this->session->customerId() === $existing) {
            $this->redirect('auth/google-link.php');
        }
        $this->flash->put('login', ['form' => Locale::text('google.link_required')], ['login_identifier' => $identity->email]);
        $this->redirect('login.php');
    }

    private function finish(int $customer, string $destination = 'account.php'): never
    {
        $this->google->clear();
        $this->session->signIn($customer);
        $this->csrf->rotate();
        $this->redirect($destination);
    }

    private function validCsrf(array $post, bool $rotate = true): bool
    {
        $valid = $this->csrf->validate(FormInput::csrfToken($post));
        // Starting OAuth is not authentication. Keep the password form usable
        // if the customer cancels Google or returns with the browser Back button.
        if ($rotate) {
            $this->csrf->rotate();
        }
        return $valid;
    }

    private function buckets(): array
    {
        return [$this->throttle->accountBucket(null, 'google-session:' . session_id()),
            $this->throttle->ipBucket($_SERVER['REMOTE_ADDR'] ?? null)];
    }

    private function fail(string $message, string $origin = 'login.php'): never
    {
        $this->flash->put($origin === 'signup.php' ? 'signup' : 'login', ['form' => Locale::text($message)]);
        $this->redirect($origin);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $this->baseUrl . $path, true, 303);
        exit;
    }
}
