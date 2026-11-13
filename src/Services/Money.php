<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Exact TND arithmetic helpers.
 *
 * PostgreSQL stores amounts and material quantities as DECIMAL(12,3). PHP
 * floats cannot represent every 3-decimal value exactly, so calculations use
 * integer "millis" (1 TND = 1000 millimes, 1 kg = 1000 g) and convert back to a
 * fixed 3-decimal string only for storage or display.
 *
 * Two families of input exist and deliberately behave differently:
 *
 * - parse*()  validates text typed by a person. It is strict (no leading zeros,
 *   at most 3 decimals, at most 9 whole digits) and returns null when invalid so
 *   the caller can show a field error.
 * - stored*() reads a DECIMAL value that PostgreSQL already validated. It is
 *   lenient and returns 0 for unreadable text, matching the earlier private
 *   helpers that every service used to copy.
 *
 * Example: Money::parseMillis('12.5') === 12500; Money::formatMillis(12500) === '12.500'.
 */
final class Money
{
    /** Parse a non-negative form amount such as "12" or "12.345"; null when invalid. */
    public static function parseMillis(string $value): ?int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }
        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * Parse a form amount that may be negative, such as a stock correction "-1.5".
     * Used only where a negative value is a legitimate business input.
     */
    public static function parseSignedMillis(string $value): ?int
    {
        $negative = str_starts_with($value, '-');
        $millis = self::parseMillis($negative ? substr($value, 1) : $value);

        return $millis === null ? null : ($negative ? -$millis : $millis);
    }

    /** Format non-negative millis as "12.345". Callers must not pass negative values. */
    public static function formatMillis(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Format millis that may be negative, e.g. -1500 becomes "-1.500". */
    public static function formatSignedMillis(int $millis): string
    {
        return ($millis < 0 ? '-' : '') . self::formatMillis(abs($millis));
    }

    /** Read a non-negative DECIMAL value from the database; unreadable text counts as 0. */
    public static function storedMillis(string $amount): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $amount, $matches) !== 1) {
            return 0;
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * What is still left of `$total` after `$deducted`, never below zero.
     * Used for remaining balances, deposits still due and material shortfalls.
     * Example: remainingAmount('100.000', '40.500') === '59.500'; overpayment gives '0.000'.
     */
    public static function remainingAmount(string $total, string $deducted): string
    {
        return self::formatMillis(max(0, self::storedMillis($total) - self::storedMillis($deducted)));
    }

    /** `$a - $b` for stored values, keeping the sign (e.g. a negative profit). */
    public static function signedDifference(string $a, string $b): string
    {
        return self::formatSignedMillis(self::storedSignedMillis($a) - self::storedSignedMillis($b));
    }

    /** Read a DECIMAL value that may be negative (recipe differences); unreadable text counts as 0. */
    public static function storedSignedMillis(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $millis = self::storedMillis($negative ? substr($amount, 1) : $amount);

        return $negative ? -$millis : $millis;
    }
}
