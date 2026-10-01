<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\Locale;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$check(Locale::resolve(['lang' => 'ar'], ['homas_language' => 'fr']) === 'ar', 'Explicit selection takes priority.');
$check(Locale::resolve([], ['homas_language' => 'fr']) === 'fr', 'Saved preference is restored.');
$check(Locale::resolve([], [], 'en;q=0.2,fr;q=0.9') === 'fr', 'Browser languages honor quality values.');
$check(Locale::resolve(['lang' => 'xx'], ['homas_language' => 'ar']) === 'ar', 'Unsupported selection cannot override preference.');
$check(Locale::text('shop.apply', 'ar') === 'تطبيق', 'Arabic text comes from its dictionary.');
$check(Locale::text('not.a.key', 'fr') === 'not.a.key', 'Unknown keys fail visibly.');

$english = require __DIR__ . '/../../app/translations/en.php';
foreach (['fr', 'ar'] as $language) {
    $dictionary = require __DIR__ . '/../../app/translations/' . $language . '.php';
    $missing = array_diff(array_keys($english), array_keys($dictionary));
    $check($missing === [], $language . ' is missing keys: ' . implode(', ', $missing));
}

foreach ([
    'app/Views/Layouts/header.php', 'app/Views/Layouts/footer.php',
    'app/Views/Pages/shop.php', 'app/Views/Pages/category.php', 'app/Views/Pages/product.php',
    'app/Views/Components/catalogue-results.php', 'app/Views/Components/catalogue-promise.php',
    'app/Views/Components/product-card.php', 'app/Views/Components/product-set-components.php',
    'public/index.php', 'public/cart.php', 'public/checkout.php',
    'public/contact.php', 'public/order-confirmation.php', 'public/login.php', 'public/signup.php',
    'public/forgot-password.php', 'public/reset-password.php',
] as $path) {
    $source = (string) file_get_contents(__DIR__ . '/../../' . $path);
    preg_match_all("/site_text\('([^']+)'\)/", $source, $matches);
    foreach ($matches[1] as $key) {
        $check(array_key_exists($key, $english), $path . ' uses an unknown translation key: ' . $key);
    }
}

$_GET = ['lang' => 'ar'];
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
session_save_path(sys_get_temp_dir());
ob_start();
require __DIR__ . '/../../public/index.php';
$html = (string) ob_get_clean();
$check(str_contains($html, '<html lang="ar" dir="rtl">'), 'Arabic page sets language and RTL direction.');
$check(str_contains($html, 'تسوق الآن'), 'Arabic page renders translated content.');
$check(str_contains($html, 'assets/css/rtl.css'), 'Arabic page includes RTL styles.');

echo "Locale checks passed.\n";
