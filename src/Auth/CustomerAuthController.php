<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Customer Auth Controller.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

use App\Security\CsrfTokenManager;
use Throwable;

// WHAT: the thin HTTP layer for login, signup, and logout POST requests.
// WHY:  it handles CSRF/redirect/flash mechanics, leaving validation and SQL
//       to the service and repository where those responsibilities belong.
final class CustomerAuthController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly CustomerSession $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly string $baseUrl
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `login` (login) and return its result.
     */
    public function login(array $post): never
    {
        $identifier = $this->stringValue($post, 'login_identifier');

        if (!$this->validCsrf($post)) {
            $this->flash->put('login', ['form' => 'Your form expired. Please try again.'], [
                'login_identifier' => $identifier,
            ]);
            $this->redirect('login.php');
        }

        try {
            $result = $this->auth->signIn($identifier, $this->stringValue($post, 'password'));
        } catch (Throwable) {
            error_log('Customer login failed because an internal dependency was unavailable.');
            $result = ['errors' => ['form' => 'Sign-in is temporarily unavailable. Please try again.']];
        }

        if ($result['errors'] !== []) {
            $this->flash->put('login', $result['errors'], [
                'login_identifier' => trim($identifier),
            ]);
            $this->redirect('login.php');
        }

        $this->redirect('index.php');
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `signup` (signup) and return its result.
     */
    public function signup(array $post): never
    {
        $safeValues = $this->safeSignupValues($post);

        if (!$this->validCsrf($post)) {
            $this->flash->put('signup', ['form' => 'Your form expired. Please try again.'], $safeValues);
            $this->redirect('signup.php');
        }

        try {
            $result = $this->auth->register($post);
        } catch (Throwable) {
            error_log('Customer signup failed because an internal dependency was unavailable.');
            $result = ['errors' => ['form' => 'Account creation is temporarily unavailable. Please try again.']];
        }

        if ($result['errors'] !== []) {
            $this->flash->put('signup', $result['errors'], $safeValues);
            $this->redirect('signup.php');
        }

        $this->flash->put('login', [], [], 'Your account is ready. Sign in with your email or phone number.');
        $this->redirect('login.php');
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `logout` (logout) and return its result.
     */
    public function logout(array $post): never
    {
        if (!$this->validCsrf($post)) {
            http_response_code(400);
            exit('The sign-out request was invalid. Please return to the site and try again.');
        }

        $this->session->signOut();
        $this->redirect('login.php?signed_out=1');
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `validCsrf` (valid csrf) and return its result.
     */
    private function validCsrf(array $post): bool
    {
        $submittedToken = isset($post['csrf_token']) && is_string($post['csrf_token'])
            ? $post['csrf_token']
            : null;
        $isValid = $this->csrf->validate($submittedToken);
        $this->csrf->rotate();
        return $isValid;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `safeSignupValues` (safe signup values) and return its result.
     */
    private function safeSignupValues(array $post): array
    {
        // Password fields are deliberately absent: flash data is server-side
        // session data too, so plain passwords never belong here.
        $safeKeys = [
            'first_name', 'last_name', 'email', 'phone_number', 'second_phone_number', 'address_label',
            'street_address', 'governorate', 'delegation', 'postal_code',
        ];
        $safeValues = [];

        foreach ($safeKeys as $key) {
            $safeValues[$key] = $this->stringValue($post, $key);
        }

        return $safeValues;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `stringValue` (string value) and return its result.
     */
    private function stringValue(array $input, string $key): string
    {
        return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `redirect` (redirect) and return its result.
     */
    private function redirect(string $path): never
    {
        // Only hard-coded, application-relative destinations reach this method;
        // user input can never become an external redirect URL.
        header('Location: ' . $this->baseUrl . $path, true, 303);
        exit;
    }
}
