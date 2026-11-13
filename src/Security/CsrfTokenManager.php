<?php

declare(strict_types=1);

namespace App\Security;

// WHAT: creates and checks unpredictable tokens for forms that change state.
// WHY:  a same-site attacker cannot submit login/signup/logout on a customer's
//       behalf without also knowing the random value stored in this session.
final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';
    private const ADMIN_KEY = '_admin_form_tokens';
    private ?string $issuedToken = null;
    private ?string $validatedToken = null;

    public function __construct(private readonly bool $independentForms = false)
    {
    }

    public function token(): string
    {
        if ($this->independentForms) {
            $this->prune();
            if ($this->issuedToken === null || !isset($_SESSION[self::ADMIN_KEY][$this->issuedToken])) {
                $this->issuedToken = bin2hex(random_bytes(32));
                $_SESSION[self::ADMIN_KEY][$this->issuedToken] = time();
            }
            return $this->issuedToken;
        }
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }


    public function validate(?string $submittedToken): bool
    {
        if ($this->independentForms) {
            $this->prune();
            $valid = is_string($submittedToken) && isset($_SESSION[self::ADMIN_KEY][$submittedToken]);
            $this->validatedToken = $valid ? $submittedToken : null;
            return $valid;
        }
        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($submittedToken)
            && is_string($storedToken)
            && hash_equals($storedToken, $submittedToken);
    }

    public function rotate(): void
    {
        if ($this->independentForms) {
            // Consume only the submitted form. Other tabs keep their own tokens.
            if ($this->validatedToken !== null) {
                unset($_SESSION[self::ADMIN_KEY][$this->validatedToken]);
            }
            $this->issuedToken = $this->validatedToken = null;
            return;
        }
        unset($_SESSION[self::SESSION_KEY]);
        $this->token();
    }

    private function prune(): void
    {
        $tokens = $_SESSION[self::ADMIN_KEY] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }
        foreach ($tokens as $token => $created) {
            if (!is_int($created) || $created < time() - 10800) {
                unset($tokens[$token]);
            }
        }
        // Bound session storage while retaining a generous number of open pages.
        $_SESSION[self::ADMIN_KEY] = array_slice($tokens, -512, null, true);
    }
}
