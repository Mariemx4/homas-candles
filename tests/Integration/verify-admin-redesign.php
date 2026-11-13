<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';


// Build the current PostgreSQL schema independently of the missing reference seed.
[$db, $schema] = create_throwaway_auth_schema();
$server = null;
$client = null;
$sessionDirectory = sys_get_temp_dir() . '/' . $schema . '_redesign_sessions';
$serverLog = sys_get_temp_dir() . '/' . $schema . '_redesign.log';
$checks = 0;
$check = TestCheck::printing($checks);

try {
    foreach (glob(APP_PATH . '/database/migrations/*.sql') ?: [] as $migration) {
        if (!in_array(basename($migration), [
            '002_create_administrators_accounts_table.sql',
            '003_create_customers_accounts_table.sql',
            '004_create_customer_addresses_table.sql',
            '020_create_login_throttle.sql',
        ], true)) {
            $db->exec((string) file_get_contents($migration));
        }
    }
    $db->exec("INSERT INTO languages(code,name) VALUES('en','English'),('fr','Français'),('ar','العربية');
        INSERT INTO categories(id,slug,is_active) VALUES(1,'candles',1),(2,'accessories',1);
        INSERT INTO product_types(id,slug,is_active) VALUES(1,'flowers',1),(2,'other',1);
        INSERT INTO material_types(id,slug,is_consumable,is_recipe_input,is_active) VALUES(1,'raw-material',1,1,1)");
    $name = 'Audit Crème %_= شمعة';
    foreach (['categories' => 'category', 'product_types' => 'product_type'] as $table => $foreignKey) {
        $text = $db->prepare("INSERT INTO {$foreignKey}_translations({$foreignKey}_id,language_code,name) VALUES(:id,'en',:name)");
        $text->execute(['id' => 1, 'name' => $name]);
        $text->execute(['id' => 2, 'name' => 'Other record']);
    }
    foreach (['colors' => 'color', 'scents' => 'scent'] as $table => $foreignKey) {
        $db->exec("INSERT INTO {$table}(id,slug,is_active) VALUES(1,'audit-match',1),(2,'other-record',1)");
        $text = $db->prepare("INSERT INTO {$foreignKey}_translations({$foreignKey}_id,language_code,name) VALUES(:id,'en',:name)");
        $text->execute(['id' => 1, 'name' => $name]);
        $text->execute(['id' => 2, 'name' => 'Other record']);
    }
    $db->exec("INSERT INTO products(id,category_id,product_type_id,slug,is_active) VALUES(1,1,1,'audit-match',0),(2,2,2,'other-record',0)");
    $text = $db->prepare("INSERT INTO product_translations(product_id,language_code,name,description) VALUES(:id,'en',:name,'Regression fixture')");
    $text->execute(['id' => 1, 'name' => $name]);
    $text->execute(['id' => 2, 'name' => 'Other record']);
    $material = $db->prepare("INSERT INTO materials(id,material_type_id,sku,name,unit,stock_quantity,unit_cost,tracks_stock,minimum_stock_level,is_active) VALUES(:id,1,:sku,:name,'g',100,0.100,1,10,1)");
    $material->execute(['id' => 1, 'sku' => 'AUDIT-MATCH', 'name' => $name]);
    $material->execute(['id' => 2, 'sku' => 'OTHER-RECORD', 'name' => 'Other record']);

    $password = 'Reliability-' . bin2hex(random_bytes(16)) . '1a';
    $adminInsert = $db->prepare("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Audit','Administrator','reliability@example.test','98110001',:hash) RETURNING id");
    $adminInsert->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    $admin = (int) $adminInsert->fetchColumn();
    $orderInsert = $db->prepare("INSERT INTO orders(order_number,language_code,order_source,order_status,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount) VALUES(:number,'en','MANUAL','CONFIRMED','PICKUP','2026-12-10',:name,'98110002',100,0,100,40)");
    $orderInsert->execute(['number' => 'AUDIT-RELIABILITY', 'name' => $name]);
    $orderInsert->execute(['number' => 'OTHER-RELIABILITY', 'name' => 'Other record']);
    $requestInsert = $db->prepare("INSERT INTO custom_product_requests(request_number,customer_name_snapshot,customer_phone_snapshot,category_id,requested_quantity,preferred_contact_method,request_status) VALUES(:number,:name,'98110002',1,1,'PHONE','SUBMITTED') RETURNING id");
    $requestInsert->execute(['number' => 'AUDIT-RELIABILITY', 'name' => $name]);
    $requestId = (int) $requestInsert->fetchColumn();
    $requestInsert->execute(['number' => 'OTHER-RELIABILITY', 'name' => 'Other record']);

    mkdir($sessionDirectory, 0700);
    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $sessionDirectory, $serverLog, [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'LOGIN_THROTTLE_KEY' => bin2hex(random_bytes(32)),
        'HOMAS_VERIFICATION_DATABASE' => null,
    ], ['max_input_vars' => '1000', 'post_max_size' => '8M']);
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    $http = static function (string $path, ?array $post = null) use ($client, $address): array {
        $headers = [];
        curl_setopt_array($client, [
            CURLOPT_URL => 'http://' . $address . '/' . $path,
            CURLOPT_POSTFIELDS => $post === null ? null : http_build_query($post),
            CURLOPT_POST => $post !== null,
            CURLOPT_HTTPGET => $post === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
                if (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower($name)] = trim($value);
                }
                return strlen($header);
            }
        ]);
        $body = curl_exec($client);
        if (!is_string($body)) {
            throw new RuntimeException('An isolated HTTP request failed.');
        }
        return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), $body, $headers];
    };
    $token = static function (string $html): string {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException('A form is missing its CSRF token.');
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    $field = static function (string $html, string $id): string {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $element = $document->getElementById($id);
        if (!$element) {
            throw new RuntimeException('Expected form field missing: ' . $id);
        }
        if ($element->tagName === 'textarea') {
            return $element->textContent;
        }
        if ($element->tagName === 'select') {
            foreach ($element->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    return $option->getAttribute('value');
                }
            }
            return $element->getElementsByTagName('option')->item(0)?->getAttribute('value') ?? '';
        }
        return $element->getAttribute('value');
    };

    [, $login] = $http('admin/login.php');
    [$status,, $headers] = $http('admin/login.php', ['csrf_token' => $token($login), 'login_identifier' => 'reliability@example.test', 'password' => $password]);
    $check($status === 303 && ($headers['location'] ?? '') === '/admin/index.php', 'HTTP checks authenticate through the normal administrator sign-in.');




    $input = static function (string $html, string $name): string {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $node = (new DOMXPath($document))->query('//input[@name="' . $name . '"]')->item(0);
        if (!$node) {
            throw new RuntimeException('Missing input ' . $name);
        }
        return $node->getAttribute('value');
    };
    $db->exec("SELECT setval('products_id_seq',(SELECT MAX(id)::bigint FROM products),true)");
    $firstOrder = (int)$db->query("SELECT id FROM orders WHERE order_number='AUDIT-RELIABILITY'")->fetchColumn();
    $secondOrder = (int)$db->query("SELECT id FROM orders WHERE order_number='OTHER-RELIABILITY'")->fetchColumn();
    $db->exec("INSERT INTO customers_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Leila','Haddad','redesign-customer@example.test','98110012','synthetic')");
    $customer = (int)$db->lastInsertId();
    $db->exec("INSERT INTO customer_addresses(customer_id,address_label,street_address,delegation,governorate,postal_code,is_primary) VALUES($customer,'Home','12 Test Street','Tunis','Tunis','1000',1)");
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED' WHERE request_number='OTHER-RELIABILITY'");
    $agreed = (int)$db->query("SELECT id FROM custom_product_requests WHERE request_number='OTHER-RELIABILITY'")->fetchColumn();

    $routes = [
        'admin/index.php',
        'admin/orders/index.php',
        'admin/orders/create.php',
        'admin/orders/detail.php?id=' . $firstOrder,
        'admin/requests/index.php',
        'admin/requests/create.php',
        'admin/requests/detail.php?id=' . $requestId,
        'admin/requests/convert.php?id=' . $agreed,
        'admin/catalogue/products.php',
        'admin/catalogue/products.php?id=new',
        'admin/catalogue/products.php?id=1',
        'admin/catalogue/categories.php',
        'admin/catalogue/product-types.php',
        'admin/catalogue/colors.php',
        'admin/catalogue/scents.php',
        'admin/catalogue/promotions.php',
        'admin/inventory/materials.php',
        'admin/inventory/materials.php?id=1',
        'admin/inventory/movements.php',
        'admin/inventory/recipe.php?product_id=1',
        'admin/production/queue.php',
        'admin/production/calendar.php',
        'admin/production/sheet.php',
        'admin/payments/index.php',
        'admin/payments/order.php?order_id=' . $firstOrder,
        'admin/customers/index.php?q=Leila',
        'admin/customers/detail.php?id=' . $customer,
        'admin/accounts.php?kind=customers&id=' . $customer,
        'admin/accounts.php',
        'admin/reports/index.php',
        'admin/settings/index.php'
    ];
    foreach ($routes as $route) {
        [$status, $html, $headers] = $http($route);
        $check($status === 200 && str_contains($html, '<h1') && !str_contains($html, 'Something went wrong while loading') && !str_contains($html, 'Warning:') && str_contains($headers['cache-control'] ?? '', 'no-store'), 'Authenticated page renders fully and privately: ' . $route);
    }
    [, $today] = $http('admin/index.php');
    $check(str_contains($today, 'Your next priorities') && str_contains($today, 'Payments to review') && str_contains($today, 'Production, at a glance'), 'Today connects order, payment, request and production attention.');
    preg_match_all('~href="([^"]*assets/css/[^\"]+)"~', $today, $styles);
    $check(count($styles[1]) >= 25 && count(array_filter($styles[1], static fn (string $url): bool => preg_match('/\?v=[a-f0-9]{12}$/D', $url) === 1)) === count($styles[1]), 'Every stylesheet dependency is independently versioned.');

    // Incomplete work can be saved safely, without creating a business record.
    $ordersBefore = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    [, $form] = $http('admin/orders/create.php');
    $draftPost = ['csrf_token' => $token($form), 'action' => 'save_draft', 'customer_mode' => 'guest', 'guest_customer_name' => 'Leïla <script>draft</script>', 'guest_customer_phone' => '98', 'items' => [['product_id' => '', 'quantity' => '2']], 'administrator_notes' => 'Private draft note'];
    [$status,, $headers] = $http('admin/orders/create.php', $draftPost);
    [, $resumed] = $http(ltrim($headers['location'], '/'));
    $check($status === 303 && str_contains($headers['location'], 'resume=1') && str_contains($resumed, 'Leïla &lt;script&gt;draft&lt;/script&gt;') && !str_contains($resumed, 'Leïla <script>draft</script>') && preg_match('/^[a-f0-9]{32}$/D', $input($resumed, 'work_draft_id')) === 1 && (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $ordersBefore, 'A partial order saves and resumes privately with escaped values and no order creation.');
    $http('admin/orders/create.php', $draftPost);
    $check((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $ordersBefore, 'Replaying a consumed draft submission cannot create an order.');
    [, $form] = $http('admin/requests/create.php');
    $partial = [['working_name' => 'Ivory شمعة', 'category_id' => '', 'product_type_id' => '', 'requested_quantity' => '2', 'components' => [['source' => 'CUSTOM', 'custom_name' => 'Petal', 'quantity' => '1', 'specifications' => 'Draft only']]]];
    $requestsBefore = (int)$db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn();
    [$status,, $headers] = $http('admin/requests/create.php', ['csrf_token' => $token($form), 'action' => 'save_draft', 'customer_mode' => 'guest', 'guest_customer_name' => 'Draft enquiry', 'preferred_contact_method' => 'PHONE', 'custom_products_json' => json_encode($partial), 'request_payload_complete' => '1']);
    [, $resumedRequest] = $http(ltrim($headers['location'], '/'));
    $check($status === 303 && $field($resumedRequest, 'custom-0-working_name') === 'Ivory شمعة' && $field($resumedRequest, 'component-0-0-name') === 'Petal' && (int)$db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn() === $requestsBefore, 'A partial composed request resumes all Unicode product/component work without creating a request.');

    // Named product drafts are persistent catalogue records, never auto-published.
    $productsBefore = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    [, $form] = $http('admin/catalogue/products.php?id=new');
    $namedPost = ['csrf_token' => $token($form), 'action' => 'create_draft', 'name' => 'Crème شمعة', 'description' => '', 'category_id' => '1', 'product_type_id' => '1'];
    [$status,, $headers] = $http('admin/catalogue/products.php', $namedPost);
    parse_str((string)parse_url($headers['location'], PHP_URL_QUERY), $savedQuery);
    $product = (int)($savedQuery['id'] ?? 0);
    $row = $db->query('SELECT p.*,t.name,t.description FROM products p JOIN product_translations t ON t.product_id=p.id AND t.language_code=\'en\' WHERE p.id=' . $product)->fetch();
    $check($status === 303 && str_ends_with($headers['location'], '#product-media') && $row['name'] === 'Crème شمعة' && $row['description'] === '' && (int)$row['is_active'] === 0 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $row['slug']) === 1, 'Named drafts retain a Unicode name with an optional empty description and generated URL, then open Photos.');
    $http('admin/catalogue/products.php', $namedPost);
    $check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $productsBefore + 1, 'Replaying product draft creation does not duplicate the product.');
    [, $edit] = $http('admin/catalogue/products.php?id=' . $product);
    $http('admin/catalogue/products.php', ['csrf_token' => $token($edit), 'id' => (string)$product, 'action' => 'save_identity', 'name' => 'Renamed draft', 'description' => '']);
    $check($db->query('SELECT name FROM product_translations WHERE language_code=\'en\' AND product_id=' . $product)->fetchColumn() === 'Renamed draft', 'A draft can be renamed in Basics before its description is complete.');
    $db->exec("CREATE FUNCTION reject_draft_translation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.name='Rollback draft' THEN RAISE EXCEPTION 'Fixture rejected the translation'; END IF; RETURN NEW; END $$; CREATE TRIGGER fail_draft_translation BEFORE INSERT ON product_translations FOR EACH ROW EXECUTE FUNCTION reject_draft_translation()");
    [, $form] = $http('admin/catalogue/products.php?id=new');
    $http('admin/catalogue/products.php', array_replace($namedPost, ['csrf_token' => $token($form), 'name' => 'Rollback draft']));
    $check((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $productsBefore + 1, 'A failed name insert rolls back the new product and all related changes.');
    $db->exec('DROP TRIGGER fail_draft_translation ON product_translations; DROP FUNCTION reject_draft_translation()');

    // The order context is retained through actual payment writes and review.
    [, $orderPage] = $http('admin/orders/detail.php?id=' . $firstOrder);
    $check(str_contains($orderPage, 'name="order_context" value="1"') && str_contains($orderPage, 'Record a payment received'), 'The order workspace renders the shared receipt form.');
    $receipt = ['csrf_token' => $token($orderPage), 'order_id' => (string)$firstOrder, 'order_context' => '1', 'payment_method' => 'CASH_ON_DELIVERY', 'payment_type' => 'DEPOSIT', 'amount' => '10.000', 'received_confirmation' => '1'];
    [$status,, $headers] = $http('admin/payments/order.php', $receipt);
    $check($status === 303 && $headers['location'] === '/admin/orders/detail.php?id=' . $firstOrder . '#order-payments' && (int)$db->query('SELECT COUNT(*) FROM payments WHERE order_id=' . $firstOrder)->fetchColumn() === 1, 'Receipt recording returns to the original order Payments section.');
    $http('admin/payments/order.php', $receipt);
    $check((int)$db->query('SELECT COUNT(*) FROM payments WHERE order_id=' . $firstOrder)->fetchColumn() === 1, 'The inline receipt retains duplicate-submission protection.');
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount,d17_reference) VALUES($firstOrder,'D17','BALANCE','PENDING_VERIFICATION',5,'REDESIGN-TRANSFER')");
    $payment = (int)$db->lastInsertId();
    [, $review] = $http('admin/payments/review.php?id=' . $payment . '&action=verify&order_context=1');
    [$status,, $headers] = $http('admin/payments/review.php', ['csrf_token' => $token($review), 'id' => (string)$payment, 'action' => 'verify', 'order_context' => '1']);
    $check($status === 303 && $headers['location'] === '/admin/orders/detail.php?id=' . $firstOrder . '#order-payments' && $db->query('SELECT payment_status FROM payments WHERE id=' . $payment)->fetchColumn() === 'VERIFIED', 'D17 review preserves the order context and records the verified receipt.');

    // Batched readiness agrees with the saved order recipes and stock history.
    $q = $db->prepare("INSERT INTO order_items(order_id,product_id,recipe_source,product_name_snapshot,quantity,unit_price_snapshot,line_total_snapshot) VALUES(:order,1,'CUSTOM','Saved recipe fixture',2,50,100) RETURNING id");
    $q->execute(['order' => $firstOrder]);
    $item = (int)$q->fetchColumn();
    $db->exec("INSERT INTO order_item_materials(order_item_id,material_id,material_name_snapshot,unit_snapshot,unit_cost_snapshot,quantity_required) VALUES($item,1,'Saved wax','g',0.100,60)");
    $readyRepo = new App\Repositories\ProductionReadinessRepository($db);
    $checksByOrder = $readyRepo->forOrders([$firstOrder, $secondOrder]);
    $check(!$checksByOrder[$firstOrder]['ready'] && $checksByOrder[$firstOrder]['shortages'][0]['name'] === $name && !$checksByOrder[$secondOrder]['ready'], 'Readiness distinguishes a stock shortage from an order without production items.');
    $db->exec('UPDATE order_item_materials SET quantity_required=10 WHERE order_item_id=' . $item);
    $check($readyRepo->forOrders([$firstOrder])[$firstOrder]['ready'], 'A complete saved recipe with sufficient stock has no current blocker.');
    $db->exec("INSERT INTO order_status_history(order_id,previous_status,new_status,change_source,changed_by_administrator_id) VALUES($firstOrder,'PREPARING','PREPARED','ADMINISTRATOR',$admin); UPDATE materials SET stock_quantity=0 WHERE id=1");
    $check($readyRepo->forOrders([$firstOrder])[$firstOrder]['ready'], 'A corrected order already prepared is not asked to supply its deducted materials again.');
    $queue = new App\Services\ProductionService(new App\Repositories\ProductionRepository($db));
    $missing = $queue->queue(['readiness' => 'missing_recipe'], new DateTimeImmutable('today'));
    $check($missing['total'] === 1 && (int)$missing['orders'][0]['id'] === $secondOrder && ($missing['queryState']['readiness'] ?? '') === 'missing_recipe', 'Needs a recipe filters counts and rows before pagination and preserves the view.');

    // Customer edits return to the customer; stale forms cannot overwrite changes.
    $profileUrl = 'admin/accounts.php?' . http_build_query(['kind' => 'customers', 'id' => $customer, 'return' => 'q=Leila&page=2']);
    [, $profile] = $http($profileUrl);
    $version = $input($profile, 'profile_version');
    $db->exec("UPDATE customers_accounts SET first_name='Changed elsewhere' WHERE id=$customer");
    $profilePost = ['csrf_token' => $token($profile), 'kind' => 'customers', 'id' => (string)$customer, 'return' => 'q=Leila&page=2', 'profile_version' => $version, 'first_name' => 'Leïla', 'last_name' => 'Haddad', 'email' => 'redesign-customer@example.test', 'phone_number' => '98110012', 'second_phone_number' => ''];
    [$status,, $headers] = $http('admin/accounts.php', $profilePost);
    [, $stale] = $http(ltrim($headers['location'], '/'));
    $check($db->query('SELECT first_name FROM customers_accounts WHERE id=' . $customer)->fetchColumn() === 'Changed elsewhere' && str_contains($stale, 'profile changed elsewhere') && $field($stale, 'profile-first_name') === 'Leïla', 'Stale profile writes are rejected while preserving the entered correction.');
    [, $profile] = $http($profileUrl);
    $profilePost['csrf_token'] = $token($profile);
    $profilePost['profile_version'] = $input($profile, 'profile_version');
    [$status,, $headers] = $http('admin/accounts.php', $profilePost);
    $check($status === 303 && str_starts_with($headers['location'], '/admin/customers/detail.php?id=' . $customer . '&return=') && str_contains($headers['location'], 'Leila') && $db->query('SELECT first_name FROM customers_accounts WHERE id=' . $customer)->fetchColumn() === 'Leïla', 'Saving current customer details returns to that customer with the directory search preserved.');
    [, $form] = $http('admin/inventory/materials.php?id=1&return_order=' . $firstOrder);
    [$status,, $headers] = $http('admin/inventory/materials.php', ['csrf_token' => $token($form), 'id' => '1', 'return_order' => (string)$firstOrder, 'action' => 'record_movement', 'movement_type' => 'STOCK_IN', 'quantity_change' => '5.000', 'movement_note' => 'Restocking from production blocker.']);
    $check($status === 303 && str_contains($headers['location'], 'return_order=' . $firstOrder) && $db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '5.000', 'Stock receipt preserves the return-to-production context and uses the existing ledger.');

    $db->exec('UPDATE custom_product_requests SET archived_at=CURRENT_TIMESTAMP WHERE id=' . $requestId);
    $dashboard = (new App\Services\AdminDashboardService(new App\Repositories\AdminDashboardRepository($db)))->overview(new DateTimeImmutable('today'));
    $check($dashboard['metrics']['openRequests'] === 1 && !in_array($requestId, array_map('intval', array_column($dashboard['recentRequests'], 'id')), true), 'Archived requests stay out of Today counts and actionable conversations.');
    [, $form] = $http('admin/orders/create.php');
    $http('admin/logout.php', ['csrf_token' => $token($form)]);
    [, $login] = $http('admin/login.php');
    $http('admin/login.php', ['csrf_token' => $token($login), 'login_identifier' => 'reliability@example.test', 'password' => $password]);
    [, $form] = $http('admin/orders/create.php?resume=1');
    [, $requestForm] = $http('admin/requests/create.php?resume=1');
    $check(!str_contains($form, 'Your saved order draft') && !str_contains($requestForm, 'Your saved request draft'), 'Signing out removes private saved work from the administrator session.');
    $log = (string)file_get_contents($serverLog);
    $check(!preg_match('/PHP (?:Warning|Fatal error|Parse error)/', $log), 'New HTTP journeys completed without PHP warnings or fatal errors.');
    echo 'Completed ' . $checks . ' admin redesign assertions.' . PHP_EOL;
} finally {
    if (is_resource($client) || $client instanceof CurlHandle) {
        curl_close($client);
    }
    $server?->stop();
    foreach (glob($sessionDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDirectory)) {
        rmdir($sessionDirectory);
    }
    if (is_file($serverLog)) {
        unlink($serverLog);
    }
    drop_throwaway_auth_schema($db, $schema);
}
