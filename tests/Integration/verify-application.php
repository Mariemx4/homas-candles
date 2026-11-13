<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-database.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Catch moved bootstraps in entry points, compatibility launchers and tests.
foreach (['public', 'scripts', 'tests'] as $folder) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT . '/' . $folder));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all("/(?m)^\\s*(?:require|require_once|include|include_once)\\s+__DIR__\\s*\\.\\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $relative) {
            if (str_ends_with($relative, '/') || str_ends_with($relative, '\\')) {
                continue;
            }
            verify(is_file($file->getPath() . '/' . $relative), 'Stale import in ' . $file->getPathname() . ': ' . $relative);
        }
    }
}
echo '[PASS] Literal route, wrapper and test imports resolve.' . PHP_EOL;

[$db, $databaseName] = create_throwaway_database('verify');
$server = null;
$sessionDirectory = sys_get_temp_dir() . '/homas_verify_session_' . bin2hex(random_bytes(6));
$logFile = sys_get_temp_dir() . '/homas_verify_server_' . bin2hex(random_bytes(6)) . '.log';
mkdir($sessionDirectory, 0700);
$customers = null;
$administrators = null;
$otherCustomer = null;
$previousDatabaseOverride = getenv('HOMAS_VERIFICATION_DATABASE');
$previousBaseUrl = getenv('APP_BASE_URL');
$previousPublicOrigin = getenv('APP_PUBLIC_ORIGIN');

