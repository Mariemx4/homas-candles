<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Customer Directory.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-customers.php';

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$phoneSeed = substr(str_pad($suffix, 8, '0', STR_PAD_LEFT), -6);
$capPhoneSeed = substr(str_pad($suffix, 8, '0', STR_PAD_LEFT), -4);
$targetPhone = '60' . $phoneSeed;
$administratorIds = [];
$customerIds = [];
$orderIds = [];
$requestIds = [];
$categoryId = 0;

try {
    $passwordHash = password_hash('CustomerDirectoryVerifier123!', PASSWORD_DEFAULT);
    $insertAdmin = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, :status)'
    );
    foreach ([['ACTIVE', 'Active', '50' . $phoneSeed], ['SUSPENDED', 'Suspended', '51' . $phoneSeed]] as [$status, $name, $phone]) {
        $insertAdmin->execute([
            'first_name' => $name,
            'last_name' => 'Customer Verifier',
            'email' => strtolower($name) . "-customers-{$suffix}@example.test",
            'phone' => $phone,
            'password_hash' => $passwordHash,
            'status' => $status,
        ]);
        $administratorIds[$status] = (int) $databaseConnection->lastInsertId();
    }
    $activeId = $administratorIds['ACTIVE'];

    $insertCategory = $databaseConnection->prepare(
        'INSERT INTO categories (slug, is_active) VALUES (:slug, 1)'
    );
    $insertCategory->execute(['slug' => "customer-verifier-{$suffix}"]);
    $categoryId = (int) $databaseConnection->lastInsertId();

    $insertCustomer = $databaseConnection->prepare(
        'INSERT INTO customers_accounts
            (first_name, last_name, email, phone_number, second_phone_number,
             password_hash, account_status, last_login_at)
         VALUES (:first_name, :last_name, :email, :phone, :second_phone,
                 :password_hash, :status, :last_login)'
    );
    $insertCustomer->execute([
        'first_name' => '<script>Literal%_=' . $suffix . '</script>',
        'last_name' => 'Target',
        'email' => "literal-{$suffix}@example.test",
        'phone' => $targetPhone,
        'second_phone' => '61' . $phoneSeed,
        'password_hash' => $passwordHash,
        'status' => 'ACTIVE',
        'last_login' => '2026-08-20 10:00:00',
    ]);
    $targetId = (int) $databaseConnection->lastInsertId();
    $customerIds[] = $targetId;

    $insertCustomer->execute([
        'first_name' => 'LiteralXX=' . $suffix,
        'last_name' => 'Decoy',
        'email' => "decoy-{$suffix}@example.test",
        'phone' => '62' . $phoneSeed,
        'second_phone' => null,
        'password_hash' => $passwordHash,
        'status' => 'ACTIVE',
        'last_login' => null,
    ]);
    $customerIds[] = (int) $databaseConnection->lastInsertId();

    for ($index = 0; $index < 101; $index++) {
        $insertCustomer->execute([
            'first_name' => "CapMarker{$suffix}",
            'last_name' => str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'email' => "cap-{$suffix}-{$index}@example.test",
            'phone' => '7' . $capPhoneSeed . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'second_phone' => null,
            'password_hash' => $passwordHash,
            'status' => $index === 100 ? 'SUSPENDED' : 'ACTIVE',
            'last_login' => null,
        ]);
        $customerIds[] = (int) $databaseConnection->lastInsertId();
    }

    $insertAddress = $databaseConnection->prepare(
        'INSERT INTO customer_addresses
            (customer_id, address_label, street_address, delegation, governorate, postal_code, is_primary)
         VALUES (:customer_id, :label, :street, :delegation, :governorate, :postal_code, :is_primary)'
    );
    foreach ([
        ['<Home>', '1 <Candle> Street', 'Tunis', 'Tunis', '1000', 1],
        [null, '2 Work Street', 'Ariana', 'Ariana', '2080', 0],
    ] as [$label, $street, $delegation, $governorate, $postalCode, $primary]) {
        $insertAddress->execute([
            'customer_id' => $targetId,
            'label' => $label,
            'street' => $street,
            'delegation' => $delegation,
            'governorate' => $governorate,
            'postal_code' => $postalCode,
            'is_primary' => $primary,
        ]);
    }

    $insertOrder = $databaseConnection->prepare(
        'INSERT INTO orders
            (order_number, customer_id, created_by_administrator_id, language_code,
             order_source, order_status, delivery_method, required_date,
             customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
             subtotal, delivery_fee, total_amount, required_deposit_amount, created_at)
         VALUES (:number, :customer_id, :administrator_id, \'en\', \'CART\', :status,
                 \'PICKUP\', :required_date, :customer_name, :customer_email,
                 :customer_phone, 10.000, 0.000, 10.000, 0.000, :created_at)'
    );
    $insertPayment = $databaseConnection->prepare(
        'INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status, amount,
             currency, verified_by_administrator_id, verified_at)
         VALUES (:order_id, \'CASH_ON_DELIVERY\', \'BALANCE\', \'VERIFIED\',
                 5.000, \'TND\', :administrator_id, :verified_at)'
    );
    for ($index = 1; $index <= 12; $index++) {
        $complete = $index <= 4;
        $insertOrder->execute([
            'number' => "C09-{$suffix}-{$index}",
            'customer_id' => $targetId,
            'administrator_id' => $activeId,
            'status' => $complete ? 'PICKED_UP' : 'REQUESTED',
            'required_date' => '2026-09-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'customer_name' => "<script>Snapshot {$index}</script>",
            'customer_email' => "snapshot-{$index}@example.test",
            'customer_phone' => '75000000',
            'created_at' => '2026-08-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ' 12:00:00',
        ]);
        $orderId = (int) $databaseConnection->lastInsertId();
        $orderIds[] = $orderId;
        $insertPayment->execute([
            'order_id' => $orderId,
            'administrator_id' => $activeId,
            'verified_at' => '2026-08-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ' 13:00:00',
        ]);
    }
    $insertItem = $databaseConnection->prepare(
        'INSERT INTO order_items
            (order_id, product_id, product_name_snapshot, quantity,
             unit_price_snapshot, line_total_snapshot)
         VALUES (:order_id, NULL, :name, 1, 10.000, 10.000)'
    );
    $insertItem->execute(['order_id' => $orderIds[1], 'name' => '<Deleted product snapshot>']);

    $insertPending = $databaseConnection->prepare(
        'INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status, amount, currency)
         VALUES (:order_id, \'D17\', \'BALANCE\', \'PENDING_VERIFICATION\', 2.000, \'TND\')'
    );
    $insertPending->execute(['order_id' => $orderIds[0]]);

    $insertRequest = $databaseConnection->prepare(
        'INSERT INTO custom_product_requests
            (request_number, customer_id, category_id, product_id,
             customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
             preferred_contact_method, requested_quantity, required_date, request_status, created_at)
         VALUES (:number, :customer_id, :category_id, NULL, :customer_name,
                 :customer_email, :customer_phone, \'EMAIL\', 1, :required_date,
                 :status, :created_at)'
    );
    for ($index = 1; $index <= 12; $index++) {
        $insertRequest->execute([
            'number' => "R09-{$suffix}-{$index}",
            'customer_id' => $targetId,
            'category_id' => $categoryId,
            'customer_name' => "<Request snapshot {$index}>",
            'customer_email' => "request-{$index}@example.test",
            'customer_phone' => '76000000',
            'required_date' => '2026-10-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'status' => $index === 1 ? 'AGREED' : 'SUBMITTED',
            'created_at' => '2026-07-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ' 12:00:00',
        ]);
        $requestIds[] = (int) $databaseConnection->lastInsertId();
    }

    $empty = $adminCustomerService->directory([]);
    $check($empty['searchRequired'] && $empty['customers'] === [] && $empty['total'] === 0, 'The directory does not enumerate accounts before a deliberate search.');
    $tooShort = $adminCustomerService->directory(['q' => '+']);
    $check($tooShort['searchRequired'] && $tooShort['filtersAdjusted'], 'A formatted search that normalizes below the minimum cannot become a wildcard query.');

    $literal = $adminCustomerService->directory(['q' => "Literal%_={$suffix}"]);
    $check($literal['total'] === 1 && (int) $literal['customers'][0]['id'] === $targetId, 'SQL wildcard characters are treated as literal customer search text.');
    $check(
        !array_key_exists('email', $literal['customers'][0])
        && !array_key_exists('phone_number', $literal['customers'][0])
        && !array_key_exists('password_hash', $literal['customers'][0])
        && str_contains((string) $literal['customers'][0]['email_hint'], '***@'),
        'Directory projections expose masked hints without full contact or authentication data.'
    );
    $formattedPhone = '+216 ' . substr($targetPhone, 0, 2) . ' ' . substr($targetPhone, 2, 3) . ' ' . substr($targetPhone, 5);
    $phone = $adminCustomerService->directory(['q' => $formattedPhone]);
    $check($phone['total'] === 1 && (int) $phone['customers'][0]['id'] === $targetId, 'Formatted Tunisian phone searches normalize safely.');

    $capped = $adminCustomerService->directory(['q' => "CapMarker{$suffix}", 'page' => '5']);
    $check($capped['capped'] && $capped['total'] === 100 && $capped['totalPages'] === 5 && count($capped['customers']) === 20, 'Broad matches stop at 100 records and remain paginated in 20-row pages.');
    $badQuery = $adminCustomerService->directory(['q' => ['invalid'], 'page' => '-4']);
    $check($badQuery['searchRequired'] && $badQuery['filtersAdjusted'], 'Malformed search and page values fail closed without querying account data.');

    $detail = $adminCustomerService->detail($targetId, ['orders_page' => '2', 'requests_page' => '2']);
    $check(is_array($detail) && count($detail['addresses']) === 2, 'Protected detail loads current account data and bounded saved addresses.');
    $check(
        !array_key_exists('password_hash', $detail['customer'])
        && !array_key_exists('reset', $detail['customer'])
        && $detail['customer']['email'] === "literal-{$suffix}@example.test",
        'Customer detail excludes password and reset data while exposing necessary account contact fields.'
    );
    $check(
        $detail['metrics']['verified_amount'] === '60.000'
        && $detail['metrics']['completed_verified_amount'] === '20.000'
        && (int) $detail['metrics']['order_count'] === 12
        && (int) $detail['metrics']['request_count'] === 12,
        'Metrics use verified payments and completed-order status boundaries exactly.'
    );
    $check(
        $detail['orderPagination']['page'] === 2 && count($detail['orders']) === 2
        && $detail['requestPagination']['page'] === 2 && count($detail['requests']) === 2,
        'Order and custom-request histories paginate independently instead of loading unbounded records.'
    );
    $check(
        (int) $detail['orders'][0]['snapshot_only_item_count'] === 1
        && str_contains((string) $detail['orders'][0]['customer_name_snapshot'], 'Snapshot'),
        'Missing product relationships preserve order-item and customer snapshots in history.'
    );
    $check($detail['requests'][0]['product_name'] === null, 'Missing optional product relationships do not break custom-request history.');

    $invalidId = $adminCustomerController->show(['id' => '1 OR 1=1']);
    $missingId = $adminCustomerController->show(['id' => '9223372036854775807']);
    $check($invalidId['httpStatus'] === 400 && $missingId['httpStatus'] === 404, 'Crafted and missing customer identifiers receive safe, distinct outcomes.');
    $check(is_array($administratorAuthorization->activeAdministrator($activeId)), 'An active administrator passes customer-route authorization.');
    $check($administratorAuthorization->activeAdministrator($administratorIds['SUSPENDED']) === null, 'A suspended administrator cannot access customer routes.');

    $customerDetail = $detail;
    $customerResult = ['httpStatus' => 200, 'loadError' => null];
    $backUrl = BASE_URL . 'admin/customers/index.php';
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Pages/admin-customer/admin-customer-detail.php';
    $html = (string) ob_get_clean();
    $check(
        str_contains($html, '&lt;script&gt;Literal%_=')
        && str_contains($html, '&lt;Home&gt;')
        && str_contains($html, '&lt;script&gt;Snapshot')
        && !str_contains($html, '<script>'),
        'Current account, address, and immutable snapshot output is HTML-escaped.'
    );
    $check(
        !str_contains($html, 'password_hash')
        && !str_contains($html, 'payment_proof_path')
        && !str_contains($html, 'customer_password_reset_codes'),
        'Rendered customer history contains no authentication or payment-evidence internals.'
    );
    $check(
        str_contains($html, 'Completed verified value')
        && str_contains($html, 'Pending and rejected payments are excluded'),
        'The protected page documents the authoritative customer-value definition.'
    );
} finally {
    if ($requestIds !== []) {
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $databaseConnection->prepare("DELETE FROM request_contact_attempts WHERE request_id IN ({$placeholders})")->execute($requestIds);
        $databaseConnection->prepare("DELETE FROM custom_product_requests WHERE id IN ({$placeholders})")->execute($requestIds);
    }
    if ($orderIds !== []) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        foreach (['material_stock_movements', 'order_status_history', 'payments', 'order_items'] as $table) {
            $databaseConnection->prepare("DELETE FROM {$table} WHERE order_id IN ({$placeholders})")->execute($orderIds);
        }
        $databaseConnection->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($orderIds);
    }
    if ($customerIds !== []) {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $databaseConnection->prepare("DELETE FROM customer_password_reset_codes WHERE customer_id IN ({$placeholders})")->execute($customerIds);
        $databaseConnection->prepare("DELETE FROM customers_accounts WHERE id IN ({$placeholders})")->execute($customerIds);
    }
    if ($categoryId > 0) {
        $databaseConnection->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
    }
    foreach ($administratorIds as $administratorId) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Customer-directory verification completed without leaving test rows.' . PHP_EOL;
