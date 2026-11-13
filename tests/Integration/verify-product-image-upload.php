<?php

declare(strict_types=1);

/*
 * Product photo replacement through the real admin route.
 *
 * Covers ProductImageService (reached via AdminProductService and the
 * /admin/catalogue/products.php form): multipart upload, CSRF, the
 * store → database → clean-up order, removal of the replaced file, the
 * "assign the color first" rule and rejection of non-image uploads.
 *
 * ISOLATION: data lives in a generated schema selected for the temporary PHP
 * server through PGOPTIONS. Uploaded files are written to the product image
 * folder of the environment running the suite, so run it in a disposable
 * preview container. Every file it creates is removed at the end.
 */
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

[$db, $schema] = isolate_application_database();
$directory = sys_get_temp_dir() . '/homas-image-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$filesBefore = array_flip(scandir(PRODUCT_IMAGE_PATH) ?: []);
$server = null;
$check = TestCheck::printing();

try {
    $db->exec("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Image', 'Verifier', 'image.verifier@example.test', '72009876', 'not-a-login-hash', 'ACTIVE')");
    $adminId = (int) $db->lastInsertId();
    $categoryId = (int) $db->query("SELECT id FROM categories WHERE slug = 'candles'")->fetchColumn();
    $typeId = (int) $db->query('SELECT id FROM product_types ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO products (category_id, product_type_id, slug) VALUES (:category, :type, 'image-upload-check')")
        ->execute(['category' => $categoryId, 'type' => $typeId]);
    $productId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO colors (slug, hex_code, is_active) VALUES ('image-check-rose', '#F4DADA', 1)");
    $colorId = (int) $db->lastInsertId();

    $sessionId = bin2hex(random_bytes(16));
    $auth = ['authenticated_at' => time(), 'last_activity_at' => time(), 'administrator_id' => $adminId];
    file_put_contents($directory . '/sess_' . $sessionId, 'administrator_auth|' . serialize($auth));

    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $directory, $directory . '/server.log', [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        // Admin pages redirect to the configured sign-in origin. Fixture Google
        // values make this temporary server that origin and keep real credentials
        // out of the test.
        'GOOGLE_CLIENT_ID' => 'image-fixture.apps.googleusercontent.com',
        'GOOGLE_CLIENT_SECRET' => 'image-fixture-secret',
        'GOOGLE_REDIRECT_URI' => 'http://' . $address . '/auth/google-callback.php',
    ], ['upload_max_filesize' => '6M', 'post_max_size' => '7M']);

    $request = static function (string $path, ?array $post = null, ?string $fileBytes = null, string $fileName = 'photo.png', string $fileType = 'image/png') use ($address, $sessionId): array {
        $headers = ['Cookie: PHPSESSID=' . $sessionId];
        $body = '';
        if ($post !== null) {
            $boundary = 'homas' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            foreach ($post as $key => $value) {
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
            }
            if ($fileBytes !== null) {
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"image\"; filename=\"" . $fileName . "\"\r\nContent-Type: " . $fileType . "\r\n\r\n" . $fileBytes . "\r\n";
            }
            $body .= '--' . $boundary . "--\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);
        $html = (string) file_get_contents('http://' . $address . '/' . $path, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), $html];
    };
    $editPath = 'admin/catalogue/products.php?id=' . $productId;
    $token = static function () use ($request, $editPath): string {
        [, $html] = $request($editPath);
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException("The product editor did not render a CSRF field: " . substr(strip_tags($html), 0, 300));
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    $mainPath = static fn (): ?string => ($value = $db->query("SELECT main_image_path FROM products WHERE id = {$productId}")->fetchColumn()) === null ? null : (string) $value;
    $colorPath = static fn (): ?string => ($value = $db->query("SELECT image_path FROM product_colors WHERE product_id = {$productId} AND color_id = {$colorId}")->fetchColumn()) ? (string) $value : null;
    $exists = static fn (?string $publicPath): bool => $publicPath !== null && is_file(PRODUCT_IMAGE_PATH . '/' . basename($publicPath));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4X8AAAAASUVORK5CYII=');

    [$status] = $request($editPath, ['csrf_token' => 'forged', 'action' => 'upload_main_image', 'id' => (string) $productId], $png);
    $check($mainPath() === null, 'A forged CSRF token stores no main image.');

    [$status] = $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_main_image', 'id' => (string) $productId], $png);
    $first = $mainPath();
    $check(in_array($status, [302, 303], true) && $exists($first), 'An administrator upload stores the main image and redirects (Post/Redirect/Get).');

    $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_main_image', 'id' => (string) $productId], $png);
    $second = $mainPath();
    $check($second !== null && $second !== $first && $exists($second) && !$exists($first), 'Replacing the main image points at the new file and removes the old one.');

    $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_main_image', 'id' => (string) $productId], 'not an image', 'photo.png');
    $check($mainPath() === $second && $exists($second), 'A non-image upload is rejected and keeps the current main image.');

    $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_color_image', 'id' => (string) $productId, 'color_id' => (string) $colorId], $png);
    $check($colorPath() === null, 'A color photo is refused until the color is assigned to the product.');

    $db->exec("INSERT INTO product_colors (product_id, color_id) VALUES ({$productId}, {$colorId})");
    $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_color_image', 'id' => (string) $productId, 'color_id' => (string) $colorId], $png);
    $firstColor = $colorPath();
    $request($editPath, ['csrf_token' => $token(), 'action' => 'upload_color_image', 'id' => (string) $productId, 'color_id' => (string) $colorId], $png);
    $secondColor = $colorPath();
    $check($exists($secondColor) && $firstColor !== $secondColor && !$exists($firstColor), 'Replacing a color photo stores the new file and removes the old one.');

    $newFiles = array_diff(scandir(PRODUCT_IMAGE_PATH) ?: [], array_keys($filesBefore));
    $check(count($newFiles) === 2, 'Only the two current photos remain; rejected and replaced uploads left no files.');
} finally {
    $server?->stop();
    foreach (array_diff(scandir(PRODUCT_IMAGE_PATH) ?: [], array_keys($filesBefore)) as $created) {
        if (is_file(PRODUCT_IMAGE_PATH . '/' . $created)) {
            unlink(PRODUCT_IMAGE_PATH . '/' . $created);
        }
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
    drop_throwaway_auth_schema($db, $schema);
}
echo 'Product image upload checks passed.' . PHP_EOL;
