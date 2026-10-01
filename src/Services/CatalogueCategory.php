<?php

declare(strict_types=1);

namespace App\Services;

/** Keep historical public URLs while database slugs remain the domain identity. */
final class CatalogueCategory
{
    private const PUBLIC_ALIASES = ['bouquets' => 'candle-bouquets'];

    public static function databaseSlug(string $publicSlug): string
    {
        $databaseSlug = array_search($publicSlug, self::PUBLIC_ALIASES, true);
        return $databaseSlug === false ? $publicSlug : $databaseSlug;
    }

    public static function publicSlug(string $databaseSlug): string
    {
        return self::PUBLIC_ALIASES[$databaseSlug] ?? $databaseSlug;
    }

    public static function path(string $databaseSlug): string
    {
        $publicSlug = self::publicSlug($databaseSlug);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $publicSlug) !== 1) {
            throw new \InvalidArgumentException('Invalid category slug.');
        }
        $legacy = 'categories/' . $publicSlug . '.php';
        return is_file(PUBLIC_PATH . '/' . $legacy)
            ? $legacy
            : 'category.php?slug=' . rawurlencode($databaseSlug);
    }

    /** @param list<array{slug: string, name: string}> $databaseRows */
    public static function navigation(array $databaseRows): array
    {
        $rows = [];
        foreach ($databaseRows as $row) {
            $rows[] = [
                'database_slug' => $row['slug'],
                'slug' => self::publicSlug($row['slug']),
                'name' => $row['name'],
                'path' => self::path($row['slug']),
            ];
        }
        return $rows;
    }
}