try {
    $imagePath = 'assets/products/verification-fixture.svg';
    $db->exec("INSERT INTO products (category_id, product_type_id, slug, selling_price, minimum_quantity, purchase_mode, is_made_to_order, main_image_path, is_active)
        VALUES (1, 1, 'verification-candle', 50.000, 1, 'CART', 1, " . $db->quote($imagePath) . ", 1)");
    $productId = (int) $db->lastInsertId();
    $translation = $db->prepare('INSERT INTO product_translations (product_id, language_code, name, description) VALUES (?, ?, ?, ?)');
    foreach (['en', 'fr', 'ar'] as $language) {
        $translation->execute([$productId, $language, 'Verification Candle', 'A test candle']);
    }
    $db->exec("INSERT INTO colors (slug, hex_code, is_active) VALUES ('verify-ivory', '#ffffff', 1)");
    $colorId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO scents (slug, is_active) VALUES ('verify-vanilla', 1)");
    $scentId = (int) $db->lastInsertId();
    foreach (['en', 'fr', 'ar'] as $language) {
        $db->prepare('INSERT INTO color_translations (color_id, language_code, name) VALUES (?, ?, ?)')->execute([$colorId, $language, 'Ivory']);
        $db->prepare('INSERT INTO scent_translations (scent_id, language_code, name) VALUES (?, ?, ?)')->execute([$scentId, $language, 'Vanilla']);
    }
    $db->prepare('INSERT INTO product_colors (product_id, color_id, image_path) VALUES (?, ?, ?)')
        ->execute([$productId, $colorId, $imagePath]);
    $db->prepare('INSERT INTO product_scents (product_id, scent_id) VALUES (?, ?)')->execute([$productId, $scentId]);
    // Checkout refuses products without a production recipe (current business
    // rule), so the fixture product needs one to be orderable.
    $materialType = (int) $db->query('SELECT id FROM material_types ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO materials (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES (?, 'VERIFY-WAX', 'Verification wax', 'g', 10000, 0.150, 1, 1)")->execute([$materialType]);
    $db->prepare("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES (?, ?, 'ACTUAL', 40)")
        ->execute([$productId, (int) $db->lastInsertId()]);
    $adminPassword = 'VerifyAdmin-' . bin2hex(random_bytes(8));
    $statement = $db->prepare("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Verification', 'Administrator', 'verify-admin@example.test', '99112233', ?, 'ACTIVE')");
    $statement->execute([password_hash($adminPassword, PASSWORD_ARGON2ID)]);

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    verify(is_resource($listener), 'Could not reserve a local test port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    putenv('HOMAS_VERIFICATION_DATABASE=' . $databaseName);
    putenv('APP_BASE_URL=/public/');
    putenv('APP_PUBLIC_ORIGIN=http://127.0.0.1:' . $port);
    $server = proc_open(
        [
            PHP_BINARY,
            '-d',
            'session.save_path=' . $sessionDirectory,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            PROJECT_ROOT
        ],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        PROJECT_ROOT
    );
    verify(is_resource($server), 'Could not start the local PHP server.');
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port . '/public/';

    $request = static function (CurlHandle $client, string $path, ?array $post = null) use ($base): array {
        $location = '';
        curl_setopt_array($client, [
            CURLOPT_URL => $base . ltrim($path, '/'),
            CURLOPT_POST => $post !== null,
            CURLOPT_POSTFIELDS => $post === null ? null : http_build_query($post),
            CURLOPT_HTTPGET => $post === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        verify(is_string($body), 'HTTP request failed: ' . $path . ' (' . curl_error($client) . ')');
        $status = (int) curl_getinfo($client, CURLINFO_HTTP_CODE);
        verify(
            $status > 0 && $status < 500 && !str_contains($body, 'We’ll be back shortly')
                && preg_match('/(?:Fatal error|Warning|Notice):/', $body) !== 1,
            'Route failed: ' . $path . ' (HTTP ' . $status . ')'
        );
        return [$status, $body, $location];
    };
    $token = static function (string $html, string $name): string {
        verify(
            preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match) === 1,
            'Expected form field missing: ' . $name
        );
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    $customers = curl_init();
    $administrators = curl_init();
    $otherCustomer = curl_init();
    foreach ([$customers, $administrators, $otherCustomer] as $client) {
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
    }
    for ($attempt = 0; $attempt < 40; $attempt++) {
        curl_setopt($customers, CURLOPT_URL, $base . 'index.php');
        if (curl_exec($customers) !== false) {
            break;
        }
        usleep(100000);
    }

    [$status, $shop] = $request($customers, 'shop.php?query=Verification');
    verify(
        $status === 200 && str_contains($shop, 'Verification Candle'),
        'Search must show the active fixture product (HTTP ' . $status . ', ' . substr(strip_tags($shop), 0, 250) . ').'
    );
    // Regression: product text uses a nondeterministic collation that PostgreSQL
    // rejects in LIKE; search must still work and stay case-insensitive.
    [$status, $lowerShop] = $request($customers, 'shop.php?query=verification');
    verify($status === 200 && str_contains($lowerShop, 'Verification Candle'), 'Search is case-insensitive.');
    [$status, $wildcardShop] = $request($customers, 'shop.php?query=' . rawurlencode('%'));
    verify($status === 200 && !str_contains($wildcardShop, 'Verification Candle'), 'A typed % is matched literally, not as a wildcard.');
    verify(
        str_contains($shop, 'class="site-skip-link" href="#main-content"')
            && str_contains($shop, '<main id="main-content" tabindex="-1"'),
        'Public pages must provide a keyboard skip link to their main content.'
    );
    verify(
        str_contains($shop, 'name="robots" content="noindex, follow"'),
        'Search results must not be indexed as duplicate landing pages.'
    );
    [$status, $plainShop] = $request($customers, 'shop.php');
    verify(
        $status === 200 && str_contains($plainShop, 'name="description"')
            && str_contains($plainShop, 'rel="canonical"')
            && str_contains($plainShop, 'property="og:image"'),
        'The public shop landing page must expose discovery metadata.'
    );
    [$status, $privateCart] = $request($customers, 'cart.php');
    verify(
        $status === 200 && str_contains($privateCart, 'name="robots" content="noindex, nofollow"')
            && !str_contains($privateCart, 'rel="canonical"'),
        'Cart pages must not be indexed or published as canonical landing pages.'
    );
    [$status, $category] = $request($customers, 'categories/candles.php');
    verify($status === 200 && str_contains($category, 'Verification Candle'), 'Category must show the fixture product.');
    verify(str_contains($shop, 'categories/candle-bouquets.php'), 'The database bouquet category must retain its public route.');
    $db->exec("INSERT INTO categories (slug, is_active) VALUES ('gift-boxes', 1)");
    $newCategoryId = (int) $db->lastInsertId();
    $addCategoryTranslation = $db->prepare('INSERT INTO category_translations (category_id, language_code, name) VALUES (?, ?, ?)');
    foreach (['en' => 'Gift Boxes', 'fr' => 'Coffrets cadeaux', 'ar' => 'Gift Boxes'] as $language => $name) {
        $addCategoryTranslation->execute([$newCategoryId, $language, $name]);
    }
    [$status, $shopWithCategory] = $request($customers, 'shop.php');
    verify($status === 200 && str_contains($shopWithCategory, 'category.php?slug=gift-boxes')
        && str_contains($shopWithCategory, 'Gift Boxes'), 'Admin-managed active categories must appear in the shop.');
    [$status, $homeWithCategory] = $request($customers, 'index.php');
    verify(
        $status === 200 && str_contains($homeWithCategory, 'category.php?slug=gift-boxes'),
        'Admin-managed active categories must appear on the homepage.'
    );
    [$status, $genericCategory] = $request($customers, 'category.php?slug=gift-boxes');
    verify(
        $status === 200 && str_contains($genericCategory, 'Gift Boxes')
            && str_contains($genericCategory, 'name="slug" value="gift-boxes"'),
        'The generic category route must render and preserve its slug in filter submissions (HTTP ' . $status
            . ', name=' . (str_contains($genericCategory, 'Gift Boxes') ? 'yes' : 'no')
            . ', slug=' . (str_contains($genericCategory, 'name="slug" value="gift-boxes"') ? 'yes' : 'no') . ').'
    );
    $db->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$newCategoryId]);
    [$status, $shopWithoutCategory] = $request($customers, 'shop.php');
    verify(
        $status === 200 && !str_contains($shopWithoutCategory, 'category.php?slug=gift-boxes'),
        'Inactive categories must leave storefront navigation.'
    );
    [$status] = $request($customers, 'category.php?slug=gift-boxes');
    verify($status === 404, 'Inactive category routes must return HTTP 404.');
    [$status, $product] = $request($customers, 'product.php?slug=verification-candle');
    verify($status === 200 && str_contains($product, 'Verification Candle'), 'Product detail must render.');
    verify(
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $product, $schemaMatch) === 1,
        'Active product pages must expose structured product data.'
    );
    $schema = json_decode($schemaMatch[1], true, 512, JSON_THROW_ON_ERROR);
    verify(
        $schema['@type'] === 'Product' && $schema['name'] === 'Verification Candle'
            && $schema['offers']['price'] === '50.000'
            && $schema['offers']['priceCurrency'] === 'TND'
            && $schema['offers']['availability'] === 'https://schema.org/PreOrder',
        'Structured data must use the current catalogue price and made-to-order availability.'
    );
    [$status, $sitemap] = $request($customers, 'sitemap.php');
    verify(
        $status === 200 && str_contains($sitemap, 'product.php?slug=verification-candle&amp;lang=en')
            && !str_contains($sitemap, 'account.php') && !str_contains($sitemap, 'admin/'),
        'The sitemap must include active products and exclude private routes.'
    );
    [$status, $robots] = $request($customers, 'robots.txt');
    verify($status === 200 && str_contains($robots, 'Disallow: /admin/'), 'Crawler rules must exclude admin routes.');
    [$status] = $request($customers, 'product.php?slug=missing-verification-product');
    verify($status === 404, 'Missing product routes must return HTTP 404.');
    [$status] = $request($customers, 'product.php?slug=invalid%20slug');
    verify($status === 404, 'Invalid product slugs must return HTTP 404.');
    [$status] = $request($customers, $imagePath);
    verify($status === 200, 'The product image must be served.');
    [$status, $cart] = $request($customers, 'cart.php');
    $csrf = $token($product, 'csrf_token');
    [$status] = $request($customers, 'cart.php', [
        'csrf_token' => $csrf,
        'action' => 'add',
        'slug' => 'verification-candle',
        'color' => (string) $colorId,
        'scent' => (string) $scentId,
        'quantity' => '1'
    ]);
    verify($status === 303, 'Cart add must redirect.');
    [$status, $cart] = $request($customers, 'cart.php');
    verify($status === 200 && str_contains($cart, 'Verification Candle'), 'Cart must retain the selected product.');
    [$status, $checkout] = $request($customers, 'checkout.php');
    verify($status === 200 && str_contains($checkout, 'Verification Candle'), 'Checkout must show the cart.');
    [$status, $signup] = $request($customers, 'signup.php');
    verify($status === 200, 'Signup form must render.');
    $customerPassword = 'VerifyCustomer-' . bin2hex(random_bytes(6));
    [$status,, $location] = $request($customers, 'signup.php', [
        'csrf_token' => $token($signup, 'csrf_token'),
        'first_name' => 'Verification',
        'last_name' => 'Customer',
        'email' => 'verify-customer@example.test',
        'phone_number' => '98112233',
        'password' => $customerPassword,
        'password_confirmation' => $customerPassword,
        'address_label' => 'Home',
        'street_address' => '1 Test Street',
        'governorate' => 'Tunis',
        'delegation' => 'Tunis',
        'postal_code' => '1000',
    ]);
    verify($status === 303 && str_contains($location, 'login.php'), 'Customer signup must complete.');
    [$status, $login] = $request($customers, 'login.php');
    [$status,, $location] = $request($customers, 'login.php', [
        'csrf_token' => $token($login, 'csrf_token'),
        'login_identifier' => 'verify-customer@example.test',
        'password' => $customerPassword,
    ]);
    verify($status === 303 && str_contains($location, 'index.php'), 'Customer must sign in.');
    [$status, $checkout] = $request($customers, 'checkout.php');
    verify($status === 200, 'Signed-in checkout must render.');
    [$status,, $location] = $request($customers, 'checkout.php', [
        'csrf_token' => $token($checkout, 'csrf_token'),
        'cart_signature' => $token($checkout, 'cart_signature'),
        'customer_name' => 'Verification Customer',
        'customer_phone' => '98112233',
        'customer_email' => 'verify-customer@example.test',
        'delivery_method' => 'PICKUP',
        'required_date' => (new DateTimeImmutable('today'))->modify('+20 days')->format('Y-m-d'),
        'accept_terms' => '1',
    ]);
    verify($status === 303 && str_contains($location, 'order-confirmation.php'), 'Customer checkout must place an order.');
    $order = $db->query("SELECT id, order_number FROM orders WHERE customer_email_snapshot = 'verify-customer@example.test' ORDER BY id DESC LIMIT 1")->fetch();
    verify(is_array($order), 'Checkout must persist the customer order.');
    [$status, $confirmation] = $request($customers, 'order-confirmation.php?order=' . rawurlencode($order['order_number']));
    verify($status === 200 && str_contains($confirmation, $order['order_number']), 'Customer order confirmation must render.');
    [$status, $accountPage] = $request($customers, 'account.php');
    verify(
        $status === 200 && str_contains($accountPage, 'verify-customer@example.test')
            && str_contains($accountPage, $order['order_number']) && str_contains($accountPage, '1 Test Street'),
        'The customer account must show contact details, address and linked order.'
    );
    [$status, $frenchAccount] = $request($customers, 'account.php?lang=fr');
    verify($status === 200 && str_contains($frenchAccount, 'lang="fr"')
        && str_contains($frenchAccount, 'Vos commandes'), 'The account view must translate into French.');
    [$status, $arabicAccount] = $request($customers, 'account.php?lang=ar');
    verify($status === 200 && str_contains($arabicAccount, 'lang="ar" dir="rtl"')
        && str_contains($arabicAccount, 'طلباتك'), 'The account view must translate and use RTL in Arabic.');
    $request($customers, 'account.php?lang=en');
    [$status, $customerOrder] = $request($customers, 'account-order.php?id=' . $order['id']);
    verify($status === 200 && str_contains($customerOrder, $order['order_number'])
        && str_contains($customerOrder, 'Verification Candle'), 'The customer must see their own order snapshots.');
    [$status,, $location] = $request($otherCustomer, 'account.php');
    verify($status === 303 && str_contains($location, 'login.php'), 'A guest must sign in before viewing account data.');
    $otherPassword = 'VerifyOther-' . bin2hex(random_bytes(6));
    $db->prepare("INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Other', 'Customer', 'verify-other@example.test', '98112234', ?, 'ACTIVE')")
        ->execute([password_hash($otherPassword, PASSWORD_ARGON2ID)]);
    $otherId = (int) $db->lastInsertId();
    [$status, $otherLogin] = $request($otherCustomer, 'login.php');
    [$status,, $location] = $request($otherCustomer, 'login.php', [
        'csrf_token' => $token($otherLogin, 'csrf_token'),
        'login_identifier' => 'verify-other@example.test',
        'password' => $otherPassword,
    ]);
    verify($status === 303 && str_contains($location, 'index.php'), 'The second customer must sign in.');
    [$status, $privateOrder] = $request($otherCustomer, 'account-order.php?id=' . $order['id']);
    verify(
        $status === 404 && !str_contains($privateOrder, $order['order_number']),
        'Another customer must not view the order.'
    );
    [$status] = $request($otherCustomer, 'account-order.php?id%5B%5D=' . $order['id']);
    verify($status === 404, 'Malformed order identifiers must be rejected.');
    $db->prepare("UPDATE customers_accounts SET account_status = 'SUSPENDED' WHERE id = ?")->execute([$otherId]);
    [$status,, $location] = $request($otherCustomer, 'account.php');
    verify($status === 303 && str_contains($location, 'login.php'), 'Suspended accounts must lose access.');
    $publicRoutes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PUBLIC_PATH));
    $publicCovered = 0;
    foreach ($publicRoutes as $route) {
        if (!$route->isFile() || $route->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($route->getPathname(), strlen(PUBLIC_PATH) + 1));
        if (str_starts_with($path, 'admin/')) {
            continue;
        }
        $request($customers, $path);
        $publicCovered++;
    }
    echo '[PASS] Customer search, signup, sign-in, cart, checkout, account, order privacy and '
        . $publicCovered . ' public route entry points.' . PHP_EOL;

    [$status, $adminLogin] = $request($administrators, 'admin/login.php');
    verify($status === 200, 'Admin login page must render.');
    [$status,, $location] = $request($administrators, 'admin/login.php', [
        'csrf_token' => $token($adminLogin, 'csrf_token'),
        'login_identifier' => 'verify-admin@example.test',
        'password' => $adminPassword,
    ]);
    verify($status === 303 && str_contains($location, 'admin/index.php'), 'Administrator must sign in.');
    [$status, $dashboard] = $request($administrators, 'admin/index.php');
    verify($status === 200 && str_contains($dashboard, 'Dashboard'), 'Authenticated dashboard must render.');
    [$status, $ordersPage] = $request($administrators, 'admin/orders/index.php');
    verify($status === 200 && str_contains($ordersPage, $order['order_number']), 'Admin orders must include the customer order.');
    [$status, $orderDetail] = $request($administrators, 'admin/orders/detail.php?id=' . $order['id']);
    verify($status === 200 && str_contains($orderDetail, $order['order_number']), 'Admin order detail must render.');
    $originalStatus = (string) $db->query('SELECT order_status FROM orders WHERE id = ' . (int) $order['id'])->fetchColumn();
    verify($originalStatus === 'REQUESTED', 'The new customer order must await admin review.');
    [$status] = $request($administrators, 'admin/orders/status.php', [
        'csrf_token' => $token($orderDetail, 'csrf_token'),
        'id' => (string) $order['id'],
        'new_status' => 'CONFIRMED',
        'expected_status' => $originalStatus,
        'inline_status' => '1',
    ]);
    verify($status === 303, 'Admin order review must redirect.');
    $updatedStatus = (string) $db->query('SELECT order_status FROM orders WHERE id = ' . (int) $order['id'])->fetchColumn();
    verify($updatedStatus === 'CONFIRMED', 'Admin review must persist the order status.');
    [$status, $updatedCustomerOrder] = $request($customers, 'account-order.php?id=' . $order['id']);
    verify(
        $status === 200 && str_contains($updatedCustomerOrder, 'Confirmed'),
        'The customer order view must show the reviewed status.'
    );

    $routes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PUBLIC_PATH . '/admin'));
    $covered = 0;
    foreach ($routes as $route) {
        if (!$route->isFile() || $route->getExtension() !== 'php' || $route->getFilename() === 'logout.php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($route->getPathname(), strlen(PUBLIC_PATH) + 1));
        [$status] = $request($administrators, $path);
        verify($status !== 401 && $status !== 403, 'Admin session was rejected on ' . $path);
        $covered++;
    }
    $db->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$productId]);
    [$status] = $request($customers, 'product.php?slug=verification-candle');
    verify($status === 404, 'An inactive product must return HTTP 404.');
    [$status, $inactiveSitemap] = $request($customers, 'sitemap.php');
    verify(
        $status === 200 && !str_contains($inactiveSitemap, 'verification-candle'),
        'Inactive products must leave the sitemap.'
    );
    echo '[PASS] Administrator sign-in, order review, dashboard and ' . $covered . ' admin route entry points.' . PHP_EOL;
} finally {
    if ($customers instanceof CurlHandle) {
        curl_close($customers);
    }
    if ($administrators instanceof CurlHandle) {
        curl_close($administrators);
    }
    if ($otherCustomer instanceof CurlHandle) {
        curl_close($otherCustomer);
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    putenv($previousDatabaseOverride === false ? 'HOMAS_VERIFICATION_DATABASE' : 'HOMAS_VERIFICATION_DATABASE=' . $previousDatabaseOverride);
    putenv($previousBaseUrl === false ? 'APP_BASE_URL' : 'APP_BASE_URL=' . $previousBaseUrl);
    putenv($previousPublicOrigin === false ? 'APP_PUBLIC_ORIGIN' : 'APP_PUBLIC_ORIGIN=' . $previousPublicOrigin);
    drop_throwaway_database($db, $databaseName);
    foreach (glob($sessionDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDirectory);
    @unlink($logFile);
}
