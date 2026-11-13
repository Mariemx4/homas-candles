<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/** Keep old bookmarks working without duplicating category-page logic. */
final class LegacyCategoryRedirect
{
    private const DESTINATIONS = [
        'candleSets.php' => 'candle-sets.php',
        'candleBouquets.php' => 'candle-bouquets.php',
        'eventFavors.php' => 'event-favors.php',
        'homeDiffusers.php' => 'home-diffusers.php',
    ];

    public static function send(string $legacyFile): never
    {
        $location = self::DESTINATIONS[$legacyFile]
            ?? throw new InvalidArgumentException('Unknown legacy category route.');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($query !== '') {
            $location .= '?' . $query;
        }
        header('Location: ' . $location, true, 301);
        exit;
    }
}
