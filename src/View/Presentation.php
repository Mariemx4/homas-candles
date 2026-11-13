<?php

declare(strict_types=1);

namespace App\View;

use App\Services\BusinessClock;
use DateTimeImmutable;
use Throwable;

/**
 * Display-only conversions shared by views. Services still own prices, dates
 * and validation. Keeping formatting here prevents one screen from silently
 * presenting the same stored value differently from another screen.
 */
final class Presentation
{
    /** Escape untrusted text at the HTML boundary, never before database writes. */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Preserve the existing three-decimal TND display and each view's null label. */
    public static function money(mixed $value, ?string $nullLabel = null): string
    {
        if ($value === null && $nullLabel !== null) {
            return $nullLabel;
        }
        return number_format((float) $value, 3) . ' TND';
    }

    public static function humanize(mixed $value): string
    {
        return ucwords(strtolower(str_replace('_', ' ', (string) $value)));
    }

    /** Calendar-only values must not acquire a timezone or shift a day. */
    public static function date(mixed $value, string $fallback = 'Not set'): string
    {
        if (!is_string($value) || $value === '') {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date instanceof DateTimeImmutable ? $date->format('d M Y') : $fallback;
    }

    /** Stored timestamps are UTC; BusinessClock renders the Tunisia business time. */
    public static function timestamp(mixed $value, string $missing = 'Not recorded', ?string $invalid = null): string
    {
        if (!is_string($value) || $value === '') {
            return $missing;
        }
        try {
            return BusinessClock::displayTimestamp($value, 'd M Y, H:i');
        } catch (Throwable) {
            return $invalid ?? $missing;
        }
    }
}
