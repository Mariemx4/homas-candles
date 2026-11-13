<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Repositories\{AdminLifecycleRepository, AdminProductRepository, TaxonomyRepository, MaterialRepository, MaterialMovementRepository, MaterialStockRepository, OrderRepository, OrderProductionRepository, CustomerAccountRepository, PricingRepository, AccessoryStockRepository, ScentStockRepository};
use App\Services\{AdminLifecycleService, AdminProductService, TaxonomyService, MaterialService, PricingService, ProductReadinessService, ProductImageStorage, AccessoryStockService, ManualOrderService, OrderProductionService, ScentAvailabilityService, AccountProfileService};
use App\Database\TransactionManager;

[$db, $schema] = create_throwaway_auth_schema(true);
$checks = 0;
$check = TestCheck::printing($checks);
$insert = static function (string $sql) use ($db): int {
    $db->exec($sql);
    return (int)$db->lastInsertId();
};
$blockedSql = static function (string $sql, string $message) use ($db, $check): void {
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        $check($e->getCode() === '23503', $message);
        return;
    }
    $check(false, $message);
};
try {
    $admin = $insert("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Lifecycle','Owner','lifecycle@example.test','98000101','fixture')");
    $other = $insert("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Other','Admin','other-lifecycle@example.test','98000102','fixture')");
    $tx = new TransactionManager($db);
    $records = new AdminLifecycleRepository($db);
    $productRepo = new AdminProductRepository($db);
    $taxonomyRepo = new TaxonomyRepository($db);
    $pricing = new PricingService(new PricingRepository($db), $tx);
    $ready = new ProductReadinessService($productRepo, $pricing, $tx);
    $stock = new AccessoryStockService(new AccessoryStockRepository($db), $tx);
    $products = new AdminProductService($productRepo, $taxonomyRepo, $tx, new ProductImageStorage(sys_get_temp_dir()), $pricing, $ready, $stock);
    $materialRepo = new MaterialRepository($db);
    $materials = new MaterialService($materialRepo, new MaterialMovementRepository($db), new MaterialStockRepository($db, $tx), $tx, $ready);
    $taxonomy = new TaxonomyService($taxonomyRepo, $ready);
    $service = new AdminLifecycleService($records, $tx, $products, $materials, $taxonomy);
    $profiles = new AccountProfileService($records, $tx);
    $check(!$service->mutate('administrators', $admin, $admin, 'delete')['success'], 'Current administrator deletion is blocked.');
    $check(!$service->mutate('administrators', $admin, $admin, 'deactivate')['success'], 'Current administrator deactivation is blocked.');
    $check($service->mutate('administrators', $other, $admin, 'deactivate')['success'], 'Another administrator may be suspended.');
    $check(!$service->mutate('administrators', $admin, $other, 'delete')['success'], 'Inactive actor cannot remove the remaining active administrator.');
    $check($service->mutate('administrators', $other, $admin, 'delete')['success'], 'Unused administrator can be deleted.');
    $check(!$service->mutate('products', 1, 999999, 'delete')['success'], 'Unknown actors cannot mutate lifecycle data.');
    $unused = $insert("INSERT INTO customers_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Unused','Customer','unused-lifecycle@example.test','98000201','fixture')");
    $check($service->mutate('customers', $unused, $admin, 'delete')['success'], 'Unused customer account can be deleted.');
    $accounts = new CustomerAccountRepository($db, $tx);
    $customer = $accounts->createWithPrimaryAddress(['first_name' => 'History', 'last_name' => 'Customer', 'email' => 'history-lifecycle@example.test', 'phone_number' => '98000202', 'password_hash' => 'fixture'], ['street_address' => 'Original road', 'delegation' => 'Tunis', 'governorate' => 'Tunis', 'postal_code' => '1000']);
    $check(!$service->mutate('customers', $customer, $admin, 'delete')['success'], 'Owned customer addresses block account hard deletion.');
    foreach (['categories', 'product_types', 'colors', 'scents'] as $kind) {
        $new = $insert("INSERT INTO $kind(slug,is_active) VALUES('lifecycle-unused-$kind',1)");
        $check($service->mutate($kind, $new, $admin, 'delete')['success'], 'Unused ' . $kind . ' can be deleted.');
    }
    $unusedMaterial = $insert("INSERT INTO materials(material_type_id,sku,name,unit,unit_cost,tracks_stock,is_active) VALUES(1,'LC-UNUSED','Unused material','g',1,0,1)");
    $check($service->mutate('materials', $unusedMaterial, $admin, 'delete')['success'], 'Unused material can be deleted.');
    $unusedProduct = $insert("INSERT INTO products(category_id,slug,is_active) VALUES(1,'lifecycle-unused-product',0)");
    $check($service->mutate('products', $unusedProduct, $admin, 'delete')['success'], 'Unused draft product can be deleted.');
    $material = $insert("INSERT INTO materials(material_type_id,sku,name,unit,stock_quantity,unit_cost,tracks_stock,is_active) VALUES(1,'LC-WAX','History wax','g',10000,0.100,1,1)");
    $color = $insert("INSERT INTO colors(slug,is_active) VALUES('lifecycle-ivory',1)");
    $scent = $insert("INSERT INTO scents(slug,is_active) VALUES('lifecycle-vanilla',1)");
    $type = $insert("INSERT INTO product_types(slug,is_active) VALUES('lifecycle-flower',1)");
    $product = $insert("INSERT INTO products(category_id,product_type_id,slug,selling_price,actual_cost,minimum_quantity,is_active) VALUES(1,$type,'lifecycle-flower-candle',8,4,1,1)");
    $db->exec("INSERT INTO product_materials(product_id,material_id,recipe_type,quantity_required) VALUES($product,$material,'ACTUAL',40);
        INSERT INTO product_colors(product_id,color_id) VALUES($product,$color); INSERT INTO product_scents(product_id,scent_id) VALUES($product,$scent)");
    $catalogue = new OrderProductionRepository($db);
    $orders = new OrderRepository($db);
    $manual = new ManualOrderService($orders, $tx, $catalogue, new OrderProductionService($catalogue, $tx), $stock, new ScentAvailabilityService(new ScentStockRepository($db), $tx), customers: $accounts);
    $pricing->savePromotion(['code' => 'LIFECYCLE10', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10', 'is_active' => '1']);
    $made = $manual->create($admin, ['customer_mode' => 'existing', 'customer_id' => (string)$customer, 'delivery_method' => 'PICKUP', 'delivery_fee' => '0.000', 'required_date' => '2026-12-10', 'catalogue_only' => true, 'promo_code' => 'LIFECYCLE10', 'items' => [['product_id' => (string)$product, 'color_id' => (string)$color, 'scent_id' => (string)$scent, 'quantity' => '1']]]);
    $check($made['success'], 'Historical fixture uses the existing manual-order and pricing services.');
    $order = $made['orderId'];
    $before = $orders->findSummary($order);
    $beforeItem = $orders->findItems($order)[0];
    foreach (['customers' => $customer, 'products' => $product, 'materials' => $material, 'categories' => 1, 'product_types' => $type, 'colors' => $color, 'scents' => $scent] as $kind => $id) {
        $check(!$service->detail($kind, $id, $admin)['can_delete'], 'Confirmation blocks referenced ' . $kind . '.');
        $check(!$service->mutate($kind, $id, $admin, 'delete')['success'], 'Forged hard delete of referenced ' . $kind . ' is blocked.');
    }
    $blockedSql('DELETE FROM customers_accounts WHERE id=' . $customer, 'Database preserves customer ownership.');
    $blockedSql('DELETE FROM products WHERE id=' . $product, 'Database preserves historical product identity.');
    $blockedSql('DELETE FROM colors WHERE id=' . $color, 'Database preserves color references.');
    $blockedSql('DELETE FROM scents WHERE id=' . $scent, 'Database preserves scent references.');
    $blockedSql('DELETE FROM administrators_accounts WHERE id=' . $admin, 'Database preserves administrator history.');
    $promo = (int)$db->query("SELECT id FROM promotion_codes WHERE code='LIFECYCLE10'")->fetchColumn();
    $check(!$service->mutate('promotions', $promo, $admin, 'delete')['success'], 'Used promotion cannot be destroyed.');
    $blockedSql('DELETE FROM promotion_codes WHERE id=' . $promo, 'Database also protects snapshot-only promotion usage.');
    $check($service->mutate('promotions', $promo, $admin, 'deactivate')['success'], 'Used promotion can be deactivated.');
    $check(!(new PricingRepository($db))->findPromotion('LIFECYCLE10')['is_active'], 'Inactive promotion is excluded by pricing validity.');
    $pricing->savePromotion(['code' => 'LC_UNUSED', 'discount_type' => 'FIXED_TND', 'discount_value' => '1', 'is_active' => '1']);
    $unusedPromo = (int)$db->query("SELECT id FROM promotion_codes WHERE code='LC_UNUSED'")->fetchColumn();
    $check($service->mutate('promotions', $unusedPromo, $admin, 'delete')['success'], 'Unused promotion can be deleted.');
    $profile = ['first_name' => 'Changed', 'last_name' => 'Profile', 'email' => 'changed-lifecycle@example.test', 'phone_number' => '98000203', 'second_phone_number' => ''];
    $check($profiles->save('customers', $customer, $profile, $admin)['success'], 'Administrator can edit a current customer profile.');
    $check(!$profiles->save('customers', $customer, $profile, $admin, true)['success'], 'Customer self-service cannot edit another account.');
    $check($profiles->save('customers', $customer, $profile, $customer, true)['success'], 'Customer self-service edits only its own account.');
    $check($service->mutate('customers', $customer, $admin, 'deactivate')['success'], 'Referenced customer can be suspended.');
    $check($orders->findCustomer($customer) === null && !$orders->searchCustomers('changed-lifecycle'), 'Inactive customer disappears from new order selection.');
    $check(!$profiles->save('customers', $customer, $profile, $customer, true)['success'], 'Suspended customers cannot edit through a stale session.');
    $check($orders->findSummary($order)['customer_name_snapshot'] === $before['customer_name_snapshot'], 'Customer edits preserve order contact snapshots.');
    $check($service->mutate('products', $product, $admin, 'deactivate')['success'] && $catalogue->findOrderableProducts([$product]) === [], 'Inactive product disappears from new order discovery.');
    $check($orders->findItems($order)[0]['product_name_snapshot'] === $beforeItem['product_name_snapshot'], 'Historical product snapshot remains readable.');
    foreach (['colors' => $color, 'scents' => $scent] as $kind => $id) {
        $check($service->mutate($kind, $id, $admin, 'deactivate')['success'], 'Referenced ' . $kind . ' deactivate safely.');
        $check(!in_array($id, array_map('intval', array_column($taxonomyRepo->findActiveOptions($kind), 'id')), true), 'Inactive ' . $kind . ' disappear from new options.');
    }
    $check($orders->findItems($order)[0]['color_name_snapshot'] === $beforeItem['color_name_snapshot'] && $orders->findItems($order)[0]['scent_name_snapshot'] === $beforeItem['scent_name_snapshot'], 'Historical option names remain frozen.');
    $check($materialRepo->isReferenced($material), 'Material identity checks include all dependencies.');
    $check(!$materials->save($material, ['name' => 'Renamed wax', 'unit_cost' => '0.100', 'minimum_stock_level' => '0', 'is_active' => '1', 'unit' => 'kg'])['success'], 'Referenced material unit changes are rejected.');
    $check($materials->save($material, ['name' => 'Renamed wax', 'unit_cost' => '0.100', 'minimum_stock_level' => '0', 'is_active' => '1'])['success'], 'Referenced material safe metadata remains editable.');
    $check($service->mutate('materials', $material, $admin, 'deactivate')['success'], 'Referenced material deactivates using readiness service.');
    $check((int)$db->query('SELECT COUNT(*) FROM order_item_materials WHERE material_id=' . $material)->fetchColumn() > 0, 'Historical material recipe remains intact.');
    $parent = $insert("INSERT INTO products(category_id,slug,is_active) VALUES(1,'lifecycle-parent',0)");
    $component = $insert("INSERT INTO products(category_id,slug,is_active) VALUES(1,'lifecycle-component',0)");
    $db->exec("INSERT INTO set_components(set_product_id,component_product_id,quantity) VALUES($parent,$component,1)");
    $check(!$service->mutate('products', $component, $admin, 'delete')['success'], 'Component composition prevents hard deletion.');
    $check($service->mutate('products', $component, $admin, 'deactivate')['success'], 'Referenced physical component retains its existing product lifecycle.');
    $check((int)$db->query('SELECT COUNT(*) FROM set_components WHERE component_product_id=' . $component)->fetchColumn() === 1, 'Inactive component composition stays readable.');
    // The final operation rechecks references added after an earlier confirmation.
    $race = $insert("INSERT INTO colors(slug,is_active) VALUES('lifecycle-race-color',1)");
    $check($service->detail('colors', $race, $admin)['can_delete'], 'Unused confirmation permits deletion before a new reference.');
    $db->exec("INSERT INTO product_colors(product_id,color_id) VALUES($parent,$race)");
    $check(!$service->mutate('colors', $race, $admin, 'delete')['success'], 'A reference added after confirmation blocks the final deletion.');
    $request = $insert("INSERT INTO custom_product_requests(request_number,customer_name_snapshot,customer_phone_snapshot,category_id,requested_quantity,preferred_contact_method,request_status,converted_order_id,converted_at) VALUES('LC-REQUEST','History','98000202',1,1,'PHONE','CONVERTED',$order,CURRENT_TIMESTAMP)");
    $check($service->mutate('requests', $request, $admin, 'archive')['success'], 'Converted custom request archives without losing history.');
    $check((int)$records->find('requests', $request)['converted_order_id'] === $order && $records->find('requests', $request)['archived_by_administrator_id'] == $admin, 'Archive keeps conversion and actor identity.');
    $check(!$service->mutate('requests', $request, $admin, 'delete')['success'], 'Custom requests have no casual hard delete.');
    $check($service->mutate('requests', $request, $admin, 'restore')['success'] && $records->find('requests', $request)['archived_at'] === null, 'Request restore retains the original relationship.');
    $controller = new App\Controllers\AdminLifecycleController($service, new App\Security\CsrfTokenManager());
    $_SESSION = [];
    $check(!$controller->process(['kind' => 'products', 'id' => (string)$parent, 'action' => 'delete', 'csrf_token' => 'forged'], $admin)['success'], 'Forged POST security tokens are rejected.');
    $check(App\Controllers\AdminLifecycleController::identity(['kind' => 'payments', 'id' => '1']) === null, 'Financial rows have no generic lifecycle deletion endpoint.');
    $check(App\Controllers\AdminLifecycleController::identity(['kind' => 'materials', 'id' => ['1']]) === null, 'Array IDs are rejected.');
    $check($orders->findSummary($order)['promo_code_snapshot'] === $before['promo_code_snapshot'] && $orders->findSummary($order)['total_amount'] === $before['total_amount'], 'Historical discount and total survive all lifecycle changes.');
    $reviewerResult = $profiles->save('administrators', null, ['first_name' => 'Review', 'last_name' => 'Identity', 'email' => 'review-lifecycle@example.test', 'phone_number' => '98000301', 'password' => 'LifecycleTest123'], $admin);
    $check($reviewerResult['success'], 'Administrator creation reuses the application password policy.');
    $reviewer = $reviewerResult['id'];
    $db->exec("INSERT INTO payments(order_id,payment_method,payment_type,payment_status,amount,verified_at,verified_by_administrator_id) VALUES($order,'CASH_ON_DELIVERY','FULL_PAYMENT','VERIFIED',7.200,CURRENT_TIMESTAMP,$reviewer)");
    $check(!$service->mutate('administrators', $reviewer, $admin, 'delete')['success'], 'Referenced payment reviewer cannot be deleted.');
    $check($service->mutate('administrators', $reviewer, $admin, 'deactivate')['success'], 'Referenced reviewer can be deactivated.');
    $check((int)$db->query('SELECT verified_by_administrator_id FROM payments WHERE order_id=' . $order)->fetchColumn() === $reviewer, 'Payment review retains suspended administrator identity.');
    $blockedSql('DELETE FROM orders WHERE id=' . $order, 'Database prevents order history cascading deletion.');
    $db->exec("INSERT INTO material_stock_movements(material_id,order_id,movement_type,quantity_change,unit_cost_snapshot,stock_before,stock_after,performed_by_administrator_id) VALUES($material,$order,'ORDER_DEDUCTION',-40,0.100,10000,9960,$admin)");
    $db->exec("UPDATE products SET stock_quantity=9 WHERE id=$component; INSERT INTO product_stock_movements(product_id,order_id,movement_type,quantity_change,stock_before,stock_after,created_by_administrator_id) VALUES($component,$order,'ORDER_DEDUCTION',-1,10,9,$admin); UPDATE orders SET order_status='PREPARED' WHERE id=$order");
    $csrf = new App\Security\CsrfTokenManager();
    $trash = new App\Controllers\OrderTrashController($orders, $tx, $csrf, $stock);
    $stockBefore = $db->query('SELECT stock_quantity FROM products WHERE id=' . $component)->fetchColumn();
    $movementsBefore = (int)$db->query('SELECT COUNT(*) FROM product_stock_movements WHERE order_id=' . $order)->fetchColumn();
    $trashed = $trash->process(['id' => (string)$order, 'version' => App\Services\OrderEditService::version($orders->lockIncludingTrash($order)), 'csrf_token' => $csrf->token()], $admin);
    $check($trashed['success'] && $orders->lockIncludingTrash($order)['deleted_at'] !== null, 'Existing Order Trash workflow still works.');
    $check($db->query('SELECT stock_quantity FROM products WHERE id=' . $component)->fetchColumn() === $stockBefore && (int)$db->query('SELECT COUNT(*) FROM product_stock_movements WHERE order_id=' . $order)->fetchColumn() === $movementsBefore, 'Trash does not return consumed components or rewrite stock history.');
    $check((int)$db->query('SELECT COUNT(*) FROM payments WHERE order_id=' . $order)->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM material_stock_movements WHERE order_id=' . $order)->fetchColumn() === 1, 'Trash preserves payment and material movement history.');
    $check($trash->process(['id' => (string)$order, 'csrf_token' => $csrf->token()], $admin, true)['success'], 'Existing Order restore continues to work.');
    $check((int)$db->query('SELECT COUNT(*) FROM product_stock_movements WHERE order_id=' . $order)->fetchColumn() === $movementsBefore, 'Restore does not double-deduct stock when Trash did not return it.');
    $check(!$taxonomy->save('product_types', $type, ['slug' => 'renamed-used-type', 'name_en' => 'Renamed type', 'is_active' => '1'])['success'], 'Referenced taxonomy machine identities cannot change.');
    $check(!$taxonomy->save('scents', $scent, ['slug' => 'lifecycle-vanilla', 'name_en' => 'Vanilla', 'fragrance_material_id' => (string)$material, 'is_active' => '0'])['success'], 'Referenced scent material links cannot change.');
    $check($taxonomy->save('product_types', $type, ['slug' => 'lifecycle-flower', 'name_en' => 'Updated display name', 'is_active' => '1'])['success'], 'Referenced taxonomy display names remain editable.');
    $check($controller::identity(['kind' => 'material_stock_movements', 'id' => '1']) === null, 'Stock movements have no destructive CRUD endpoint.');
    // Exercise real competing connections: FK/key-share locks prevent a reference slipping into deletion.
    $config = require APP_PATH . '/config/database.local.php';
    $second = new PDO('pgsql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 5432) . ';dbname=' . ($config['database'] ?? $config['db_name']), $config['username'] ?? $config['user_name'], $config['password'] ?? $config['psswd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $second->exec('SET search_path TO ' . $schema);
    $second->exec("SET lock_timeout TO '150ms'");
    $concurrent = $insert("INSERT INTO colors(slug,is_active) VALUES('lifecycle-concurrent-color',1)");
    $db->beginTransaction();
    $records->find('colors', $concurrent, true);
    try {
        $second->exec("INSERT INTO product_colors(product_id,color_id) VALUES($parent,$concurrent)");
        $check(false, 'Concurrent reference insert must wait.');
    } catch (PDOException $e) {
        $check($e->getCode() === '55P03', 'Concurrent reference insertion waits for the deletion row lock.');
    }
    $check($service->mutate('colors', $concurrent, $admin, 'delete')['success'], 'Locked unused record deletes atomically.');
    $db->commit();
    try {
        $second->exec("INSERT INTO product_colors(product_id,color_id) VALUES($parent,$concurrent)");
        $check(false, 'Deleted parent must reject a late reference.');
    } catch (PDOException $e) {
        $check($e->getCode() === '23503', 'Late reference cannot attach to a deleted parent.');
    }
    $pricing->savePromotion(['code' => 'LC_RACE', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10', 'is_active' => '1']);
    $racePromo = (int)$db->query("SELECT id FROM promotion_codes WHERE code='LC_RACE'")->fetchColumn();
    $clone = "INSERT INTO orders SELECT (jsonb_populate_record(NULL::orders,to_jsonb(o)||jsonb_build_object('id',nextval('orders_id_seq'),'order_number','LC-RACE-ORDER','promo_code_snapshot','LC_RACE'))).* FROM orders o WHERE id=$order";
    $db->beginTransaction();
    $records->find('promotions', $racePromo, true);
    try {
        $second->exec($clone);
        $check(false, 'Concurrent promotion usage must wait.');
    } catch (PDOException $e) {
        $check($e->getCode() === '55P03', 'Snapshot-only promotion usage waits for deletion lock.');
    }
    $check($service->mutate('promotions', $racePromo, $admin, 'delete')['success'], 'Unused promotion deletes while holding its identity lock.');
    $db->commit();
    try {
        $second->exec($clone);
        $check(false, 'Deleted promotion must reject late usage.');
    } catch (PDOException $e) {
        $check($e->getCode() === '23503', 'Late order cannot snapshot a promotion deleted concurrently.');
    }
    $reviewProduct = $insert("INSERT INTO products(category_id,slug,is_active) VALUES(1,'lifecycle-reviewed-product',0)");
    $db->exec("INSERT INTO product_translation_reviews(product_id,language_code,source_language_code,source_hash,status,reviewed_by) VALUES($reviewProduct,'fr','en','fixture','APPROVED',$reviewer)");
    $check(!$service->mutate('products', $reviewProduct, $admin, 'delete')['success'], 'Product translation review audit prevents normal product deletion.');
    $blockedSql('DELETE FROM products WHERE id=' . $reviewProduct, 'Database preserves translation review audit children.');
    $check($service->mutate('customers', $customer, $admin, 'activate')['success'] && $profiles->deactivateOwnAccount($customer)['success'], 'Signed-in customer can deactivate their own account without deleting history.');
    $check(!$profiles->deactivateOwnAccount($customer)['success'] && $orders->findSummary($order)['customer_name_snapshot'] === $before['customer_name_snapshot'], 'Suspended customer cannot mutate status through a stale session; snapshots survive.');
    echo "Verified $checks lifecycle assertions.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    drop_throwaway_auth_schema($db, $schema);
}
