<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;

/**
 * Safe readers for admin list filters taken from `$_GET`.
 *
 * Query strings are untrusted: a value can be missing, an array
 * (`?status[]=x`), or text the page never offered. Every reader returns a
 * neutral value instead of failing, and sets `$adjusted = true` whenever it had
 * to discard something. List pages use that flag to tell the administrator
 * "some filters were reset" rather than silently showing different results.
 *
 * `$adjusted` is passed by reference so one flag can collect corrections from
 * several fields:
 *
 *     $adjusted = false;
 *     $status = ListQuery::allowed($query, 'status', ['NEW', 'PAID'], $adjusted);
 *     $from = ListQuery::date($query, 'date_from', $adjusted);
 *
 * Which keys exist, their allowed values and the resulting pagination state
 * stay in each list's service, because those are that feature's rules.
 */
final class ListQuery
{
    /** Return the raw string value, or '' when it is absent or not a string. */
    public static function string(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }

    /**
     * Return the value only when it is one of `$allowed` (exact, case-sensitive).
     *
     * @param list<string> $allowed
     */
    public static function allowed(array $query, string $key, array $allowed, bool &$adjusted): ?string
    {
        $value = self::string($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            $adjusted = true;

            return null;
        }

        return $value;
    }

    /** Return a real calendar date in `Y-m-d` form; impossible dates such as 2026-02-30 are rejected. */
    public static function date(array $query, string $key, bool &$adjusted): ?string
    {
        $value = self::string($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $adjusted = true;

            return null;
        }

        return $value;
    }
}
