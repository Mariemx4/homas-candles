<?php

declare(strict_types=1);

namespace App\Services;

final class PublicMetadata
{
    private const PRIVATE_PAGES = [
        'account.php', 'account-order.php', 'cart.php', 'checkout.php',
        'order-confirmation.php', 'order-payment.php', 'login.php', 'signup.php',
        'forgot-password.php', 'reset-password.php',
    ];

    /** An explicit production origin prevents Host-header poisoning in canonical URLs. */
    public static function origin(array $server): ?string
    {
        $configured = getenv('APP_PUBLIC_ORIGIN');
        if (is_string($configured) && $configured !== '') {
            $parts = parse_url($configured);
            return filter_var($configured, FILTER_VALIDATE_URL) !== false
                && is_array($parts)
                && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                && is_string($parts['host'] ?? null)
                && in_array($parts['path'] ?? '', ['', '/'], true)
                && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['query'], $parts['fragment'])
                ? rtrim($configured, '/') : null;
        }

        // Local HTTP verification and XAMPP may use their own loopback port.
        $host = (string) ($server['HTTP_HOST'] ?? '');
        if (preg_match('/^(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?$/i', $host) !== 1) {
            return null;
        }
        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    public static function url(string $origin, string $basePath, string $path, string $languageCode): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';
        return $origin . $basePath . $path . $separator . 'lang=' . rawurlencode($languageCode);
    }

    /** @return array{description: string, robots: string, canonical: ?string, image: ?string, type: string, structuredData: ?array} */
    public static function forPage(
        string $page,
        bool $isCategory,
        string $languageCode,
        string $basePath,
        array $server,
        array $query,
        int $responseStatus,
        ?array $categoryPage = null,
        ?array $product = null,
        array $scents = []
    ): array {
        $origin = self::origin($server);
        $isPrivate = in_array($page, self::PRIVATE_PAGES, true);
        $isKnown = in_array($page, ['index.php', 'shop.php', 'product.php', 'contact.php'], true) || $isCategory;
        $noindex = $isPrivate || !$isKnown || $responseStatus >= 400 || $origin === null;

        $description = match (true) {
            $page === 'index.php' => Locale::text('footer.story_body'),
            $page === 'shop.php' => Locale::text('shop.description'),
            $isCategory && $categoryPage !== null => (string) ($categoryPage['description'] ?? ''),
            $page === 'product.php' && $product !== null => (string) ($product['description'] ?? ''),
            $page === 'contact.php' => Locale::text('seo.contact.description'),
            default => Locale::text('footer.tagline'),
        };
        $description = self::summary($description);

        $path = match (true) {
            $page === 'index.php' => 'index.php',
            $page === 'shop.php' => 'shop.php',
            $isCategory && $categoryPage !== null => (string) ($categoryPage['path'] ?? ''),
            $page === 'product.php' && $product !== null => 'product.php?slug=' . rawurlencode((string) $product['slug']),
            $page === 'contact.php' => 'contact.php',
            default => '',
        };
        if ($path === '') {
            $noindex = true;
        }
        $landingParameters = $isCategory ? ['lang', 'slug'] : ['lang'];
        if (($page === 'shop.php' || $isCategory) && array_diff(array_keys($query), $landingParameters) !== []) {
            // Search, filters and pagination are useful to visitors, but duplicate landing pages.
            $noindex = true;
        }

        $canonical = $origin !== null && !$isPrivate && $path !== '' && $responseStatus < 400
            ? self::url($origin, $basePath, $path, $languageCode)
            : null;
        $imagePath = $page === 'product.php' && $product !== null
            ? (string) ($product['main_image_path'] ?? '')
            : 'assets/brand/bestSeller.jpg';
        $image = $origin !== null && $imagePath !== '' ? $origin . $basePath . ltrim($imagePath, '/') : null;

        $structuredData = null;
        if (!$noindex && $page === 'product.php' && $product !== null && $canonical !== null && $image !== null) {
            $structuredData = [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => (string) $product['name'],
                'description' => $description,
                'image' => [$image],
                'url' => $canonical,
                'brand' => ['@type' => 'Brand', 'name' => "Hom'As Candles"],
            ];
            if (($product['purchase_mode'] ?? null) === 'CART') {
                $millis = Money::parseMillis((string) ($product['selling_price'] ?? ''));
                if ($millis !== null) {
                    $available = ((string) ($product['category_slug'] ?? '') !== 'accessories'
                            || (int) ($product['stock_quantity'] ?? 0) >= max(1, (int) ($product['minimum_quantity'] ?? 1)))
                        && ($scents === [] || array_filter($scents,
                            static fn (array $scent): bool => ($scent['is_available'] ?? true) !== false) !== []);
                    $structuredData['offers'] = [
                        '@type' => 'Offer',
                        'url' => $canonical,
                        'priceCurrency' => 'TND',
                        'price' => Money::formatMillis($millis),
                        'availability' => 'https://schema.org/' . ($available
                            ? (!empty($product['is_made_to_order']) ? 'PreOrder' : 'InStock')
                            : 'OutOfStock'),
                    ];
                }
            }
        }

        return [
            'description' => $description,
            'robots' => $noindex ? ($isPrivate ? 'noindex, nofollow' : 'noindex, follow') : 'index, follow',
            'canonical' => $canonical,
            'image' => $image,
            'type' => $page === 'product.php' && $product !== null ? 'product' : 'website',
            'structuredData' => $structuredData,
        ];
    }

    private static function summary(string $value): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        return function_exists('mb_substr') ? mb_substr($plain, 0, 160, 'UTF-8') : substr($plain, 0, 160);
    }
}
