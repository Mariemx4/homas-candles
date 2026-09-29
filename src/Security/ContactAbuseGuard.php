<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Security component for Contact Abuse Guard.
 *
 * DATA FLOW: public/contact.php stamps the form when it is rendered and asks
 * this guard to inspect each submission before ContactRequestService saves
 * anything. State lives in the PHP session, alongside the CSRF token.
 */

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

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     *
     * @param array<string, int> $settings Values from app/config/contact.php.
     */
    public function __construct(private readonly array $settings, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /**
     * FUNCTION PURPOSE: Remember when the form was shown; call it on every GET render.
     */
    public function stampForm(): void
    {
        $_SESSION[self::STAMP_KEY] = ($this->clock)();
    }

    /**
     * FUNCTION PURPOSE: Classify one submission as PASS, BOT, TOO_FAST, or RATE_LIMITED.
     */
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

    /**
     * FUNCTION PURPOSE: Count one accepted submission against this session's limit.
     */
    public function recordSubmission(): void
    {
        $now = ($this->clock)();
        $history = $this->recentSubmissions($now);
        $history[] = $now;
        $_SESSION[self::HISTORY_KEY] = $history;
        // A new stamp is needed for the next message, so a replayed POST is "too fast".
        unset($_SESSION[self::STAMP_KEY]);
    }

    /**
     * FUNCTION PURPOSE: Return this session's submission times that are still inside the window.
     *
     * @return list<int>
     */
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
