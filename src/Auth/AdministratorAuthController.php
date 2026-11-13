<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use Throwable;

final class AdministratorAuthController
{
    public function __construct(
        private readonly AdministratorAuthService $auth,
        private readonly AdministratorSession $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly string $adminUrl
    ) {
    }

    public function login(array $post): never
    {
        $identifier = FormInput::string($post, 'login_identifier');
        if (!$this->validCsrf($post)) {
            $this->flash->put('administrator_login', ['form' => 'Your form expired. Please try again.'], [
                'login_identifier' => trim($identifier),
            ]);
            $this->redirect('login.php');
        }

        try {
            $result = $this->auth->signIn($identifier, FormInput::string($post, 'password'));
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

    public function logout(array $post): never
    {
        if (!$this->validCsrf($post)) {
            http_response_code(400);
            exit('The sign-out request was invalid.');
        }
        $this->session->signOut();
        $this->redirect('login.php?signed_out=1');
    }

    private function validCsrf(array $post): bool
    {
        $token = FormInput::csrfToken($post);
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        return $valid;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $this->adminUrl . $path, true, 303);
        exit;
    }
}
