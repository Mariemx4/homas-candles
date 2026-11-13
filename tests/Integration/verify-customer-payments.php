<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

use App\Database\TransactionManager;
use App\Repositories\PaymentRepository;
use App\Services\PaymentEvidenceStorage;
use App\Services\PaymentService;

[$db, $schema] = create_throwaway_auth_schema(true);
$directory = sys_get_temp_dir() . '/homas-payment-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$server = null;
$proofPaths = [];
$check = TestCheck::printing();
try {
    $db->exec("INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Payment', 'Customer', 'payment.customer@example.test', '22001234', 'test-hash', 'ACTIVE')");
    $customerId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO orders (order_number, customer_id, language_code, order_source, order_status, delivery_method, required_date,
        customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount)
        VALUES ('ORD-PAYMENT-HTTP', :customer, 'en', 'CART', 'REQUESTED', 'PICKUP', CURRENT_DATE + 7, '<Payment Customer>', '22001234', 100.000, 0.000, 100.000, 40.000)")->execute(['customer' => $customerId]);
    $orderId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Payment', 'Reviewer', 'payment.admin@example.test', '72001234', 'test-hash', 'ACTIVE')");
    $adminId = (int) $db->lastInsertId();
    $newSession = static function (array $values) use ($directory): string {
        $id = bin2hex(random_bytes(16));
        $contents = '';
        foreach ($values as $key => $value) {
            $contents .= $key . '|' . serialize($value);
        }
        file_put_contents($directory . '/sess_' . $id, $contents);
        return $id;
    };
    $auth = ['authenticated_at' => time(), 'last_activity_at' => time()];
    $customerSession = $newSession(['customer_auth' => $auth + ['customer_id' => $customerId]]);
    $guestSession = $newSession(['checkout_placed_orders' => ['ORD-PAYMENT-HTTP' => $orderId]]);
    $adminSession = $newSession(['administrator_auth' => $auth + ['administrator_id' => $adminId]]);
    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $directory, $directory . '/server.log', [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'HOMAS_D17_ACCOUNT_HOLDER' => 'Test <HOMAS> Business',
        'HOMAS_D17_NUMBER' => '22000000',
        'HOMAS_VERIFICATION_DATABASE' => null,
    ], ['upload_max_filesize' => '6M', 'post_max_size' => '7M']);
    $request = static function (string $path, ?string $session = null, ?array $post = null, ?string $png = null) use ($address): array {
        $headers = $session === null ? [] : ['Cookie: PHPSESSID=' . $session];
        $body = '';
        if ($post !== null) {
            if ($png === null) {
                $body = http_build_query($post);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $boundary = 'homas' . bin2hex(random_bytes(16));
                $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
                foreach ($post as $key => $value) {
                    $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
                }
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"proof.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $boundary . "--\r\n";
            }
        }
        $context = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10
        ]]);
        $html = file_get_contents('http://' . $address . '/' . $path, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), (string) $html, $responseHeaders];
    };
    $token = static function (string $html): string {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException('Missing payment CSRF field.');
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    $url = 'order-payment.php?order_id=' . $orderId . '&method=D17';
    [$status, $html] = $request($url, $customerSession);
    $check($status === 200 && str_contains($html, 'Test &lt;HOMAS&gt; Business') && str_contains($html, '40.000') && str_contains($html, 'noindex, nofollow'), 'Customer D17 instructions render configured escaped recipient details, authoritative deposit and private metadata.');
    $check($request($url)[0] === 404 && $request($url, $guestSession)[0] === 200, 'Order payment page rejects anonymous ID access and accepts the checkout-session capability.');
    foreach (['index.php', 'order.php?order_id=' . $orderId, 'review.php?id=1&action=verify', 'evidence.php?id=1'] as $route) {
        $check($request('admin/payments/' . $route)[0] === 303, 'Administrator authentication protects payment route ' . $route);
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4X8AAAAASUVORK5CYII=');
    $check(!str_contains($html, 'admin_estimate') && !str_contains($html, 'Estimated gross profit') && !str_contains($html, 'unit_cost_snapshot'), 'Customer payment output excludes admin cost and profit estimates.');
    $post = ['csrf_token' => $token($html), 'order_id' => (string) $orderId, 'amount' => '0.001', 'payment_method' => 'CASH_ON_DELIVERY'];
    $check($request($url, $customerSession, $post, $png)[0] === 303, 'Genuine customer proof upload uses Post/Redirect/Get.');
    $row = $db->query('SELECT * FROM payments ORDER BY id DESC LIMIT 1')->fetch();
    $proofPaths[] = $row['payment_proof_path'];
    $check($row['amount'] === '40.000' && $row['payment_method'] === 'D17' && $row['payment_status'] === 'PENDING_VERIFICATION' && $row['verified_at'] === null, 'Customer amount/method tampering cannot replace authoritative D17 deposit or mark it paid.');
    $paymentId = (int) $row['id'];
    [$status, $proof] = $request('order-payment.php?order_id=' . $orderId . '&evidence=' . $paymentId, $customerSession);
    $check($status === 200 && $proof === $png && $request('order-payment.php?order_id=' . $orderId . '&evidence=' . $paymentId)[0] === 404, 'Customer proof is streamed only to an authorized order viewer.');
    $request($url, $customerSession, $post, $png);
    $check((int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 1, 'Repeated customer POST cannot create duplicate proof payments.');
    [$status, $review] = $request('admin/payments/review.php?id=' . $paymentId . '&action=verify', $adminSession);
    $check($status === 200 && str_contains($review, '&lt;Payment Customer&gt;') && str_contains($review, 'Current amount due') && str_contains($review, 'View uploaded evidence'), 'Administrator review renders customer, current expected amount and protected proof link.');
    $service = new PaymentService(new PaymentRepository($db), new TransactionManager($db), new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH));
    $check($service->reject($paymentId, $adminId, 'Wrong recipient')['success'], 'Administrator can reject genuine uploaded proof with an audit reason.');
    [, $html] = $request($url, $customerSession);
    $check(str_contains($html, 'Wrong recipient') && str_contains($html, 'Submit proof for verification'), 'Rejected proof remains visible and customer can submit corrected evidence.');
    $request($url, $customerSession, ['csrf_token' => $token($html)], $png);
    $row = $db->query('SELECT * FROM payments ORDER BY id DESC LIMIT 1')->fetch();
    $proofPaths[] = $row['payment_proof_path'];
    $check($service->verify((int) $row['id'], $adminId, new DateTimeImmutable())['success'], 'Corrected proof can be approved through existing verification service.');
    [, $html] = $request($url, $customerSession);
    $check(str_contains($html, 'Partially paid') && str_contains($html, '60.000'), 'Verified deposit leaves the authoritative 60.000 TND balance.');
    $db->prepare("UPDATE orders SET order_status = 'PICKED_UP' WHERE id = :id")->execute(['id' => $orderId]);
    $check($service->orderSummary($orderId)['order']['payment_status_label'] === 'Partially paid', 'Pickup does not pay the remaining balance.');
    $adminUrl = 'admin/payments/order.php?order_id=' . $orderId;
    [, $html] = $request($adminUrl, $adminSession);
    $receipt = ['csrf_token' => $token($html), 'order_id' => (string) $orderId, 'payment_method' => 'CASH_ON_DELIVERY', 'payment_type' => 'BALANCE', 'amount' => '60.000', 'received_confirmation' => '1'];
    $check($request('admin/payments/order.php', $adminSession, $receipt)[0] === 303 && $service->orderSummary($orderId)['order']['payment_status_label'] === 'Paid', 'Authenticated cash confirmation records the balance using POST, CSRF and PRG.');
    [$status, $queue] = $request('admin/payments/index.php', $adminSession);
    $check($status === 200 && str_contains($queue, 'View proof') && str_contains($queue, 'Pay on delivery / pickup') && !str_contains($queue, '<img src="/admin/payments/evidence'), 'Payment records expose useful actions without embedding private proof images.');
    [$status, $orderDetail] = $request('admin/orders/detail.php?id=' . $orderId, $adminSession);
    $check($status === 200 && str_contains($orderDetail, 'Pending verification') && str_contains($orderDetail, 'Recorded payment methods') && str_contains($orderDetail, 'Pay on delivery / pickup'), 'Existing order detail includes the extended payment summary without changing fulfilment workflows.');
} finally {
    $server?->stop();
    $storage = new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH);
    foreach ($proofPaths as $path) {
        $storage->discard($path);
    }
    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
    drop_throwaway_auth_schema($db, $schema);
}
