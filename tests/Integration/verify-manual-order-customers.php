<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';

use App\Auth\PasswordPolicy;
use App\Auth\PasswordResetService;
use App\Controllers\ManualOrderController;
use App\Database\TransactionManager;
use App\Mail\PasswordResetMailer;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminCustomerRepository;
use App\Repositories\CustomerAccountReadRepository;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\ScentStockRepository;
use App\Security\CsrfTokenManager;
use App\Services\AccessoryStockService;
use App\Services\ManualOrderService;
use App\Services\OrderProductionService;
use App\Services\ScentAvailabilityService;

// All writes use a randomly named PostgreSQL schema, removed in finally.
[$db, $schema] = create_throwaway_auth_schema(true);
$server = null;
$client = null;
$sessionDirectory = sys_get_temp_dir() . '/' . $schema . '_sessions';
$serverLog = sys_get_temp_dir() . '/' . $schema . '.log';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

try {
    $db->exec("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash)
        VALUES ('Manual', 'Admin', 'admin@example.test', '99000001', 'fixture-only')");
    $adminId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO materials (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
        VALUES (1, 'MANUAL-WAX', 'Manual wax', 'kg', 100, 20, 1, 1)");
    $materialId = (string) $db->lastInsertId();
    $db->exec("INSERT INTO products (category_id, slug, selling_price, actual_cost, is_active) VALUES (1, 'manual-product', 60, 4, 1)");
    $productId = (string) $db->lastInsertId();
    $db->exec("INSERT INTO product_materials (product_id, material_id, quantity_required, recipe_type) VALUES ($productId, $materialId, 0.200, 'ACTUAL')");
    $transactions = new TransactionManager($db);
    $accounts = new CustomerAccountRepository($db, $transactions);
    $orders = new OrderRepository($db);
    $productionRepository = new OrderProductionRepository($db);
    $manual = new ManualOrderService(
        $orders, $transactions, $productionRepository,
        new OrderProductionService($productionRepository, $transactions),
        new AccessoryStockService(new AccessoryStockRepository($db), $transactions),
        new ScentAvailabilityService(new ScentStockRepository($db), $transactions),
        customers: $accounts
    );
    $csrf = new CsrfTokenManager();
    $controller = new ManualOrderController($manual, $csrf);
    $base = [
        'customer_id' => '', 'create_customer' => '', 'customer_name' => 'Manual Customer',
        'customer_email' => '', 'customer_phone' => '22000001', 'customer_second_phone' => '',
        'delivery_method' => 'PICKUP', 'required_date' => '2026-12-10', 'delivery_fee' => '0.000',
        'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'items' => [['product_id' => $productId, 'product_name' => 'Catalogue candle', 'quantity' => '2',
            'unit_price' => '60.000', 'recipe' => [['material_id' => $materialId, 'quantity' => '0.200']]]],
    ];
    // Translate the fixture's legacy shape into the actual three-mode HTTP contract.
    $modePost = static function (array $values): array {
        $mode = $values['customer_mode'] ?? (!empty($values['create_customer']) ? 'new' : (!empty($values['customer_id']) ? 'existing' : 'guest'));
        $values['customer_mode'] = $mode;
        if (is_string($mode)) {
            foreach (['customer_name', 'customer_email', 'customer_phone', 'customer_second_phone', 'customer_notes'] as $field) {
                $values[$mode . '_' . $field] ??= $values[$field] ?? '';
            }
        }
        if ($mode === 'new') {
            foreach (['street_address', 'delegation', 'governorate', 'postal_code'] as $field) {
                $values['account_' . $field] ??= $values[$field] ?? '';
            }
            $values['copy_account_address'] ??= '1';
        }
        return $values;
    };
    $submit = static fn (array $values, int $administrator = 0): array => $controller->process(
        $modePost($values) + ['csrf_token' => $csrf->token()], $administrator ?: $adminId
    );
    $new = array_replace($base, [
        'create_customer' => '1', 'customer_name' => '', 'customer_email' => 'New.Customer@EXAMPLE.test',
        'customer_second_phone' => '22000002', 'street_address' => '12 Candle Street',
        'delegation' => 'Tunis', 'governorate' => 'Tunis', 'postal_code' => '1000',
    ]);
    $created = $submit($new);
    $check($created['success'], 'A new account and pickup order are created without submitting a name or password.');
    $account = $accounts->findByEmail('new.customer@example.test');
    $customerId = (int) $account['id'];
    $saved = $db->query('SELECT * FROM orders WHERE id = ' . $created['orderId'])->fetch();
    $check((int) $saved['customer_id'] === $customerId && $saved['customer_name_snapshot'] === 'new.customer'
        && $saved['street_address_snapshot'] === null && $saved['total_amount'] === '120.000'
        && $saved['required_deposit_amount'] === '48.000', 'The account is linked and existing pickup, total and deposit rules are preserved.');
    $detail = new AdminCustomerRepository($db);
    $contact = $detail->findAccount($customerId);
    $addresses = $detail->findAddresses($customerId);
    $check($contact['second_phone_number'] === '22000002' && count($addresses) === 1
        && (int) $addresses[0]['is_primary'] === 1 && $addresses[0]['street_address'] === '12 Candle Street',
        'The optional second phone and primary address are saved even for pickup.');
    $check(password_get_info($account['password_hash'])['algoName'] === 'argon2id'
        && !PasswordPolicy::verify('', $account['password_hash'])
        && !PasswordPolicy::verify('22000001', $account['password_hash']), 'The account has a secure undisclosed credential.');
    $check($detail->countOrders($customerId) === 1
        && (new CustomerAccountReadRepository($db))->orderCount($customerId) === 1,
        'Both administrator and customer histories include the manual order.');

    $counts = static fn (): array => [
        (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM customer_addresses')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    ];
    $before = $counts();
    foreach ([
        'duplicate email' => [array_replace($new, ['customer_phone' => '22000003', 'customer_second_phone' => '']), 'customer_email'],
        'duplicate primary phone' => [array_replace($new, ['customer_email' => 'another@example.test', 'customer_second_phone' => '']), 'customer_phone'],
        'duplicate secondary phone' => [array_replace($new, ['customer_email' => 'another@example.test', 'customer_phone' => '22000003']), 'customer_second_phone'],
        'matching phones' => [array_replace($new, ['customer_second_phone' => '22000001']), 'customer_second_phone'],
        'invalid secondary phone' => [array_replace($new, ['customer_second_phone' => 'abc']), 'customer_second_phone'],
        'missing email' => [array_replace($new, ['customer_email' => '']), 'customer_email'],
        'missing pickup account address' => [array_replace($new, ['street_address' => '']), 'account_street_address'],
        'unknown linked account' => [array_replace($base, ['customer_id' => '999999']), 'customer_id'],
        'invalid item' => [array_replace($new, ['items' => []]), 'items'],
    ] as $label => [$input, $field]) {
        $result = $submit($input);
        $check(!$result['success'] && isset($result['errors'][$field]), $label . ' is rejected with a field error.');
    }
    $expired = $controller->process($new + ['csrf_token' => 'invalid'], $adminId);
    $check(!$expired['success'] && isset($expired['errors']['form']) && $counts() === $before,
        'Invalid inputs and CSRF failures save no accounts, addresses or orders.');

    $failed = $submit(array_replace($new, [
        'customer_email' => 'rollback@example.test', 'customer_phone' => '22000003', 'customer_second_phone' => '',
    ]), 999999);
    $check(!$failed['success'] && $counts() === $before && $accounts->findByEmail('rollback@example.test') === null,
        'An order insert failure rolls back its newly created account and address.');

    $linked = $submit(array_replace($base, ['customer_id' => (string) $customerId]));
    $guest = $submit($base);
    $check($linked['success'] && $guest['success'] && $counts()[0] === $before[0],
        'Existing-account and guest orders still work without creating accounts.');
    $check($db->query('SELECT customer_id FROM orders WHERE id = ' . $guest['orderId'])->fetchColumn() === null,
        'A guest order retains its null account link.');
    $delivery = $submit(array_replace($new, [
        'customer_name' => 'Delivery Customer', 'customer_email' => 'delivery@example.test',
        'customer_phone' => '22000004', 'customer_second_phone' => '',
        'delivery_method' => 'DELIVERY', 'delivery_fee' => '9.000',
    ]));
    $check($delivery['success'], 'New-account delivery orders accept an omitted second phone.');
    $savedDelivery = $db->query('SELECT * FROM orders WHERE id = ' . $delivery['orderId'])->fetch();
    $check($savedDelivery['street_address_snapshot'] === '12 Candle Street'
        && $savedDelivery['total_amount'] === '129.000', 'Delivery address snapshots and fees are preserved.');

    $beforeModes = $counts();
    foreach (['', 'unknown', ['guest']] as $mode) {
        $invalidMode = $submit(array_replace($base, ['customer_mode' => $mode]));
        $check(!$invalidMode['success'] && isset($invalidMode['errors']['customer_mode']), 'Missing, unknown and array customer modes are rejected.');
    }
    $unselected = $submit(array_replace($base, ['customer_mode' => 'existing']));
    $check(!$unselected['success'] && isset($unselected['errors']['customer_id']), 'Existing mode requires an explicit account selection.');
    $forgedGuest = $submit(array_replace($base, ['customer_mode' => 'guest', 'customer_id' => (string) $customerId,
        'create_customer' => '1', 'new_customer_email' => 'forged@example.test', 'new_customer_phone' => '22000009',
        'account_street_address' => 'Ignored account address']));
    $check($forgedGuest['success'] && $counts()[0] === $beforeModes[0] && $counts()[1] === $beforeModes[1]
        && $db->query('SELECT customer_id FROM orders WHERE id = ' . $forgedGuest['orderId'])->fetchColumn() === null,
        'Guest mode ignores stale account fields and IDs and creates only an independent order.');
    $separate = $submit(array_replace($new, ['customer_mode' => 'new', 'customer_id' => (string) $customerId,
        'customer_email' => 'separate@example.test', 'customer_phone' => '22000006', 'customer_second_phone' => '',
        'delivery_method' => 'DELIVERY', 'delivery_fee' => '9.000', 'copy_account_address' => '',
        'account_street_address' => 'Primary account address', 'street_address' => 'Different delivery address']));
    $separateAccount = $accounts->findByEmail('separate@example.test');
    $separateOrder = $separate['success'] ? $orders->findSummary($separate['orderId']) : null;
    $check($separate['success'] && (int) $separateOrder['customer_id'] === (int) $separateAccount['id']
        && (int) $separateAccount['id'] !== $customerId
        && $detail->findAddresses((int) $separateAccount['id'])[0]['street_address'] === 'Primary account address'
        && $separateOrder['street_address_snapshot'] === 'Different delivery address',
        'New mode ignores stale IDs and stores account primary and order delivery addresses independently.');
    $db->exec("UPDATE customers_accounts SET first_name = 'Changed profile' WHERE id = " . $customerId);
    $check($orders->findSummary($created['orderId'])['customer_name_snapshot'] === 'new.customer',
        'Later profile changes leave historical order contact snapshots unchanged.');
    $check(count($orders->searchCustomers('NEW.CUSTOMER@EXAMPLE.TEST')) === 1
        && count($orders->searchCustomers('22000001')) === 1, 'Existing customer search works by email and phone.');

    // Verify the complete password setup path with a fake mailer; no email is sent.
    $resets = new PasswordResetRepository($db, $transactions);
    $mailer = new class implements PasswordResetMailer {
        public string $code = '';
        public function sendCode(string $email, string $code, int $minutes): bool { $this->code = $code; return true; }
    };
    $reset = new PasswordResetService($accounts, $resets, $transactions, $mailer);
    $requested = $reset->requestCode('new.customer@example.test');
    $check($requested['errors'] === [] && strlen($mailer->code) === 6,
        'An administrator-created account can request a password setup code.');
    $check(isset($reset->requestCode('new.customer@example.test')['errors']['form']),
        'Password setup retains its resend cooldown.');
    $result = $reset->resetPassword('new.customer@example.test', $mailer->code, 'CustomerPassword123', 'CustomerPassword123');
    $check($result['errors'] === [] && PasswordPolicy::verify('CustomerPassword123', $accounts->findByEmail('new.customer@example.test')['password_hash']),
        'An administrator-created account can set its password through the existing reset service.');

    // Real authenticated HTTP requests exercise routes, bootstrap wiring, redirects and flash values.
    $adminPassword = 'HttpAdministrator123';
    $db->prepare('UPDATE administrators_accounts SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => PasswordPolicy::hashVerified($adminPassword), 'id' => $adminId]);
    mkdir($sessionDirectory, 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    if ($listener === false) { throw new RuntimeException('Cannot reserve a test port.'); }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment = getenv();
    $environment['PGOPTIONS'] = '-c search_path=' . $schema;
    $environment['APP_BASE_URL'] = '/';
    $environment['LOGIN_THROTTLE_KEY'] = bin2hex(random_bytes(32));
    unset($environment['HOMAS_VERIFICATION_DATABASE']);
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessionDirectory, '-S', $address, '-t', PUBLIC_PATH],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes, PROJECT_ROOT, $environment
    );
    if (!is_resource($server)) { throw new RuntimeException('Cannot start the test server.'); }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
        if (is_resource($connection)) { fclose($connection); break; }
        usleep(100000);
    }
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    $request = static function (string $path, ?array $post = null, ?string $method = null) use ($client, $address): array {
        $location = '';
        curl_setopt_array($client, [
            CURLOPT_URL => 'http://' . $address . '/' . $path,
            CURLOPT_POSTFIELDS => $post === null ? null : http_build_query($post),
            CURLOPT_POST => $post !== null, CURLOPT_HTTPGET => $post === null,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) { $location = trim(substr($header, 9)); }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        if (!is_string($body)) { throw new RuntimeException('HTTP request failed.'); }
        return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), $body, $location];
    };
    $token = static function (string $html): string {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException('CSRF input is missing.');
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    [$status, , $location] = $request('admin/orders/create.php');
    $check($status === 303 && str_contains($location, 'admin/login.php'), 'Manual-order creation requires administrator authentication.');
    [, $login] = $request('admin/login.php');
    [$status] = $request('admin/login.php', ['csrf_token' => $token($login), 'login_identifier' => 'admin@example.test', 'password' => $adminPassword]);
    $check($status === 303, 'The route test authenticates an administrator.');
    [$status, $form] = $request('admin/orders/create.php?customer_id=' . $customerId);
    $check($status === 200 && str_contains($form, 'name="customer_id" value="' . $customerId . '"')
        && str_contains($form, 'name="existing_customer_email" type="email" maxlength="255" value="new.customer@example.test"')
        && str_contains($form, 'value="12 Candle Street"'), 'A customer-page link preselects the account and fills contact and address fields.');
    [$searchStatus, $searchBody] = $request('admin/orders/customers.php?q=NEW.CUSTOMER');
    $check($searchStatus === 200 && count(json_decode($searchBody, true)['customers']) === 1,
        'Authenticated customer search returns the existing account.');
    [$searchStatus, $searchBody] = $request('admin/orders/customers.php?id=' . $customerId);
    $check($searchStatus === 200 && json_decode($searchBody, true)['customer']['street_address'] === '12 Candle Street',
        'Selecting an account loads its current primary address through the existing endpoint.');
    [$status, $customerPage] = $request('admin/customers/detail.php?id=' . $customerId);
    $check($status === 200 && str_contains($customerPage, 'admin/orders/create.php?customer_id=' . $customerId),
        'The customer detail page renders its account-specific manual-order button.');
    [$status] = $request('admin/orders/create.php?customer_id[]=bad');
    $check($status === 404, 'An invalid preselected customer produces a controlled response.');
    [$status] = $request('admin/orders/create.php', null, 'PUT');
    $check($status === 405, 'The manual-order route rejects unsupported HTTP methods.');
    [, $form] = $request('admin/orders/create.php');
    $dom = new DOMDocument(); @$dom->loadHTML($form); $xpath = new DOMXPath($dom);
    $check($xpath->query('//input[@name="customer_mode" and @checked and @value="guest"]')->length === 1
        && $xpath->query('//fieldset[@data-customer-mode-panel="guest" and not(@hidden) and not(@disabled)]')->length === 1
        && $xpath->query('//fieldset[@data-customer-mode-panel!="guest" and @hidden and @disabled]')->length === 2
        && $xpath->query('//input[@name="create_customer"]')->length === 0,
        'The initial form presents one safe guest mode and hides/disables both other workflows without the old checkbox.');
    $httpNew = array_replace($new, ['customer_email' => 'http@example.test', 'customer_phone' => '22000005', 'customer_second_phone' => '']);
    [$status, , $location] = $request('admin/orders/create.php', $modePost($httpNew) + ['csrf_token' => $token($form)]);
    $check($status === 303 && str_contains($location, '/admin/orders/detail.php?id='),
        'Posting a new account and order redirects to the created order.');
    $httpAccount = $accounts->findByEmail('http@example.test');
    $check($httpAccount !== null && $detail->countOrders((int) $httpAccount['id']) === 1,
        'The HTTP route persists the order linked to the newly created account.');
    [$status, $orderPage] = $request(ltrim($location, '/'));
    $check($status === 200 && str_contains($orderPage, 'Manual order'), 'The created order detail page renders successfully.');
    [, $form] = $request('admin/orders/create.php');
    [$status, , $location] = $request('admin/orders/create.php', $modePost($httpNew) + ['csrf_token' => $token($form)]);
    [$formStatus, $form] = $request(ltrim($location, '/'));
    $check($status === 303 && $formStatus === 200 && str_contains($form, 'already belongs to an account')
        && preg_match('/value="new" data-customer-mode[^>]+ checked/', $form) === 1 && str_contains($form, 'value="http@example.test"'),
        'Failed submissions preserve the new-account option and entered values.');
    [, $form] = $request('admin/orders/create.php');
    $check(!str_contains($form, 'Agreed unit price') && !str_contains($form, 'data-recipe-field')
        && str_contains($form, 'Catalogue unit price'), 'Normal manual-order entry contains calculated prices and no recipe editor.');
    $previewPost = $modePost($base) + ['csrf_token' => $token($form)];
    [$status, $body] = $request('admin/orders/price-preview.php', $previewPost);
    $preview = json_decode($body, true);
    $check($status === 200 && $preview['success'] && $preview['amounts']['total'] === '120.000', 'Authenticated pricing preview uses server catalogue prices.');
    [$status, $body] = $request('admin/orders/price-preview.php', array_replace($previewPost, ['csrf_token' => 'invalid']));
    $check($status === 200 && !json_decode($body, true)['success'], 'Price preview validates CSRF without mutating the order.');
    $orderCount = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    [$status, , $location] = $request('admin/orders/create.php', $previewPost + ['action' => 'review_pricing']);
    [$status, $body] = $request(ltrim($location, '/'));
    $check($status === 200 && str_contains($body, '120.000 TND') && (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $orderCount,
        'Review prices works through POST/Redirect/Get without JavaScript and creates no order.');
    [$status, $productPage] = $request('admin/catalogue/products.php?id=' . $productId);
    $check($status === 200 && str_contains($productPage, 'Quantity pricing') && str_contains($productPage, 'Production recipe') && str_contains($productPage, 'name="quantity_required"'),
        'Catalogue product editor exposes quantity prices and the reused recipe editor.');
    [$status, , $location] = $request('admin/catalogue/products.php', ['csrf_token' => $token($productPage), 'action' => 'add_quantity_price', 'id' => $productId, 'minimum_quantity' => '10', 'maximum_quantity' => '', 'unit_price' => '55.000']);
    [$status, $productPage] = $request(ltrim($location, '/'));
    $check($status === 200 && str_contains($productPage, '55.000') && (int) $db->query('SELECT COUNT(*) FROM product_quantity_prices')->fetchColumn() === 1,
        'Catalogue quantity-price form saves through the authenticated controller and PRG.');
    [$status, $promoPage] = $request('admin/catalogue/promotions.php');
    $check($status === 200 && str_contains($promoPage, 'FIXED_TND'), 'Promotion configuration page renders both supported discount types.');
    [$status, , $location] = $request('admin/catalogue/promotions.php', ['csrf_token' => $token($promoPage), 'code' => 'HTTP10', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10', 'is_active' => '1', 'minimum_subtotal' => '0', 'valid_from' => '', 'valid_until' => '']);
    [$status, $promoPage] = $request(ltrim($location, '/'));
    $check($status === 200 && str_contains($promoPage, 'HTTP10'), 'Promotion configuration saves through POST/Redirect/Get.');
    [$status, $requestForm] = $request('admin/requests/create.php');
    $dom = new DOMDocument(); @$dom->loadHTML($requestForm); $xpath = new DOMXPath($dom);
    $check($status === 200 && str_contains($requestForm, 'Who is this request for?')
        && $xpath->query('//fieldset[@data-customer-mode-panel="guest" and not(@hidden) and not(@disabled)]')->length === 1
        && $xpath->query('//fieldset[@data-customer-mode-panel!="guest" and @hidden and @disabled]')->length === 2,
        'Custom Request uses the same three-mode component and only shows its active customer panel.');
    $requestPost = ['category_id' => '1', 'preferred_contact_method' => 'PHONE', 'requested_quantity' => '5', 'customer_message' => 'Special candle request'];
    foreach ([
        'guest' => ['customer_mode' => 'guest', 'customer_id' => (string) $customerId, 'guest_customer_name' => 'HTTP Guest', 'guest_customer_phone' => '22000008', 'guest_customer_email' => 'new.customer@example.test'],
        'existing' => ['customer_mode' => 'existing', 'customer_id' => (string) $customerId],
        'new' => ['customer_mode' => 'new', 'customer_id' => (string) $customerId, 'new_customer_name' => 'HTTP Request', 'new_customer_email' => 'http.request@example.test', 'new_customer_phone' => '22000009', 'account_street_address' => '1 Request Street', 'account_delegation' => 'Tunis', 'account_governorate' => 'Tunis', 'account_postal_code' => '1000'],
    ] as $mode => $fields) {
        [, $requestForm] = $request('admin/requests/create.php');
        [$status, , $location] = $request('admin/requests/create.php', $requestPost + $fields + ['csrf_token' => $token($requestForm)]);
        $savedRequest = $db->query('SELECT * FROM custom_product_requests ORDER BY id DESC LIMIT 1')->fetch();
        $check($status === 303 && str_contains($location, '/admin/requests/detail.php?id=')
            && ($mode === 'guest' ? $savedRequest['customer_id'] === null : ($mode === 'existing' ? (int) $savedRequest['customer_id'] === $customerId : (int) $savedRequest['customer_id'] !== $customerId)),
            'Custom Request HTTP ' . $mode . ' mode persists the correct explicit customer link.');
    }
    [, $productPage] = $request('admin/catalogue/products.php?id=' . $productId);
    $request('admin/catalogue/products.php', ['csrf_token' => $token($productPage), 'action' => 'deactivate', 'id' => $productId]);
    [, $productPage] = $request('admin/catalogue/products.php?id=' . $productId);
    [$status, , $location] = $request('admin/catalogue/products.php', ['csrf_token' => $token($productPage), 'action' => 'update_line', 'product_id' => $productId, 'material_id' => $materialId, 'recipe_type' => 'ACTUAL', 'quantity_required' => '0.250']);
    [$status, $productPage] = $request(ltrim($location, '/'));
    $check($status === 200 && $db->query('SELECT quantity_required FROM product_materials WHERE product_id=' . $productId)->fetchColumn() === '0.250',
        'Catalogue recipe form reuses recipe validation, repricing and readiness through its existing service.');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($client instanceof CurlHandle) { curl_close($client); }
    drop_throwaway_auth_schema($db, $schema);
    foreach (glob($sessionDirectory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($sessionDirectory)) { rmdir($sessionDirectory); }
    if (is_file($serverLog)) { unlink($serverLog); }
}
