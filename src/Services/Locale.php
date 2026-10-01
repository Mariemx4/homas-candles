<?php

declare(strict_types=1);

namespace App\Services;

final class Locale
{
    public const SUPPORTED = ['en', 'fr', 'ar'];
    private const COOKIE = 'homas_language';
    private static ?string $current = null;
    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];

    /** @param array<string, mixed> $query @param array<string, mixed> $cookies */
    public static function resolve(array $query, array $cookies, string $acceptLanguage = ''): string
    {
        foreach ([$query['lang'] ?? null, $cookies[self::COOKIE] ?? null] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }
        $preferences = [];
        foreach (explode(',', $acceptLanguage) as $entry) {
            if (preg_match('/^\s*([a-z]{2})(?:-[a-z0-9-]+)?(?:\s*;\s*q\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?))?\s*$/i', $entry, $match) !== 1) {
                continue;
            }
            $language = strtolower($match[1]);
            $quality = isset($match[2]) ? (float) $match[2] : 1.0;
            if ($quality > 0 && in_array($language, self::SUPPORTED, true)) {
                $preferences[$language] = max($preferences[$language] ?? 0.0, $quality);
            }
        }
        arsort($preferences, SORT_NUMERIC);
        if ($preferences !== []) {
            return (string) array_key_first($preferences);
        }
        return 'en';
    }

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $query = $_GET ?? [];
        $cookies = $_COOKIE ?? [];
        $selected = $query['lang'] ?? null;
        self::$current = self::resolve($query, $cookies, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if (PHP_SAPI !== 'cli' && is_string($selected) && in_array($selected, self::SUPPORTED, true)) {
            $path = defined('BASE_URL') ? (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '/') : '/';
            setcookie(self::COOKIE, $selected, [
                'expires' => time() + 31536000,
                'path' => $path,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return self::$current;
    }

    public static function text(string $key, ?string $language = null): string
    {
        $language ??= self::current();
        if (!in_array($language, self::SUPPORTED, true)) {
            $language = 'en';
        }
        foreach (array_unique([$language, 'en']) as $candidate) {
            if (!isset(self::$dictionaries[$candidate])) {
                self::$dictionaries[$candidate] = require APP_PATH . '/translations/' . $candidate . '.php';
            }
            if (isset(self::$dictionaries[$candidate][$key])) {
                return self::$dictionaries[$candidate][$key];
            }
        }
        return $key;
    }
}
