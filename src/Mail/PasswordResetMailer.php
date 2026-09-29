<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Mail boundary for Password Reset Mailer.
 *
 * DATA FLOW: Application services call this abstraction or implementation when a use case needs email, keeping delivery details out of controllers and views.
 */

namespace App\Mail;

interface PasswordResetMailer
{
    /**
     * FUNCTION PURPOSE: Apply the operation represented by `sendCode` (send code) and return its result.
     */
    public function sendCode(string $recipientEmail, string $code, int $validMinutes): bool;
}
