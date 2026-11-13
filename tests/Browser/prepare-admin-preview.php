<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
$previewState = sys_get_temp_dir() . '/homas-admin-preview-state.json';
if (($argv[1] ?? '') === 'cleanup') {
    if (!is_file($previewState)) {
        echo 'No preview state exists.' . PHP_EOL;
        exit;
    }
    $state = json_decode(file_get_contents($previewState), true, 512, JSON_THROW_ON_ERROR);
    drop_throwaway_auth_schema(database(), $state['schema']);
    unlink($previewState);
    echo 'Removed the disposable preview schema.' . PHP_EOL;
    exit;
}
if (is_file($previewState)) {
    throw new RuntimeException('A preview fixture already exists. Clean it up before preparing another.');
}
if (!getenv('LOGIN_THROTTLE_KEY')) {
    putenv('LOGIN_THROTTLE_KEY=' . bin2hex(random_bytes(32)));
}


// Keep every fixture in a generated schema, separate from the shop's records.
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


    $db->exec("UPDATE category_translations SET name=CASE category_id WHEN 1 THEN 'Candles' ELSE 'Accessories' END; UPDATE product_type_translations SET name=CASE product_type_id WHEN 1 THEN 'Flowers' ELSE 'Trays' END;
      UPDATE product_translations SET name=CASE product_id WHEN 1 THEN 'Peony candle' ELSE 'Ceramic tray' END;
      UPDATE products SET is_active=1,actual_cost=8,selling_price=24,main_image_path=CASE id WHEN 1 THEN 'assets/brand/candle.jpg' ELSE 'assets/brand/candleSet.jpg' END;
      UPDATE materials SET name=CASE id WHEN 1 THEN 'Soy wax' ELSE 'Cotton wicks' END;
      INSERT INTO product_materials(product_id,material_id,recipe_type,quantity_required) VALUES(1,1,'ACTUAL',10);
      UPDATE orders SET customer_name_snapshot=CASE order_number WHEN 'AUDIT-RELIABILITY' THEN 'Amira Ben Ali' ELSE 'Nour Trabelsi' END,required_date=CURRENT_DATE+1;
      UPDATE orders SET required_date=CURRENT_DATE WHERE order_number='AUDIT-RELIABILITY';
      UPDATE custom_product_requests SET customer_name_snapshot='Yasmine Mansour',customer_message='A bespoke floral arrangement for a wedding. Cream tones, soft rose scent.';");
    $orders = $db->query('SELECT id FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($orders as $index => $id) {
        $q = $db->prepare("INSERT INTO order_items(order_id,product_id,recipe_source,product_name_snapshot,quantity,unit_price_snapshot,line_total_snapshot) VALUES(:order,1,'CATALOGUE','Peony candle',:quantity,24,:total) RETURNING id");
        $qty = $index === 0 ? 3 : 15;
        $q->execute(['order' => $id, 'quantity' => $qty, 'total' => $qty * 24]);
        $item = (int)$q->fetchColumn();
        $db->exec("INSERT INTO order_item_materials(order_item_id,material_id,material_name_snapshot,unit_snapshot,unit_cost_snapshot,quantity_required) VALUES($item,1,'Soy wax','g',0.1,10)");
        $db->exec("UPDATE orders SET subtotal=" . ($qty * 24) . ",total_amount=" . ($qty * 24) . ",required_deposit_amount=" . ($qty * 24 >= 100 ? $qty * 24 * 0.4 : 0) . " WHERE id=$id");
    }
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount,d17_reference) VALUES(" . $orders[0] . ",'D17','BALANCE','PENDING_VERIFICATION',20,'PREVIEW-D17')");
    $service = new App\Services\CustomRequestService(new App\Repositories\CustomRequestRepository($db), new App\Database\TransactionManager($db));
    $request = $service->create($admin, ['customer_name' => 'Yasmine Mansour', 'customer_phone' => '98110010', 'preferred_contact_method' => 'WHATSAPP', 'custom_products' => [['working_name' => 'Wedding peony favors', 'category_id' => '1', 'product_type_id' => '1', 'requested_quantity' => '30', 'specifications' => 'Ivory petals with a rose scent.', 'components' => []]]]);
    if (!$request['success']) {
        throw new RuntimeException('Preview request fixture failed');
    }
    $db->exec("UPDATE custom_product_requests SET request_status='DISCUSSING' WHERE id=" . $request['requestId']);
    $db->exec("SELECT setval('products_id_seq',(SELECT MAX(id)::bigint FROM products),true)");
    require_once APP_PATH . '/Bootstrap/admin/admin-catalogue.php';
    $db->exec("INSERT INTO product_translations(product_id,language_code,name,description) VALUES(1,'fr','Bougie pivoine','Une bougie fleurie pour votre table.'),(1,'ar','Peony candle','A floral candle for your table.') ON CONFLICT(product_id,language_code) DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description;
INSERT INTO product_colors(product_id,color_id,image_path) VALUES(1,1,'assets/brand/candle.jpg') ON CONFLICT(product_id,color_id) DO UPDATE SET image_path=EXCLUDED.image_path;
INSERT INTO product_scents(product_id,scent_id) VALUES(1,1) ON CONFLICT DO NOTHING;
UPDATE color_translations SET name='Ivory' WHERE color_id=1; UPDATE scent_translations SET name='Rose' WHERE scent_id=1;
UPDATE scents SET fragrance_material_id=2 WHERE id=1;
UPDATE materials SET name='Rose fragrance' WHERE id=2;
INSERT INTO product_materials(product_id,material_id,recipe_type,quantity_required) VALUES(1,2,'ACTUAL',1) ON CONFLICT(product_id,material_id,recipe_type) DO UPDATE SET quantity_required=EXCLUDED.quantity_required;");
    $pricingService->recalculateAll();
    $result = $adminProductService->toggleActive(1, true);
    if (!$result['success']) {
        throw new RuntimeException(json_encode($result));
    }

    $customers = new App\Repositories\CustomerAccountRepository($db, new App\Database\TransactionManager($db));
    $customerId = $customers->createWithPrimaryAddress(['first_name' => 'Leila', 'last_name' => 'Haddad', 'email' => 'preview-customer@example.test', 'phone_number' => '98110011', 'password_hash' => password_hash('SyntheticCustomerOnly1!', PASSWORD_ARGON2ID)], ['street_address' => '12 Test street', 'delegation' => 'Tunis', 'governorate' => 'Tunis', 'postal_code' => '1000'], $admin);
    $linkRequest = $db->prepare('UPDATE custom_product_requests SET customer_id = :customer WHERE id = :request');
    $linkRequest->execute(['customer' => $customerId, 'request' => $request['requestId']]);
    file_put_contents($previewState, json_encode(['schema' => $schema, 'administrator' => $admin, 'email' => 'reliability@example.test', 'password' => $password, 'orders' => array_map('intval', $orders), 'request' => $request['requestId'], 'customer' => $customerId], JSON_THROW_ON_ERROR));
    chmod($previewState, 0600);
    echo 'Prepared isolated preview schema with synthetic records.' . PHP_EOL;
} catch (Throwable $error) {
    drop_throwaway_auth_schema($db, $schema);
    throw $error;
}
