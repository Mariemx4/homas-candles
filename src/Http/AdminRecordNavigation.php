<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/**
 * Canonical destinations after record management. Only known internal routes
 * are emitted; request URLs and Referer headers are never redirect targets.
 */
final class AdminRecordNavigation
{
    private const RECORDS = [
        'products' => ['Product', 'catalogue/products.php', 'id'],
        'materials' => ['Material', 'inventory/materials.php', 'id'],
        'categories' => ['Category', 'catalogue/categories.php', 'id'],
        'product_types' => ['Product type', 'catalogue/product-types.php', 'id'],
        'colors' => ['Color', 'catalogue/colors.php', 'id'],
        'scents' => ['Scent', 'catalogue/scents.php', 'id'],
        'promotions' => ['Promotion', 'catalogue/promotions.php', 'edit'],
        'customers' => ['Customer', 'customers/detail.php', 'id'],
        'administrators' => ['Administrator', 'accounts.php', 'id'],
        'requests' => ['Request', 'requests/detail.php', 'id'],
    ];

    public static function label(string $kind): string
    {
        return self::definition($kind)[0];
    }

    public static function listPath(string $kind): string
    {
        $route = match ($kind) {
            'customers' => 'customers/index.php',
            'requests' => 'requests/index.php',
            default => self::definition($kind)[1],
        };
        return 'admin/' . $route;
    }

    public static function recordPath(string $kind, int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A positive record identifier is required.');
        }
        [, $route, $parameter] = self::definition($kind);
        return 'admin/' . $route . '?' . http_build_query([$parameter => $id])
            . ($kind === 'promotions' ? '#promotion-editor' : '');
    }

    private static function definition(string $kind): array
    {
        return self::RECORDS[$kind] ?? throw new InvalidArgumentException('Unknown record kind.');
    }
}
