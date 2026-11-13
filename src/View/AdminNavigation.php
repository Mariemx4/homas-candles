<?php

declare(strict_types=1);

namespace App\View;

use App\Http\AdminRecordNavigation;

/** Navigation definitions are data; fragments only render these shared entries. */
final class AdminNavigation
{
    public static function catalogueTabs(): array
    {
        $definitions = [
            ['categories', 'Categories', 'tag'],
            ['product_types', 'Product Types', 'settings'],
            ['products', 'Products', 'products'],
            ['scents', 'Scents', 'scent'],
            ['colors', 'Colors', 'palette'],
            ['promotions', 'Promotions', 'tag'],
        ];
        return array_map(static fn (array $tab): array => [
            'key' => $tab[0],
            'label' => $tab[1],
            'icon' => $tab[2],
            'href' => BASE_URL . AdminRecordNavigation::listPath($tab[0]),
        ], $definitions);
    }

    /** Paths are internal application routes, never user-supplied return URLs. */
    public static function sidebarGroups(): array
    {
        return [
            'Daily work' => [
                ['dashboard', 'Today', 'today', 'admin/index.php'],
                ['orders', 'Orders', 'orders', 'admin/orders/index.php'],
                ['requests', 'Custom requests', 'requests', 'admin/requests/index.php'],
                ['production', 'Production', 'production', 'admin/production/queue.php'],
                ['payments', 'Payments', 'payments', 'admin/payments/index.php'],
            ],
            'Catalog & supplies' => [
                ['catalogue', 'Catalog', 'products', AdminRecordNavigation::listPath('products')],
                ['inventory', 'Materials & stock', 'stock', AdminRecordNavigation::listPath('materials')],
            ],
            'Your business' => [
                ['customers', 'Customers', 'customers', 'admin/customers/index.php'],
                ['reports', 'Reports', 'reports', 'admin/reports/index.php'],
            ],
            'Administration' => [
                ['accounts', 'Team & access', 'team', 'admin/accounts.php'],
                ['settings', 'Settings', 'settings', 'admin/settings/index.php'],
            ],
        ];
    }
}
