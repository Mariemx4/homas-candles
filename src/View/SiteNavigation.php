<?php

declare(strict_types=1);

namespace App\View;

use App\Repositories\ProductRepository;
use App\Services\CatalogueCategory;
use Throwable;

/**
 * Data for the public header's category drawer.
 *
 * Catalogue pages already load their categories and pass them to the header;
 * every other page (account, auth, static pages) gets them from here, so the
 * drawer always follows administrator changes. Kept outside the view so the
 * template itself never opens a database connection.
 */
final class SiteNavigation
{
    /**
     * @return list<array<string, mixed>> the categories shown in the drawer, or [] when the database is unavailable
     */
    public static function categories(string $locale): array
    {
        try {
            require_once APP_PATH . '/config/database.php';

            return CatalogueCategory::navigation(
                (new ProductRepository(database()))->activeCatalogueCategories($locale)
            );
        } catch (Throwable) {
            // Keep static/auth pages usable during a database outage. Their own data
            // boundary reports the failure; the navigation must not invent categories.
            return [];
        }
    }
}
