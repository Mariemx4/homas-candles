<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Administrator Auth Controller.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, 
 * manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

use App\Security\CsrfTokenManager;
use Throwable;

final class AdministratorAuthController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AdministratorAuthService $auth,
        private readonly AdministratorSession $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly string $adminUrl
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `login` (login) and return its result.
     */
    public function login(array $post): never
    {
        $identifier = $this->stringValue($post, 'login_identifier');
        if (!$this->validCsrf($post)) {
            $this->flash->put('administrator_login', ['form' => 'Your form expired. Please try again.'], [
                'login_identifier' => trim($identifier),
            ]);
            $this->redirect('login.php');
        }

        try {
            $result = $this->auth->signIn($identifier, $this->stringValue($post, 'password'));
        } catch (Throwable) {
            error_log('Administrator login failed because an internal dependency was unavailable.');
            $result = ['errors' => ['form' => 'Sign-in is temporarily unavailable. Please try again.']];
        }
        if ($result['errors'] !== []) {
            $this->flash->put('administrator_login', $result['errors'], [
                'login_identifier' => trim($identifier),
            ]);
            $this->redirect('login.php');
        }

        $this->redirect('index.php');
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `logout` (logout) and return its result.
     */
    public function logout(array $post): never
    {
        if (!$this->validCsrf($post)) {
            http_response_code(400);
            exit('The sign-out request was invalid.');
        }
        $this->session->signOut();
        $this->redirect('login.php?signed_out=1');
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `validCsrf` (valid csrf) and return its result.
     */
    private function validCsrf(array $post): bool
    {
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        return $valid;
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
        header('Location: ' . $this->adminUrl . $path, true, 303);
        exit;
    }
}
