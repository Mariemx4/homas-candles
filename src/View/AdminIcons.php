<?php

declare(strict_types=1);

namespace App\View;

/** Local decorative SVGs. The surrounding control must supply its accessible name. */
final class AdminIcons
{
    private const PATHS = [
        'today' => '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4m8-4v4M3 10h18m-13 5h3m3 0h2"/>',
        'orders' => '<path d="M7 3h10l3 4v14H4V7l3-4Z M4 7h16M9 12h6m-6 4h4"/>',
        'requests' => '<path d="M21 11a8 8 0 0 1-8 8H7l-5 3 2-6a8 8 0 0 1-1-5 9 9 0 0 1 18 0Z"/><path d="M8 10h8m-8 4h5"/>',
        'payments' => '<rect x="2" y="5" width="20" height="15" rx="3"/><path d="M2 10h20M6 15h4"/>',
        'products' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Z M3 7l9 5 9-5m-9 5v10M7 4.8l10 5.5"/>',
        'stock' => '<path d="M3 10h18v11H3V10ZM5 3h14v7M8 14h8m-6 3h4"/>',
        'production' => '<path d="M4 21V8l6 4V8l6 4V3h4v18H4Zm4-4h1m3 0h1m3 0h1"/>',
        'customers' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2m2-18a4 4 0 0 1 0 8m2 10v-2a7 7 0 0 0-3-6"/>',
        'reports' => '<path d="M4 3v18h17M8 16v-5m5 5V6m5 10v-8"/>',
        'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="3"/><circle cx="16" cy="17" r="3"/>',
        'team' => '<circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2M12 2v2m8 6h2M2 10h2"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'store' => '<path d="M3 10h18l-2-7H5l-2 7Zm1 0v11h16V10M9 21v-7h6v7"/>',
        'logout' => '<path d="M9 3H4v18h5m5-14 5 5-5 5m-7-5h12"/>',
        'edit' => '<path d="m16 3 5 5M4 20l5-1L21 7l-5-5L4 14l-1 7Z"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'archive' => '<rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v14h14V7m-10 5h6"/>',
        'back' => '<path d="M19 12H5m5-5-5 5 5 5"/>',
        'search' => '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        'filter' => '<path d="M4 5h16M7 12h10m-7 7h4"/>',
        'save' => '<path d="M4 3h13l4 4v14H3V3h1Zm3 0v6h10V3M7 21v-8h10v8"/>',
        'photo' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="2"/><path d="m3 17 5-5 4 4 4-6 5 7"/>',
        'history' => '<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-4v6l4 2"/>',
        'language' => '<path d="M3 5h12M9 2v3m4 0c0 6-4 10-9 12m1-9c1 4 4 7 8 9m1 4 4-11 4 11m-7-3h6"/>',
        'tag' => '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7" cy="7" r="1"/>',
        'palette' => '<circle cx="8" cy="8" r="1"/><circle cx="14" cy="6" r="1"/><circle cx="18" cy="11" r="1"/><path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h2a4 4 0 0 0 4-4c0-4-5-6-9-6Z"/>',
        'scent' => '<path d="M8 3c-4 4 4 4 0 8m8-8c-4 4 4 4 0 8M5 14h14v7H5v-7Z"/>',
        'shield' => '<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z"/><path d="m8 12 3 3 5-6"/>',
        'print' => '<path d="M6 8V3h12v5M6 17H3V8h18v9h-3M6 14h12v7H6v-7Zm11-3h1"/>',
    ];

    public static function render(string $name): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (self::PATHS[$name] ?? self::PATHS['arrow']) . '</svg>';
    }

    /** Escape labels and URLs here so fragments cannot accidentally inject HTML. */
    public static function actionLink(string $href, string $icon, string $label): string
    {
        $escape = Presentation::escape(...);
        return '<a class="admin-icon-action" href="' . $escape($href) . '" aria-label="' . $escape($label) . '" title="' . $escape($label) . '">'
            . self::render($icon) . '<span class="admin-action-tooltip" aria-hidden="true">' . $escape($label) . '</span></a>';
    }

    /** Status opens the dependency-aware manager; this never deletes on click. */
    public static function recordActions(string $editUrl, string $kind, int $id, string $name): string
    {
        $managementUrl = BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => $kind, 'id' => $id]);
        return '<div class="admin-record-actions">'
            . self::actionLink($editUrl, 'edit', 'Edit ' . $name)
            . self::actionLink($managementUrl, 'settings', 'Manage status and deletion: ' . $name)
            . '</div>';
    }
}
