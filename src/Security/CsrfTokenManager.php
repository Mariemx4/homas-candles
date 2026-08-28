<?php

declare(strict_types=1);

namespace App\Security;

// WHAT: creates and checks unpredictable tokens for forms that change state.
// WHY:  a same-site attacker cannot submit login/signup/logout on a customer's
//       behalf without also knowing the random value stored in this session.
final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function validate(?string $submittedToken): bool
    {
        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($submittedToken)
            && is_string($storedToken)
            && hash_equals($storedToken, $submittedToken);
    }

    public function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        $this->token();
    }
}
