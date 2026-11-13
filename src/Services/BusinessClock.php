<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calendar dates are Tunisian; existing database timestamps remain UTC. */
final class BusinessClock
{
    public const ZONE = 'Africa/Tunis';

    /** Formats a stored UTC timestamp in Tunisia time for display, or '—' when empty or invalid. */
    public static function displayTimestamp(?string $value, string $format = 'd M Y · H:i'): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::ZONE))->format($format);
        } catch (\Exception) {
            return '—';
        }
    }

    /**
     * Midnight today in Tunisia (Africa/Tunis), the shop's business day.
     *
     * @param DateTimeImmutable|null $now pass a fixed time in tests
     */
    public static function today(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::ZONE))->setTime(0, 0);
    }

    /**
     * Converts an inclusive range of Tunisia business dates to UTC timestamps for SQL.
     *
     * @param string $start first business date, Y-m-d
     * @param string $end last business date, Y-m-d (included)
     * @return array{start: string, end: string} 'start' inclusive, 'end' exclusive (the day after $end)
     * @throws InvalidArgumentException for invalid dates or a reversed range
     */
    public static function utcBounds(string $start, string $end): array
    {
        $zone = new DateTimeZone(self::ZONE);
        $utc = new DateTimeZone('UTC');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $zone);
        if (!$first || !$last || $first->format('Y-m-d') !== $start || $last->format('Y-m-d') !== $end || $first > $last) {
            throw new InvalidArgumentException('Choose valid business dates.');
        }
        return ['start' => $first->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end' => $last->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
