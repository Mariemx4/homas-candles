<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Safe readers for submitted form values (`$_POST`, or an array built from it).
 *
 * Every POST field is untrusted: it can be missing, or an array when someone
 * submits `name[]=x`. These readers never throw and never cast an unexpected
 * type into a value; they return '' or null so the caller can show a normal
 * validation error.
 *
 * Validation rules (lengths, allowed values) stay with the controller or
 * service that owns the form. This class only answers "what text, if any, was
 * submitted for this key?".
 *
 *     $id = FormInput::positiveId($post['id'] ?? null);   // null unless "1".."999…"
 *     $name = FormInput::trimmed($post, 'name');          // '' when missing/not text
 *     $valid = $this->csrf->validate(FormInput::csrfToken($post));
 */
final class FormInput
{
    /** The submitted value exactly as typed, or '' when it is missing or not a string. */
    public static function string(array $input, string $key): string
    {
        return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
    }

    /** The submitted value without surrounding whitespace, or ''. */
    public static function trimmed(array $input, string $key): string
    {
        return trim(self::string($input, $key));
    }

    /** The submitted CSRF token, or null so CsrfTokenManager::validate() rejects it. */
    public static function csrfToken(array $input): ?string
    {
        return isset($input['csrf_token']) && is_string($input['csrf_token']) ? $input['csrf_token'] : null;
    }

    /**
     * A database ID from a form or query value: a positive integer written
     * with plain digits only ("12"), never "+12", " 12", "1e3" or "012".
     * Integers already parsed by PHP are accepted when positive.
     */
    public static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        // The pattern allows 19 digits; filter_var rejects values above PHP_INT_MAX.
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }
}
