<?php

declare(strict_types=1);

namespace App\Services;

/** DECIMAL(12,3) amounts used by orders; calculations use integer millimes. */
final class Money
{
    public static function parseMillis(string $value): ?int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }
        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    public static function formatMillis(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
