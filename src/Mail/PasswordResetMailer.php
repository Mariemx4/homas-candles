<?php

declare(strict_types=1);

namespace App\Mail;

interface PasswordResetMailer
{
    public function sendCode(string $recipientEmail, string $code, int $validMinutes): bool;
}
