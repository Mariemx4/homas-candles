<?php

declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;

/** Only GoogleOidcClient creates this from network data after full token verification. */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $hostedDomain = null
    ) {
        if ($subject === '' || strlen($subject) > 255 || preg_match('/^[A-Za-z0-9_-]+$/D', $subject) !== 1
            || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid verified Google identity.');
        }
        if ($hostedDomain !== null && ($hostedDomain === '' || strlen($hostedDomain) > 253
            || filter_var($hostedDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new InvalidArgumentException('Invalid verified Google hosted domain.');
        }
    }

    /**
     * Google controls Gmail and the Workspace domain asserted in its signed hd
     * claim. A verified third-party email alone does not prove current ownership;
     * those customers must still authenticate locally before connecting Google.
     */
    public function ownsEmailAddress(): bool
    {
        return str_ends_with(strtolower($this->email), '@gmail.com') || $this->hostedDomain !== null;
    }

    /** Safe, bounded pending-registration data; tokens and secrets never enter the session. */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'email' => $this->email,
            'first_name' => mb_substr($this->firstName, 0, 100), 'last_name' => mb_substr($this->lastName, 0, 100),
            'hosted_domain' => $this->hostedDomain];
    }
}
