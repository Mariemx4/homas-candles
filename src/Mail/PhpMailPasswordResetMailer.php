<?php

declare(strict_types=1);

namespace App\Mail;

// WHAT: sends reset codes through PHP's configured mail transport.
// WHY:  application code stays independent of the eventual SMTP/provider setup.
final class PhpMailPasswordResetMailer implements PasswordResetMailer
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The password reset sender address is invalid.');
        }
    }

    public function sendCode(string $recipientEmail, string $code, int $validMinutes): bool
    {
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $subject = "Your Hom'As Candles password reset code";
        $message = "We received a request to reset your Hom'As Candles password.\r\n\r\n"
            . "Your verification code is: {$code}\r\n\r\n"
            . "This code expires in {$validMinutes} minutes. If you did not request it, ignore this email.\r\n";
        $safeName = str_replace(["\r", "\n"], '', $this->fromName);
        $headers = [
            'From: ' . $safeName . ' <' . $this->fromAddress . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        // Never log the code or email body. A false return is logged generically
        // by the service and the public response remains enumeration-safe.
        return @mail($recipientEmail, $subject, $message, implode("\r\n", $headers));
    }
}
