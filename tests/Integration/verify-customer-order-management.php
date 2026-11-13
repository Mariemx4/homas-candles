<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

use App\Database\TransactionManager;
use App\Repositories\{OrderRepository, OrderProductionRepository, CustomerAccountReadRepository, AdminLifecycleRepository, AccessoryStockRepository, ScentStockRepository, OrderStatusRepository, MaterialStockRepository};
use App\Services\{CustomerOrderService, AccountProfileService, AccessoryStockService, ScentAvailabilityService, OrderService, OrderStatusService, OrderProductionService};

[$db, $schema] = create_throwaway_auth_schema(true);
$count = 0;
$server = null;
$directory = sys_get_temp_dir() . '/homas-customer-orders-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$check = TestCheck::printing($count);
$reject = static function (callable $fn, string $message) use ($check): void {
    try {
        $fn();
    } catch (DomainException | PDOException) {
        $check(true, $message);
        return;
    }
    $check(false, $message);
};
try {
    $db->exec("INSERT INTO customers_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Customer','One','one@example.test','22000001','fixture'),('Customer','Two','two@example.test','22000002','fixture')");
    $ids = $db->query('SELECT id FROM customers_accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    [$customer, $other] = array_map('intval', $ids);
    $db->exec("INSERT INTO customer_addresses(customer_id,street_address,delegation,governorate,postal_code,is_primary) VALUES($customer,'1 Test Street','Tunis','Tunis','1000',1),($other,'2 Test Street','Tunis','Tunis','1000',1)");
    $db->exec("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Order','Admin','admin@example.test','99000001','fixture')");
    $admin = (int)$db->lastInsertId();
    $db->exec("INSERT INTO materials(material_type_id,sku,name,unit,stock_quantity,unit_cost,tracks_stock,is_active) VALUES(1,'CUSTOMER-WAX','Wax','g',10000,0.1,1,1)");
    $material = (int)$db->lastInsertId();
    $db->exec("INSERT INTO products(category_id,slug,selling_price,actual_cost,minimum_quantity,is_active) VALUES(1,'customer-candle',12,5,1,1)");
    $product = (int)$db->lastInsertId();
    $db->exec("INSERT INTO product_materials(product_id,material_id,recipe_type,quantity_required) VALUES($product,$material,'ACTUAL',10)");
    $db->exec("INSERT INTO product_quantity_prices(product_id,minimum_quantity,unit_price) VALUES($product,10,8.5)");
    $tx = new TransactionManager($db);
    $orders = new OrderRepository($db);
    $reader = new CustomerAccountReadRepository($db);
    $catalogue = new OrderProductionRepository($db);
    $accessories = new AccessoryStockService(new AccessoryStockRepository($db), $tx);
    $service = new CustomerOrderService($db, $tx, $orders, $catalogue, $accessories, new ScentAvailabilityService(new ScentStockRepository($db), $tx), require APP_PATH . '/config/checkout.php');
    $profiles = new AccountProfileService(new AdminLifecycleRepository($db), $tx);
    $profile = ['first_name' => 'Customer', 'last_name' => 'One', 'email' => 'one@example.test', 'phone_number' => '22000001', 'second_email' => 'extra@example.test', 'second_phone_number' => '22000003'];
    $address = ['street_address' => 'Extra address', 'delegation' => 'Tunis', 'governorate' => 'Tunis', 'postal_code' => '1000'];
    $check($profiles->save('customers', $customer, $profile + ['new_addresses' => [$address, $address]], $customer, true)['success'], 'Profile accepts a second email, second phone and three addresses.');
    $check(count($reader->addresses($customer)) === 3 && $reader->activeAccount($customer)['second_email'] === 'extra@example.test', 'Optional contacts and all three addresses persist.');
    $check(!$profiles->save('customers', $customer, $profile + ['new_addresses' => [$address]], $customer, true)['success'], 'Fourth address is rejected.');
    $all = $db->query("SELECT id FROM customer_addresses WHERE customer_id=$customer")->fetchAll(PDO::FETCH_COLUMN);
    $records = new AdminLifecycleRepository($db);
    $beforeDefault = $records->find('customers', $customer);
    $oldVersion = AccountProfileService::version($beforeDefault, $records->addresses($customer));
    $chosenDefault = (int) $all[1];
    $check($profiles->save('customers', $customer, $profile + ['primary_address_id' => (string) $chosenDefault, 'profile_version' => $oldVersion], $customer, true)['success'], 'Customers can choose an owned saved address as their default.');
    $check((int) $db->query("SELECT id FROM customer_addresses WHERE customer_id=$customer AND is_primary=1")->fetchColumn() === $chosenDefault, 'The chosen default is persisted with exactly one primary address.');
    $check((int) $db->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$customer AND is_primary=1")->fetchColumn() === 1, 'Changing defaults never creates multiple primary addresses.');
    $check(!$profiles->save('customers', $customer, $profile + ['profile_version' => $oldVersion], $customer, true)['success'], 'An address-default change invalidates an older profile form.');
    $foreignAddress = (string) $db->query("SELECT id FROM customer_addresses WHERE customer_id=$other LIMIT 1")->fetchColumn();
    $check(!$profiles->save('customers', $customer, array_replace($profile, ['first_name' => 'Should not persist', 'primary_address_id' => $foreignAddress]), $customer, true)['success'], 'A foreign default address rejects the entire profile update.');
    $check($records->find('customers', $customer)['first_name'] === 'Customer', 'Rejected default-address selection leaves contact details unchanged.');
    $check(!$profiles->save('customers', $customer, $profile + ['primary_address_id' => [$chosenDefault]], $customer, true)['success'], 'Malformed default-address input is safely rejected.');
    $check(!$profiles->save('customers', $customer, $profile + ['primary_address_id' => (string) $chosenDefault, 'addresses' => [$chosenDefault => ['remove' => '1']]], $customer, true)['success'], 'A customer cannot remove the same address selected as default.');
    $check((int) $db->query("SELECT COUNT(*) FROM account_change_history WHERE account_kind='customers' AND account_id=$customer AND before_state->'addresses' IS DISTINCT FROM after_state->'addresses'")->fetchColumn() >= 2, 'Default-address changes are attributed in the existing account audit.');
    $check(!$profiles->save('customers', $customer, $profile + ['addresses' => array_fill_keys($all, ['remove' => '1'])], $customer, true)['success'], 'Removing the final address is rejected atomically.');
    foreach (['email', 'phone_number'] as $required) {
        $check(!$profiles->save('customers', $customer, array_replace($profile, [$required => '']), $customer, true)['success'], 'Primary ' . $required . ' is required.');
    }
    $check(!$profiles->save('customers', $other, $profile, $customer, true)['success'], 'Customers cannot edit another account.');
    $check(!$profiles->save('customers', $customer, array_replace($profile, ['second_email' => $profile['email']]), $customer, true)['success'], 'Duplicate primary and secondary emails are rejected.');
    $check(!$profiles->save('customers', $customer, array_replace($profile, ['second_phone_number' => $profile['phone_number']]), $customer, true)['success'], 'Duplicate phone numbers are rejected.');
    $newOrder = static function (bool $manual = false) use ($db, $tx, $orders, $customer, $admin, $product): int {
        return $tx->run(function () use ($db, $orders, $customer, $admin, $product, $manual): int {
            $values = [
                'order_number' => 'ORD-TEST-' . strtoupper(bin2hex(random_bytes(5))),
                'customer_id' => $customer,
                'delivery_method' => 'PICKUP',
                'required_date' => date('Y-m-d', strtotime('+7 days')),
                'customer_name' => 'Customer One',
                'customer_email' => 'one@example.test',
                'customer_phone' => '22000001',
                'street_address' => null,
                'delegation' => null,
                'governorate' => null,
                'postal_code' => null,
                'subtotal' => '48.000',
                'delivery_fee' => '0.000',
                'total_amount' => '48.000',
                'required_deposit' => '0.000',
                'customer_notes' => null
            ];
            $id = $manual ? $orders->insertManualOrder($values + ['administrator_id' => $admin, 'administrator_notes' => null]) : $orders->insertCartOrder($values + ['language_code' => 'en']);
            $orders->insertManualOrderItem([
                'order_id' => $id,
                'product_id' => $product,
                'product_name' => 'Test <Candle>',
                'quantity' => 4,
                'unit_price' => '12.000',
                'line_total' => '48.000',
                'unit_cost' => '5.000',
                'customization_notes' => null,
                'catalogue_unit_price_snapshot' => '12.000',
                'quantity_unit_price_snapshot' => '12.000',
                'recipe_source' => 'CATALOGUE'
            ]);
            $item = (int)$db->lastInsertId();
            $db->exec("INSERT INTO order_item_materials(order_item_id,material_id,material_name_snapshot,unit_snapshot,quantity_required,unit_cost_snapshot) SELECT $item,id,name,unit,10,0.1 FROM materials WHERE sku='CUSTOMER-WAX'");
            $orders->insertCustomerOrderHistory($id);
            return $id;
        });
    };
    $id = $newOrder();
    $accountController = new App\Controllers\CustomerAccountController($reader);
    $overview = $accountController->overview($customer, []);
    $check($overview['orderSummary'] === ['total' => 1, 'active' => 1, 'completed' => 0, 'cancelled' => 0], 'Customer overview counts in-progress, completed and cancelled orders separately.');
    $check((int) $overview['nextOrder']['id'] === $id && $overview['orders'][0]['first_item_name'] === 'Test <Candle>', 'The next-order spotlight and order cards use owned immutable item snapshots.');
    $check($accountController->overview($other, [])['orders'] === [] && $accountController->overview($other, [])['nextOrder'] === null, 'Overview summaries and spotlights never expose another customer’s orders.');
    $check($accountController->overview($customer, ['filter' => 'completed'])['orders'] === [], 'Completed-history filters exclude an in-progress order.');
    $malformedOverview = $accountController->overview($customer, ['filter' => ['active'], 'page' => ['999']]);
    $check($malformedOverview['filter'] === 'all' && $malformedOverview['page'] === 1, 'Malformed filters and pages fall back safely without changing ownership scope.');
    $check($accountController->overview($customer, ['filter' => 'active', 'page' => '999'])['page'] === 1, 'History pagination clamps to the selected filter’s actual page count.');
    $displayOrder = $reader->orders($customer, 10, 0)[0];
    $displayOrder['required_deposit_amount'] = '40.000';
    $displayOrder['amount_paid'] = '0.000';
    $displayOrder['pending_amount'] = '40.000';
    $state = App\View\CustomerAccountView::orderState($displayOrder);
    $check($state['help'] === 'account.hint_payment_review' && $state['action'] !== 'account.pay_deposit', 'A pending transfer prompts verification instead of a duplicate deposit.');
    $displayOrder['pending_amount'] = '0.000';
    $check(App\View\CustomerAccountView::orderState($displayOrder)['action'] === 'account.pay_deposit', 'An outstanding deposit links to the existing authoritative payment summary.');
    $displayOrder['order_status'] = 'CANCELLED';
    $check(App\View\CustomerAccountView::orderState($displayOrder)['action'] === 'account.open_order', 'Cancelled orders never prompt another deposit.');
    $version = static fn (int $id): string => CustomerOrderService::version($reader->order($customer, $id), $reader->orderItems($customer, $id));
    $input = static function (int $id, int $quantity) use ($reader, $customer): array {
        $row = $reader->order($customer, $id);
        $item = $reader->orderItems($customer, $id)[0];
        return ['quantities' => [$item['id'] => (string)$quantity], 'delivery_method' => 'PICKUP', 'required_date' => $row['required_date'], 'customer_notes' => 'Customer notes'];
    };
    $revise = static function (int $id, int $quantity) use ($service, $customer, $version, $input): array {
        $v = $version($id);
        $values = $input($id, $quantity);
        $preview = $service->preview($customer, $id, $values, $v);
        if (!$preview['success']) {
            throw new RuntimeException(json_encode($preview));
        }
        $result = $service->update($customer, $id, $values, $v, $preview['signature']);
        if (!$result['success']) {
            throw new RuntimeException(json_encode($result));
        }
        return $result['quote'];
    };
    $check($reader->order($other, $id) === null && !$service->cancel($other, $id, $version($id))['success'], 'Ownership protects order reads and cancellation.');
    $check(!$service->preview($customer, $id, $input($id, 1), $version($id))['success'], 'Revised merchandise must reach the 40 DT minimum.');
    $quote = $revise($id, 12);
    $check($quote['total_millis'] === 102000 && $quote['deposit_millis'] === 40800, 'Quantity-tier pricing recalculates the total and the 40% deposit.');
    $line = $reader->orderItems($customer, $id)[0];
    $check($line['quantity_unit_price_snapshot'] === '8.500' && $line['quantity_tier_snapshot'] === '10-up', 'Revised price tags and pricing snapshots agree.');
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount,verified_at) VALUES($id,'CASH_ON_DELIVERY','DEPOSIT','VERIFIED',50,NOW())");
    $statuses = new OrderStatusService(new OrderStatusRepository($db), new MaterialStockRepository($db, $tx), $tx, new OrderProductionService($catalogue, $tx), $accessories);
    $check($statuses->selectStatus($id, $admin, 'CONFIRMED', 'REQUESTED')['success'], 'Administrator can confirm the revised order after deposit verification.');
    $quote = $revise($id, 15);
    $check($quote['total_millis'] === 127500 && $quote['deposit_due_millis'] === 1000 && $quote['remaining_millis'] === 77500 && $reader->order($customer, $id)['order_status'] === 'REQUESTED', 'Increased deposit returns a confirmed order to Requested and preserves paid funds.');
    $quote = $revise($id, 4);
    $check($quote['deposit_millis'] === 0 && $quote['credit_millis'] === 2000 && $quote['remaining_millis'] === 0, 'Reducing below the threshold removes the deposit and reports overpayment.');
    $adminDetail = (new OrderService($orders))->detail($id, new DateTimeImmutable());
    $check($adminDetail['order']['credit_balance'] === '2.000' && count($adminDetail['customerEdits']) === 3, 'Admin sees the same revised balance and edit audit.');
    $stale = $version($id);
    $preview = $service->preview($customer, $id, $input($id, 10), $stale);
    $db->exec("UPDATE products SET selling_price=13 WHERE id=$product; UPDATE product_quantity_prices SET unit_price=9 WHERE product_id=$product");
    $check(!$service->update($customer, $id, $input($id, 10), $stale, $preview['signature'])['success'], 'Changed catalogue prices require a fresh customer review.');
    $check(!$service->update($customer, $id, $input($id, 10), $stale, 'forged')['success'], 'Forged reviewed price signatures are rejected.');
    $db->exec("UPDATE products SET minimum_quantity=6 WHERE id=$product");
    $check(!$service->preview($customer, $id, $input($id, 5), $version($id))['success'], 'Product minimum quantity still applies to customer edits.');
    $db->exec("UPDATE products SET minimum_quantity=1 WHERE id=$product");
    $paymentVersion = $version($id);
    $paymentPreview = $service->preview($customer, $id, $input($id, 10), $paymentVersion);
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount) VALUES($id,'D17','BALANCE','PENDING_VERIFICATION',10)");
    $pendingId = (int)$db->lastInsertId();
    $check(!$service->update($customer, $id, $input($id, 10), $paymentVersion, $paymentPreview['signature'])['success'], 'A payment arriving after preview prevents a stale save.');
    $pendingPreview = $service->preview($customer, $id, $input($id, 10), $version($id));
    $check($pendingPreview['quote']['remaining_millis'] === 40000 && $pendingPreview['quote']['pending_millis'] === 10000, 'Pending payments are displayed separately and never counted as verified funds.');
    $db->exec("UPDATE payments SET payment_status='REJECTED',verified_by_administrator_id=$admin,rejection_reason='Test rejection' WHERE id=$pendingId");
    $db->exec("UPDATE orders SET customer_notes='Admin changed notes' WHERE id=$id");
    $check(!$service->cancel($customer, $id, $stale)['success'], 'Stale cancellation is rejected.');
    $manual = $newOrder(true);
    $quote = $revise($manual, 10);
    $check($quote['total_millis'] === 90000, 'Audited customer edits also reprice a linked manual catalogue order.');
    $reject(fn () => $db->exec("UPDATE order_items SET quantity=11,line_total_snapshot=99 WHERE order_id=$manual"), 'Direct edits still cannot rewrite a locked manual sale.');
    $check($statuses->selectStatus($manual, $admin, 'PREPARING', 'REQUESTED')['success'], 'Admin production status advances using the shared order record.');
    $check(!$service->cancel($customer, $manual, $version($manual))['success'] && !$service->preview($customer, $manual, $input($manual, 12), $version($manual))['success'], 'Preparing orders reject editing and cancellation.');
    $check($statuses->selectStatus($manual, $admin, 'REQUESTED', 'PREPARING')['success'], 'Administrator status corrections remain available.');
    $check(!CustomerOrderService::editable($reader->order($customer, $manual)) && !$service->cancel($customer, $manual, $version($manual))['success'], 'A status correction never reopens an order whose preparation already started.');
    $config = require APP_PATH . '/config/database.local.php';
    $second = new PDO('pgsql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 5432) . ';dbname=' . ($config['database'] ?? $config['db_name']), $config['username'] ?? $config['user_name'], $config['password'] ?? $config['psswd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $second->exec('SET search_path TO ' . $schema);
    $second->exec("SET lock_timeout TO '150ms'");
    $clone = "INSERT INTO orders(order_number,customer_id,language_code,order_source,order_status,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount)
      SELECT 'ORD-CONCURRENT',customer_id,'en','CART','REQUESTED','PICKUP',required_date,customer_name_snapshot,customer_phone_snapshot,48,0,48,0 FROM orders WHERE id=$id";
    $db->beginTransaction();
    $third = $newOrder();
    try {
        $second->exec($clone);
        $check(false, 'Competing checkout must wait.');
    } catch (PDOException $e) {
        $check($e->getCode() === '55P03', 'Concurrent fourth checkout waits for the customer quota lock.');
    }
    $db->commit();
    try {
        $second->exec($clone);
        $check(false, 'Direct fourth checkout must fail.');
    } catch (PDOException $e) {
        $check($e->getCode() === '23514' && str_contains($e->getMessage(), 'three orders'), 'Database rejects the fourth checkout after the competing transaction commits.');
    }
    $reject(fn () => $newOrder(), 'Fourth order in one day is refused.');
    $check($service->cancel($customer, $third, $version($third))['success'], 'Customer can cancel a Requested order.');
    $reject(fn () => $newOrder(), 'Cancelling an order does not reset the daily allowance.');
    $check($reader->order($customer, $third)['order_status'] === 'CANCELLED', 'Cancellation is visible through the shared record.');
    $db->exec("UPDATE orders SET created_at=CURRENT_DATE-INTERVAL '1 day' WHERE id=$third");
    $next = $newOrder();
    $check($next > 0, 'Previous-day orders do not consume the current daily allowance.');
    // HTTP routes use actual customer sessions, CSRF and isolated schema configuration.
    $session = bin2hex(random_bytes(16));
    file_put_contents($directory . '/sess_' . $session, 'customer_auth|' . serialize(['customer_id' => $customer, 'authenticated_at' => time(), 'last_activity_at' => time()]));
    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $directory, $directory . '/server.log', [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'HOMAS_VERIFICATION_DATABASE' => null,
    ]);
    $request = static function (string $path, ?array $post = null, bool $auth = true) use ($address, $session): array {
        $headers = $auth ? ['Cookie: PHPSESSID=' . $session] : [];
        if ($post !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = file_get_contents('http://' . $address . '/' . $path, false, $context);
        $response = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        preg_match('/\s(\d{3})\s/', $response[0] ?? '', $match);
        return [(int)($match[1] ?? 0), (string)$body, $response];
    };
    $field = static function (string $html, string $name): string {
        if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) {
            throw new RuntimeException('Missing field ' . $name);
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    [$code, $html] = $request('profile.php');
    $check($code === 200 && str_contains($html, 'Second email (optional)') && str_contains($html, 'pages/account.css') && !str_contains($html, 'account.profile_rules'), 'Profile renders translated labels and account styles.');
    $check(str_contains($html, 'name="primary_address_id"') && str_contains($html, 'customer-account.js') && str_contains($html, 'id="security"'), 'Customer profile includes default-address selection, unsaved-change protection and account recovery.');
    $profileToken = $field($html, 'csrf_token');
    $check($request('profile.php', ['action' => 'deactivate_account', 'csrf_token' => $profileToken])[0] === 303 && $reader->activeAccount($customer) !== null, 'Deactivation requires an explicit confirmation as well as a valid CSRF token.');
    [$profileCode, $profileHtml] = $request('profile.php');
    $check($profileCode === 200 && str_contains($profileHtml, 'value="one@example.test"'), 'A rejected security-only form preserves the visible contact details.');
    $check($request('profile.php', array_replace($profile, ['csrf_token' => 'invalid', 'first_name' => 'Forged change']))[0] === 303 && $reader->activeAccount($customer)['first_name'] === 'Customer', 'Profile POST requests reject invalid CSRF without changing customer data.');
    foreach (['en', 'fr', 'ar'] as $language) {
        [$overviewCode, $overviewHtml] = $request('account.php?lang=' . $language . '&filter=active');
        $check($overviewCode === 200 && str_contains($overviewHtml, 'account-order-list') && !str_contains($overviewHtml, 'account.overview_intro') && !str_contains($overviewHtml, 'account.filter_active'), 'Customer dashboard and order filters render translated text in ' . $language . '.');
    }
    $check($request('account.php', null, false)[0] === 303 && $request('profile.php', null, false)[0] === 303, 'Dashboard and profile both require an authenticated customer session.');
    $check($request('account-order-edit.php?id=' . $id, null, false)[0] === 303, 'Anonymous order editing requires sign-in.');
    [$code, $html] = $request('account-order.php?id=' . $id);
    $check($code === 200 && str_contains($html, 'Test &lt;Candle&gt;') && str_contains($html, 'customer-order.js'), 'Order detail escapes names and enables automatic tracking.');
    [$code, $json, $headers] = $request('account-order.php?id=' . $id . '&format=json');
    $state = json_decode($json, true);
    $check($code === 200 && $state['figures']['credit'] === '2.000' && !str_contains($json, 'Admin changed notes') && str_contains(implode(' ', $headers), 'no-store'), 'Tracking returns private, authoritative status and balances without admin notes.');
    $check($request('account-order.php?id=999999&format=json')[0] === 404, 'Unknown tracking orders are inaccessible.');
    $editUrl = 'account-order-edit.php?id=' . $id;
    $request($editUrl, ['action' => 'preview', 'csrf_token' => 'bad', 'version' => $version($id)] + $input($id, 10));
    [, $html] = $request($editUrl);
    $check(str_contains($html, 'Reload the page') && !str_contains($html, 'name="review_token"'), 'CSRF blocks previews and save drafts.');
    $request($editUrl, ['action' => 'preview', 'csrf_token' => $field($html, 'csrf_token'), 'version' => $field($html, 'version')] + $input($id, 10));
    [, $html] = $request($editUrl);
    $check(str_contains($html, '90.000') && str_contains($html, 'Confirm and save these changes'), 'Preview displays authoritative revised prices before saving.');
    $request($editUrl, ['action' => 'save', 'csrf_token' => $field($html, 'csrf_token'), 'review_token' => $field($html, 'review_token'), 'total_amount' => '0.001', 'quantities' => ['1' => '1']]);
    $check($reader->order($customer, $id)['total_amount'] === '90.000', 'Save uses the server-reviewed draft and ignores forged client totals and quantities.');
    $check($statuses->selectStatus($id, $admin, 'CONFIRMED', 'REQUESTED')['success'], 'Admin can confirm the saved revised order.');
    [, $json] = $request('account-order.php?id=' . $id . '&format=json');
    $check(json_decode($json, true)['status'] === 'Confirmed', 'Admin status changes are reflected in customer tracking.');
    [, $html] = $request('account-order.php?id=' . $id);
    $request('account-order.php?id=' . $id, ['action' => 'cancel', 'csrf_token' => 'bad', 'version' => $version($id)]);
    $check($reader->order($customer, $id)['order_status'] === 'CONFIRMED', 'Cancellation rejects invalid CSRF.');
    $request('account-order.php?id=' . $id, ['action' => 'cancel', 'csrf_token' => $field($html, 'csrf_token'), 'version' => $version($id)]);
    $check($reader->order($customer, $id)['order_status'] === 'CANCELLED', 'Confirmed order cancellation works through the authenticated endpoint.');
    [, $json] = $request('account-order.php?id=' . $id . '&format=json');
    $cancelState = json_decode($json, true);
    $check($cancelState['figures']['remaining'] === '0.000' && $cancelState['figures']['credit'] === '50.000', 'Cancelled paid orders request no further payment and show funds for refund review.');
    $check(!str_contains(file_get_contents($directory . '/server.log'), 'PHP Warning') && !str_contains(file_get_contents($directory . '/server.log'), 'PHP Fatal'), 'Customer routes finish without PHP warnings or fatal errors.');
    echo "Customer management: $count checks passed.\n";
} finally {
    $server?->stop();
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    drop_throwaway_auth_schema($db, $schema);
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
