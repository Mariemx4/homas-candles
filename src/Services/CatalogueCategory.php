<?php

declare(strict_types=1);

namespace App\Services;

/** Category identifiers and their single storefront route. */
final class CatalogueCategory
{
    // Database slugs that business rules depend on. Rules compare these stable
    // machine identifiers, never translated category names. Use the constants
    // instead of repeating the strings so a search finds every rule for a category.
    public const CANDLES = 'candles';
    public const SETS = 'candle-sets';
    public const DIFFUSERS = 'home-diffusers';
    public const ACCESSORIES = 'accessories';
    public const BOUQUETS = 'bouquets';
    public const EVENT_FAVORS = 'event-favors';

    public static function path(string $slug): string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid category slug.');
        }
        return 'category.php?slug=' . rawurlencode($slug);
    }

    /** @param list<array{slug: string, name: string}> $databaseRows */
    public static function navigation(array $databaseRows): array
    {
        $rows = [];
        foreach ($databaseRows as $row) {
            $rows[] = [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'path' => self::path($row['slug']),
            ];
        }
        return $rows;
    }
}
