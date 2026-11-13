<?php

declare(strict_types=1);

namespace App\Security;

use Closure;

// WHAT: cheap, session-based checks that stop automated form spam.
// WHY:  CSRF alone proves the form came from our page, not that a human sent
//       it. These checks add three signals a simple bot fails:
//         1. a hidden "honeypot" field that people never see or fill in;
//         2. a minimum time between rendering and submitting the form;
//         3. a cap on submissions per session within a time window.
//       Database-wide limits (per phone, site-wide) live in
//       ContactRequestService because they need the repository.
final class ContactAbuseGuard
{
    public const HONEYPOT_FIELD = 'website';
    private const STAMP_KEY = 'contact_form_rendered_at';
    private const HISTORY_KEY = 'contact_submissions';

    public const PASS = 'pass';
    public const BOT = 'bot';
    public const TOO_FAST = 'too_fast';
    public const RATE_LIMITED = 'rate_limited';

    private readonly Closure $clock;


    public function __construct(private readonly array $settings, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }


    public function stampForm(): void
    {
        $_SESSION[self::STAMP_KEY] = ($this->clock)();
    }


    public function inspect(array $input): string
    {
        // A filled honeypot is decisive; the page shows a normal success
        // message so the bot learns nothing about why it was ignored.
        $honeypot = $input[self::HONEYPOT_FIELD] ?? '';
        if (!is_string($honeypot) || trim($honeypot) !== '') {
            return self::BOT;
        }

        $now = ($this->clock)();
        $renderedAt = $_SESSION[self::STAMP_KEY] ?? null;
        if (!is_int($renderedAt) || $now - $renderedAt < $this->settings['minimum_fill_seconds']) {
            return self::TOO_FAST;
        }

        if (count($this->recentSubmissions($now)) >= $this->settings['session_limit']) {
            return self::RATE_LIMITED;
        }

        return self::PASS;
    }


    public function recordSubmission(): void
    {
        $now = ($this->clock)();
        $history = $this->recentSubmissions($now);
        $history[] = $now;
        $_SESSION[self::HISTORY_KEY] = $history;
        // A new stamp is needed for the next message, so a replayed POST is "too fast".
        unset($_SESSION[self::STAMP_KEY]);
    }


    private function recentSubmissions(int $now): array
    {
        $history = is_array($_SESSION[self::HISTORY_KEY] ?? null) ? $_SESSION[self::HISTORY_KEY] : [];
        $cutoff = $now - $this->settings['session_window_seconds'];

        return array_values(array_filter(
            $history,
            static fn (mixed $time): bool => is_int($time) && $time > $cutoff
        ));
    }
}
