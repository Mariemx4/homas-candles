<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Small text rules shared by form validation.
 *
 * Database limits such as VARCHAR(180) count characters, not bytes. Arabic and
 * accented French letters use several UTF-8 bytes each, so strlen() would reject
 * valid names early. Validators therefore measure with Text::length().
 */
final class Text
{
    /** Count user-visible UTF-8 characters, falling back to bytes only when mbstring is absent. */
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
