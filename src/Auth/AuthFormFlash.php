<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Auth Form Flash.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

// WHAT: carries validation errors and safe field values across one redirect.
// WHY:  POST/Redirect/GET prevents accidental resubmission on refresh, while
//       this small session-backed message survives just long enough for the GET.
final class AuthFormFlash
{
    private const SESSION_KEY = '_auth_form_flash';

    public function put(string $form, array $errors, array $values = [], ?string $success = null): void
    {
        $_SESSION[self::SESSION_KEY][$form] = [
            'errors' => $errors,
            'values' => $values,
            'success' => $success,
        ];
    }

    /**
     * @return array{errors: array, values: array, success: ?string}
     */
    public function consume(string $form): array
    {
        $state = $_SESSION[self::SESSION_KEY][$form] ?? [];
        unset($_SESSION[self::SESSION_KEY][$form]);

        return [
            'errors' => is_array($state['errors'] ?? null) ? $state['errors'] : [],
            'values' => is_array($state['values'] ?? null) ? $state['values'] : [],
            'success' => is_string($state['success'] ?? null) ? $state['success'] : null,
        ];
    }
}
