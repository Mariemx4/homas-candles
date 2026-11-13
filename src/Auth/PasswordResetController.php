<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use Throwable;

final class PasswordResetController
{
    private const CONTEXT_KEY = '_password_reset_email';


    public function __construct(
        private readonly PasswordResetService $service,
        private readonly CsrfTokenManager $csrf,
        private readonly AuthFormFlash $flash,
        private readonly string $baseUrl
    ) {
    }

    public function requestCode(array $post): never
    {
        $email = FormInput::string($post, 'email');
        if (!$this->validCsrf($post)) {
            $this->flash->put('forgot-password', ['form' => 'Your form expired. Please try again.'], ['email' => $email]);
            $this->redirect('forgot-password.php');
        }

        try {
            $result = $this->service->requestCode($email);
        } catch (Throwable) {
            error_log('Password reset request failed internally.');
            $result = ['errors' => ['form' => 'Password reset is temporarily unavailable. Please try again.']];
        }
        if ($result['errors'] !== []) {
            $this->flash->put('forgot-password', $result['errors'], ['email' => trim($email)]);
            $this->redirect('forgot-password.php');
        }

        $_SESSION[self::CONTEXT_KEY] = $result['email'];
        $this->flash->put('reset-password', [], [], PasswordResetService::REQUEST_MESSAGE);
        $this->redirect('reset-password.php');
    }

    public function reset(array $post): never
    {
        $email = $_SESSION[self::CONTEXT_KEY] ?? null;
        if (!is_string($email) || $email === '') {
            $this->flash->put('forgot-password', ['form' => 'Request a new verification code first.']);
            $this->redirect('forgot-password.php');
        }
        if (!$this->validCsrf($post)) {
            $this->flash->put('reset-password', ['form' => 'Your form expired. Please try again.']);
            $this->redirect('reset-password.php');
        }

        try {
            $result = $this->service->resetPassword(
                $email,
                $this->verificationCode($post),
                FormInput::string($post, 'password'),
                FormInput::string($post, 'password_confirmation')
            );
        } catch (Throwable) {
            error_log('Password reset completion failed internally.');
            $result = ['errors' => ['form' => 'Password reset is temporarily unavailable. Please try again.']];
        }
        if ($result['errors'] !== []) {
            $this->flash->put('reset-password', $result['errors']);
            $this->redirect('reset-password.php');
        }

        unset($_SESSION[self::CONTEXT_KEY]);
        $this->flash->put('login', [], [], 'Your password has been reset. You can sign in now.');
        $this->redirect('login.php');
    }

    public function resendCode(array $post): never
    {
        $email = $_SESSION[self::CONTEXT_KEY] ?? null;
        if (!is_string($email) || $email === '') {
            $this->flash->put('forgot-password', ['form' => 'Enter your account email first.']);
            $this->redirect('forgot-password.php');
        }
        if (!$this->validCsrf($post)) {
            $this->flash->put('reset-password', ['form' => 'Your resend request expired. Please try again.']);
            $this->redirect('reset-password.php');
        }

        try {
            $result = $this->service->requestCode($email);
        } catch (Throwable) {
            error_log('Password reset code resend failed internally.');
            $result = ['errors' => ['form' => 'We could not send another code right now. Please try again.']];
        }

        if ($result['errors'] !== []) {
            $this->flash->put('reset-password', $result['errors']);
            $this->redirect('reset-password.php');
        }

        $this->flash->put('reset-password', [], [], 'A new verification code has been sent to your email address.');
        $this->redirect('reset-password.php');
    }

    private function validCsrf(array $post): bool
    {
        $token = FormInput::csrfToken($post);
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        return $valid;
    }

    private function verificationCode(array $input): string
    {
        // WHAT: join the six visible mini inputs into the service's one code.
        // WHY:  this also works without JavaScript, so the visual component
        //       never becomes the only way the server can receive the code.
        $digits = '';
        for ($position = 1; $position <= 6; $position++) {
            $digits .= FormInput::string($input, 'code_digit_' . $position);
        }
        return $digits;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $this->baseUrl . $path, true, 303);
        exit;
    }
}
