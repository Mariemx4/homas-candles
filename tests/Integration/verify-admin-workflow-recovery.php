<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';


// Build the current PostgreSQL schema independently of the missing reference seed.
[$db, $schema] = create_throwaway_auth_schema();
$server = null;
$client = null;
$sessionDirectory = sys_get_temp_dir() . '/' . $schema . '_workflow_sessions';
$serverLog = sys_get_temp_dir() . '/' . $schema . '_workflow.log';
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


    $hidden = static function (string $html, string $name): string {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $nodes = (new DOMXPath($document))->query('//input[@name="' . $name . '"]');
        if (!$nodes->length) {
            throw new RuntimeException('Missing form input ' . $name);
        }
        return $nodes->item(0)->getAttribute('value');
    };
    [, $tabA] = $http('admin/inventory/materials.php?id=1');
    [, $tabB] = $http('admin/requests/detail.php?id=' . $requestId);
    $check($token($tabA) !== $token($tabB), 'Different admin pages receive independent form tokens.');
    $http('admin/requests/action.php', ['csrf_token' => $token($tabB), 'id' => (string)$requestId, 'action' => 'contact', 'contact_method' => 'PHONE', 'contact_outcome' => 'CONTACTED', 'notes' => 'Tab B saved.', 'follow_up' => '']);
    $movement = ['csrf_token' => $token($tabA), 'id' => '1', 'action' => 'record_movement', 'movement_type' => 'ADJUSTMENT_IN', 'quantity_change' => '2.500', 'movement_note' => 'Tab A survives.'];
    $http('admin/inventory/materials.php', $movement);
    $check($db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '102.500', 'Saving another tab does not expire an already-open stock form.');
    $http('admin/inventory/materials.php', $movement);
    $check((int)$db->query('SELECT COUNT(*) FROM material_stock_movements')->fetchColumn() === 1, 'Replaying a consumed stock form cannot deduct or add stock twice.');
    [, $tabA] = $http('admin/inventory/materials.php?id=1');
    $http('admin/requests/action.php', ['csrf_token' => 'invalid', 'id' => (string)$requestId, 'action' => 'assign', 'administrator_id' => '']);
    $http('admin/inventory/materials.php', array_replace($movement, ['csrf_token' => $token($tabA)]));
    $check($db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '105.000', 'An invalid token in another tab does not expire a valid form.');

    $tx = new App\Database\TransactionManager($db);
    $repo = new App\Repositories\CustomRequestRepository($db);
    $service = new App\Services\CustomRequestService($repo, $tx);
    $definition = [
        'working_name' => 'Crème شمعة',
        'category_id' => '1',
        'product_type_id' => '1',
        'requested_quantity' => '2',
        'color_choice' => '__custom__',
        'color_custom' => 'Ivory cream',
        'scent_choice' => '',
        'specifications' => 'Original brief.',
        'components' => [['source' => 'CUSTOM', 'custom_name' => 'Wax flower', 'quantity' => '1', 'color_choice' => '', 'scent_choice' => '', 'specifications' => 'Original component.']]
    ];
    $base = ['customer_name' => 'Workflow Customer', 'customer_phone' => '98110009', 'customer_email' => '', 'preferred_contact_method' => 'PHONE', 'custom_products' => [$definition]];
    $created = $service->create($admin, $base);
    $check($created['success'], 'A small working brief can be created with the shared definition validator.');
    $brief = (int)$created['requestId'];
    [, $edit] = $http('admin/requests/edit.php?id=' . $brief);
    $version = $hidden($edit, 'version');
    $check($field($edit, 'custom-0-working_name') === $definition['working_name'] && $field($edit, 'component-0-0-name') === 'Wax flower', 'The correction form reloads existing Unicode product and component definitions.');
    $corrected = $definition;
    $corrected['working_name'] = 'Corrected <script>name</script>';
    $corrected['requested_quantity'] = '3';
    $corrected['components'][0]['specifications'] = 'New component notes.';
    $editPost = ['id' => (string)$brief, 'csrf_token' => $token($edit), 'version' => $version, 'revision_reason' => 'Customer clarified the design.', 'custom_products_json' => json_encode([$corrected]), 'request_payload_complete' => '1'];
    $http('admin/requests/edit.php', $editPost);
    $detail = $service->detail($brief, new DateTimeImmutable());
    $revision = $detail['briefRevisions'][0] ?? null;
    $check($detail['request']['custom_products'][0]['working_name'] === $corrected['working_name'] && $detail['request']['custom_products'][0]['requested_quantity'] == 3 && count($detail['briefRevisions']) === 1, 'A correction saves definitions and exactly one durable revision.');
    $before = json_decode($revision['before_brief'], true);
    $after = json_decode($revision['after_brief'], true);
    $check($before['custom_products'][0]['working_name'] === $definition['working_name'] && $after['custom_products'][0]['components'][0]['specifications'] === 'New component notes.' && (int)$revision['administrator_id'] === $admin, 'Revision history retains original and corrected specifications plus the acting administrator.');
    $references = new App\Repositories\AdminLifecycleRepository($db);
    $check(($references->dependencies('requests', $brief)['custom_request_brief_revisions'] ?? 0) === 1
        && ($references->dependencies('administrators', $admin)['custom_request_brief_revisions'] ?? 0) === 1, 'Existing lifecycle dependency checks protect both the revised request and its actor history.');
    [, $body] = $http('admin/requests/detail.php?id=' . $brief);
    $check(str_contains($body, 'Brief revisions') && str_contains($body, 'Customer clarified the design.') && !str_contains($body, '<script>name</script>') && strpos($body, '<h1>') < strpos($body, '<h2>Requested products'), 'The detail page presents heading first and safely renders correction history.');
    [, $edit] = $http('admin/requests/edit.php?id=' . $brief);
    $http('admin/requests/edit.php', array_replace($editPost, ['csrf_token' => $token($edit), 'revision_reason' => 'Stale edit attempt.']));
    [, $stale] = $http('admin/requests/edit.php?id=' . $brief);
    $check(str_contains($stale, 'changed in another tab') && $field($stale, 'custom-0-working_name') === $corrected['working_name'] && $hidden($stale, 'version') === $version && count($repo->briefRevisions($brief)) === 1, 'A stale brief cannot overwrite a correction; entered values and the old version remain available for review.');
    [, $edit] = $http('admin/requests/edit.php?id=' . $brief);
    $currentVersion = $hidden($edit, 'version');
    $http('admin/requests/edit.php', array_replace($editPost, ['csrf_token' => 'expired', 'version' => $currentVersion]));
    [, $body] = $http('admin/requests/edit.php?id=' . $brief);
    $check(str_contains($body, 'form expired') && $field($body, 'revision-reason') === 'Customer clarified the design.' && count($repo->briefRevisions($brief)) === 1, 'Expired correction forms retain work without writing history.');
    [, $edit] = $http('admin/requests/edit.php?id=' . $brief);
    $db->exec('ALTER TABLE custom_request_brief_revisions RENAME TO workflow_missing_revisions');
    try {
        $http('admin/requests/edit.php', array_replace($editPost, ['csrf_token' => $token($edit), 'version' => $hidden($edit, 'version'), 'custom_products_json' => json_encode([$definition])]));
    } finally {
        $db->exec('ALTER TABLE workflow_missing_revisions RENAME TO custom_request_brief_revisions');
    }
    $check($repo->findRequestProducts($brief)[0]['working_name'] === $corrected['working_name'] && count($repo->briefRevisions($brief)) === 1, 'A failed revision write rolls back product/component replacements atomically.');
    foreach (['AGREED', 'CANCELLED', 'DECLINED'] as $state) {
        $q = $db->prepare('UPDATE custom_product_requests SET request_status=:state WHERE id=:id');
        $q->execute(['state' => $state, 'id' => $brief]);
        $check(!$service->editBrief($brief, $admin, ['version' => $currentVersion, 'custom_products' => [$definition], 'revision_reason' => 'Blocked correction.'])['success'], $state . ' requests reject brief corrections.');
    }
    $db->exec('UPDATE custom_product_requests SET request_status=\'SUBMITTED\',archived_at=CURRENT_TIMESTAMP WHERE id=' . $brief);
    $check(!$service->editBrief($brief, $admin, ['version' => $currentVersion, 'custom_products' => [$definition], 'revision_reason' => 'Blocked correction.'])['success'], 'Archived requests reject brief corrections.');
    $db->exec('UPDATE custom_product_requests SET archived_at=NULL,request_status=\'AGREED\' WHERE id=' . $brief);

    // Exercise the exact advertised maximum through HTTP with PHP's 1000-var limit.
    $bulk = [];
    for ($i = 0; $i < 50; ++$i) {
        $product = $definition;
        $product['working_name'] = 'Bulk ' . $i;
        $product['components'] = array_fill(0, 50, $definition['components'][0]);
        $bulk[] = $product;
    }
    [, $form] = $http('admin/requests/create.php');
    $bulkPost = ['csrf_token' => $token($form), 'customer_mode' => 'guest', 'guest_customer_name' => 'Maximum brief', 'guest_customer_phone' => '98110008', 'guest_customer_email' => '', 'preferred_contact_method' => 'PHONE', 'custom_products_json' => json_encode($bulk), 'request_payload_complete' => '1'];
    [$status,, $headers] = $http('admin/requests/create.php', $bulkPost);
    $bulkId = (int)$db->query("SELECT id FROM custom_product_requests WHERE customer_name_snapshot='Maximum brief'")->fetchColumn();
    $check($status === 303 && $bulkId > 0 && (int)$db->query('SELECT COUNT(*) FROM custom_request_products WHERE request_id=' . $bulkId)->fetchColumn() === 50 && (int)$db->query('SELECT COUNT(*) FROM custom_request_components c JOIN custom_request_products p ON p.id=c.request_product_id WHERE p.request_id=' . $bulkId)->fetchColumn() === 2500, 'All 50 products × 50 components survive the actual HTTP parser, beyond max_input_vars=1000.');
    $count = (int)$db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn();
    foreach (['malformed' => '{', 'oversize' => str_repeat('x', 2097153), 'too_many' => json_encode(array_fill(0, 51, $definition))] as $label => $payload) {
        [, $form] = $http('admin/requests/create.php');
        $http('admin/requests/create.php', array_replace($bulkPost, ['csrf_token' => $token($form), 'custom_products_json' => $payload]));
        $check((int)$db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn() === $count, 'The ' . $label . ' payload is rejected without a partial request.');
    }
    [, $form] = $http('admin/requests/create.php');
    $incomplete = array_replace($bulkPost, ['csrf_token' => $token($form)]);
    unset($incomplete['request_payload_complete']);
    $http('admin/requests/create.php', $incomplete);
    $check((int)$db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn() === $count, 'Missing completion marker rejects an incomplete native form before saving.');

    $quoteRoute = 'admin/requests/convert.php?id=' . $brief;
    [, $form] = $http($quoteRoute);
    $quotedDefinition = $repo->findRequestProducts($brief)[0];
    $quotePost = [
        'id' => (string)$brief,
        'csrf_token' => $token($form),
        'custom_products' => [['definition_id' => (string)$quotedDefinition['id'], 'product_name' => 'Final agreed product', 'quantity' => '3', 'agreed_batch_price' => '100.002', 'unit_price' => '', 'customization_notes' => 'Final brief']],
        'delivery_method' => 'DELIVERY',
        'required_date' => '2026-12-10',
        'delivery_fee' => '9.000',
        'street_address' => '12 Test Street',
        'delegation' => 'Test',
        'governorate' => 'Tunis',
        'postal_code' => '1000',
        'administrator_notes' => 'Review test',
        'request_payload_complete' => '1'
    ];
    [$status, $json] = $http('admin/requests/convert.php', $quotePost + ['conversion_action' => 'preview']);
    $quote = json_decode($json, true);
    $check($status === 200 && $quote['success'] && $quote['totals'] === ['subtotal' => '100.002', 'delivery_fee' => '9.000', 'total' => '109.002', 'deposit' => '43.601'], 'The server previews exact batch prices, delivery and rounded 40% deposit.');
    $http('admin/requests/convert.php', $quotePost + ['conversion_action' => 'review']);
    [, $review] = $http($quoteRoute);
    $check(str_contains($review, '109.002') && str_contains($review, '43.601') && $hidden($review, 'quote_total') === '109.002', 'Review totals works without JavaScript and returns a server-calculated confirmation total.');
    $initialOrders = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $http('admin/requests/convert.php', array_replace($quotePost, ['csrf_token' => $token($review), 'quote_total' => '109.001']));
    $check((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $initialOrders, 'A mismatched or missing reviewed total cannot create an order.');
    [, $form] = $http($quoteRoute);
    $invalid = array_replace($quotePost, ['csrf_token' => $token($form), 'street_address' => '', 'conversion_action' => 'preview']);
    [, $json] = $http('admin/requests/convert.php', $invalid);
    $check(!json_decode($json, true)['success'], 'Delivery quotation requires a valid address on the server.');
    $pickup = array_replace($quotePost, ['csrf_token' => $token($form), 'delivery_method' => 'PICKUP', 'delivery_fee' => '0.000', 'conversion_action' => 'preview']);
    $pickup['custom_products'][0]['agreed_batch_price'] = '99.999';
    [, $json] = $http('admin/requests/convert.php', $pickup);
    $quote = json_decode($json, true);
    $check($quote['success'] && $quote['totals']['deposit'] === '0.000' && $quote['totals']['total'] === '99.999', 'Pickup has no delivery charge and totals below 100.000 require no deposit.');
    $create = array_replace($quotePost, ['csrf_token' => $token($form), 'quote_total' => '109.002']);
    [$status,, $headers] = $http('admin/requests/convert.php', $create);
    $converted = (int)$db->query('SELECT converted_order_id FROM custom_product_requests WHERE id=' . $brief)->fetchColumn();
    $order = $db->query('SELECT * FROM orders WHERE id=' . $converted)->fetch();
    $check($status === 303 && $converted > 0 && $order['total_amount'] === '109.002' && $order['required_deposit_amount'] === '43.601', 'Preview does not consume the form; confirmed creation persists the same total and deposit.');
    $http('admin/requests/convert.php', $create);
    $check((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $initialOrders + 1 && $repo->findRequestProducts($brief)[0]['converted_order_item_id'] !== null, 'Replaying conversion creates no duplicate order or item.');
    $check(!$service->editBrief($brief, $admin, ['version' => $currentVersion, 'custom_products' => [$definition], 'revision_reason' => 'Blocked correction.'])['success'], 'Converted product definitions remain locked.');
    for ($i = 0; $i < 4; ++$i) {
        $repo->recordBriefRevision($brief, $admin, 'History page fixture ' . $i, $before, $after);
    }
    $history = $service->detail($brief, new DateTimeImmutable());
    [, $olderHistory] = $http('admin/requests/detail.php?id=' . $brief . '&revision_page=2');
    $check(count($history['briefRevisions']) === 3 && $history['revisionPages'] === 2
        && str_contains($olderHistory, 'Customer clarified the design.') && str_contains($olderHistory, 'Newer revisions'), 'History is bounded per page and the original revision remains accessible on older pages.');

    $orderStatusService = new App\Services\OrderStatusService(
        new App\Repositories\OrderStatusRepository($db),
        new App\Repositories\MaterialStockRepository($db, $tx),
        $tx,
        new App\Services\OrderProductionService(new App\Repositories\OrderProductionRepository($db), $tx),
        new App\Services\AccessoryStockService(new App\Repositories\AccessoryStockRepository($db), $tx)
    );
    $cancelId = (int)$db->query("SELECT id FROM orders WHERE order_number='OTHER-RELIABILITY'")->fetchColumn();
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount,verified_at,verified_by_administrator_id) VALUES($cancelId,'CASH_ON_DELIVERY','DEPOSIT','VERIFIED',40,CURRENT_TIMESTAMP,$admin)");
    [, $form] = $http('admin/orders/detail.php?id=' . $cancelId);
    $statusPost = ['id' => (string)$cancelId, 'csrf_token' => $token($form), 'inline_status' => '1', 'expected_status' => 'CONFIRMED', 'new_status' => 'CANCELLED', 'return_to' => 'detail'];
    $http('admin/orders/status.php', $statusPost);
    $check($db->query('SELECT order_status FROM orders WHERE id=' . $cancelId)->fetchColumn() === 'CANCELLED' && $db->query('SELECT amount FROM payments WHERE order_id=' . $cancelId)->fetchColumn() === '40.000', 'Admin cancellation works before production and retains recorded payments.');
    $check((int)$db->query('SELECT COUNT(*) FROM order_status_history WHERE order_id=' . $cancelId . ' AND new_status=\'CANCELLED\' AND changed_by_administrator_id=' . $admin)->fetchColumn() === 1 && $db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '105.000', 'Cancellation writes one actor-attributed history record without changing stock.');
    $check(!$orderStatusService->selectStatus($cancelId, $admin, 'REQUESTED', 'CANCELLED')['success'], 'Cancelled orders cannot silently reopen through status correction.');
    $productionId = (int)$db->query("SELECT id FROM orders WHERE order_number='AUDIT-RELIABILITY'")->fetchColumn();
    $db->exec("INSERT INTO order_status_history(order_id,previous_status,new_status,changed_by_administrator_id,change_source) VALUES($productionId,'CONFIRMED','PREPARING',$admin,'ADMINISTRATOR')");
    $check(!$orderStatusService->selectStatus($productionId, $admin, 'CANCELLED', 'CONFIRMED')['success'], 'An order corrected back to Confirmed still cannot cancel after production has started.');
    $options = $orderStatusService->selectionOptions($db->query('SELECT * FROM orders WHERE id=' . $productionId)->fetch());
    $cancelOption = current(array_filter($options, static fn ($option) => $option['status'] === 'CANCELLED'));
    $check($cancelOption['disabled'] && $cancelOption['reason'] === 'before production only', 'Unsupported cancellation is labelled by its real rule rather than pickup-only.');

    [, $form] = $http('admin/inventory/materials.php?id=1');
    $oldToken = $token($form);
    $http('admin/logout.php', ['csrf_token' => $oldToken]);
    [, $login] = $http('admin/login.php');
    $http('admin/login.php', ['csrf_token' => $token($login), 'login_identifier' => 'reliability@example.test', 'password' => $password]);
    $http('admin/inventory/materials.php', array_replace($movement, ['csrf_token' => $oldToken]));
    $check($db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '105.000', 'Signing out and back in invalidates all forms from the previous administrator session.');
    $log = (string)file_get_contents($serverLog);
    $check(!preg_match('/(?:PHP Warning|PHP Fatal error|PHP Parse error)/', $log), 'HTTP journeys completed without PHP warnings or fatal errors.');
    echo 'Completed ' . $checks . ' workflow recovery assertions.' . PHP_EOL;
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
