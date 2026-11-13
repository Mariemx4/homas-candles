<?php

declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\TransactionManager;
use App\Repositories\{OrderRepository, OrderProductionRepository, OrderStatusRepository, ProductionRepository, PricingRepository, CustomerAccountRepository, CustomRequestRepository, AccessoryStockRepository, ScentStockRepository};
use App\Services\{ManualOrderService, OrderProductionService, OrderPricingService, PricingService, CustomerModeService, CustomRequestService, AccessoryStockService, ScentAvailabilityService};

[$db, $schema] = create_throwaway_auth_schema(true);
$checks = 0;
$check = TestCheck::printing($checks);
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (DomainException|PDOException $e) {
        $check(true, $message);
        return;
    }
    $check(false, $message);
};
try {
    $db->exec("INSERT INTO administrators_accounts (first_name,last_name,email,phone_number,password_hash) VALUES ('Pricing','Admin','pricing@example.test','99000101','fixture')");
    $admin = (int) $db->lastInsertId();
    $db->exec("INSERT INTO materials (material_type_id,sku,name,unit,stock_quantity,unit_cost,tracks_stock,is_active) VALUES (1,'PRICE-WAX','Wax','g',10000,0.150,1,1)");
    $material = (int) $db->lastInsertId();
    $db->exec("INSERT INTO products (category_id,slug,selling_price,actual_cost,minimum_quantity,is_active) VALUES (1,'price-candle',12,6,1,1)");
    $product = (int) $db->lastInsertId();
    $db->exec("INSERT INTO product_materials (product_id,material_id,recipe_type,quantity_required) VALUES ($product,$material,'ACTUAL',40)");
    $tx = new TransactionManager($db);
    $orders = new OrderRepository($db);
    $catalogue = new OrderProductionRepository($db);
    $production = new OrderProductionService($catalogue, $tx);
    $pricingRepo = new PricingRepository($db);
    $pricing = new PricingService($pricingRepo, $tx);
    $accounts = new CustomerAccountRepository($db, $tx);
    $manual = new ManualOrderService($orders, $tx, $catalogue, $production, new AccessoryStockService(new AccessoryStockRepository($db), $tx), new ScentAvailabilityService(new ScentStockRepository($db), $tx), customers:$accounts);
    $p = static fn (): array => $catalogue->findOrderableProducts([$product])[$product];
    $check(OrderPricingService::unitPrice($p(), 1)['quantity_millis'] === 12000, 'Catalogue base price is authoritative without a tier.');
    foreach ([['10','24','11.000'],['25','49','10.000'],['50','','9.000']] as [$min,$max,$price]) {
        $check($pricing->addQuantityPrice($product, ['minimum_quantity' => $min,'maximum_quantity' => $max,'unit_price' => $price])['success'], 'Configure a quantity price through the existing pricing service.');
    }
    foreach ([1 => 12000,9 => 12000,10 => 11000,24 => 11000,25 => 10000,49 => 10000,50 => 9000,100 => 9000] as $qty => $price) {
        $check(OrderPricingService::unitPrice($p(), $qty)['quantity_millis'] === $price, 'Inclusive tier boundary: quantity ' . $qty);
    }
    foreach ([['20','30','10'],['0','9','10'],['30','25','10'],['100','','13']] as [$min,$max,$price]) {
        $check(!$pricing->addQuantityPrice($product, ['minimum_quantity' => $min,'maximum_quantity' => $max,'unit_price' => $price])['success'], 'Invalid or overlapping quantity configuration is rejected.');
    }
    $reject(fn () => $db->exec("INSERT INTO product_quantity_prices (product_id,minimum_quantity,maximum_quantity,unit_price) VALUES ($product,22,23,10)"), 'Database also rejects ambiguous tiers.');
    $savePromo = static fn (string $code, string $type, string $value, array $extra = []): array => $pricing->savePromotion($extra + ['code' => $code,'discount_type' => $type,'discount_value' => $value,'is_active' => '1','minimum_subtotal' => '0','valid_from' => '','valid_until' => '']);
    $check($savePromo('MARIEM10', 'PERCENTAGE', '10')['success'] && $savePromo('FIXED5', 'FIXED_TND', '5')['success'], 'Percentage and fixed promotions have explicit configuration.');
    $check($savePromo('NAME99', 'PERCENTAGE', '2')['success'], 'Code text does not determine the configured discount.');
    $sale = new OrderPricingService($pricingRepo);
    $quote = $sale->quote(220000, 9000, ['promo_code' => 'mariem10','manual_discount_type' => 'FIXED_TND','manual_discount_value' => '8']);
    $check($quote['promo_millis'] === 22000 && $quote['manual_millis'] === 8000 && $quote['total_millis'] === 199000 && $quote['deposit_millis'] === 79600, 'Promo, manual discount, delivery and existing 40% deposit stack in the required order.');
    $check($sale->quote(220000, 0, ['promo_code' => 'FIXED5'])['total_millis'] === 215000, 'Fixed promotion and pickup zero fee.');
    $check($sale->quote(220000, 0, ['promo_code' => 'NAME99'])['promo_millis'] === 4400, 'Configured 2% is used even when a code contains 99.');
    $check($sale->quote(220000, 9000, ['promo_code' => 'MARIEM10','manual_discount_type' => 'PERCENTAGE','manual_discount_value' => '5'])['manual_millis'] === 9900, 'Manual percentage is calculated after the promo.');
    $check(OrderPricingService::calculate(1001, 0, ['manual_discount_type' => 'PERCENTAGE','manual_discount_value' => '50'])['manual_millis'] === 501, 'Millime rounding is exact and half-up.');
    $savePromo('EXPIRED', 'FIXED_TND', '5', ['valid_until' => '2020-01-01']);
    $savePromo('FUTURE', 'FIXED_TND', '5', ['valid_from' => '2099-01-01']);
    $savePromo('INACTIVE', 'FIXED_TND', '5', ['is_active' => '0']);
    $savePromo('MINIMUM', 'FIXED_TND', '5', ['minimum_subtotal' => '300']);
    foreach (['INVALID','EXPIRED','FUTURE','INACTIVE','MINIMUM'] as $code) {
        $reject(fn () => $sale->quote(220000, 0, ['promo_code' => $code]), 'Unavailable promotion is rejected: ' . $code);
    }
    foreach ([['FIXED_TND','221'],['FIXED_TND','-1'],['PERCENTAGE','101'],['PERCENTAGE','abc']] as [$type,$value]) {
        $reject(fn () => $sale->quote(220000, 0, ['manual_discount_type' => $type,'manual_discount_value' => $value]), 'Invalid or excessive manual discount is rejected.');
    }
    $base = ['customer_mode' => 'guest','guest_customer_name' => 'Pricing Guest','guest_customer_phone' => '22000101','guest_customer_email' => '','delivery_method' => 'PICKUP','required_date' => '2026-12-10','delivery_fee' => '0.000','items' => [['product_id' => (string)$product,'quantity' => '20','unit_price' => '0.001','recipe' => [['material_id' => (string)$material,'quantity' => '999']]]],'catalogue_only' => true,'promo_code' => 'MARIEM10','manual_discount_type' => 'FIXED_TND','manual_discount_value' => '8','manual_discount_reason' => 'Repeat customer','total_amount' => '0.001','promo_discount_amount' => '999'];
    $made = $manual->create($admin, $base);
    $check($made['success'], 'A normal catalogue order loads server prices and recipes.');
    $id = $made['orderId'];
    $row = $orders->findSummary($id);
    $item = $orders->findItems($id)[0];
    $check($row['subtotal'] === '220.000' && $row['total_amount'] === '190.000' && $row['delivery_fee'] === '0.000' && $row['quantity_savings_snapshot'] === '20.000', 'Forged prices, totals and discount amounts are ignored; pickup has zero fee.');
    $check($item['catalogue_unit_price_snapshot'] === '12.000' && $item['quantity_unit_price_snapshot'] === '11.000' && $item['unit_price_snapshot'] === '11.000', 'Base, tier and effective prices are saved independently.');
    $saved = $db->query('SELECT * FROM order_item_materials WHERE order_item_id=' . $item['id'])->fetch();
    $check($saved['quantity_required'] === '40.000' && $item['recipe_source'] === 'CATALOGUE', 'Recipe is saved per unit; browser recipe quantities are ignored.');
    $requirements = (new OrderStatusRepository($db))->findMaterialRequirements($id);
    $check($requirements[0]['required_quantity'] === '800.000' && (new ProductionRepository($db))->findMaterialRequirements([$id])[0]['required_quantity'] === '800.000', 'Production planning and inventory deduction both multiply the saved recipe by ordered quantity.');
    $workLines = (new ProductionRepository($db))->findProductionLines([$id]);
    $check((int)$workLines[0]['custom_recipe'] === 2 && $workLines[0]['product_name'] === $item['product_name_snapshot'], 'Production groups identify a saved catalogue recipe and use the sold product name.');
    $db->exec("UPDATE products SET selling_price=15 WHERE id=$product; UPDATE product_materials SET quantity_required=50 WHERE product_id=$product; UPDATE product_quantity_prices SET unit_price=10.500 WHERE product_id=$product AND minimum_quantity=10");
    $savePromo('MARIEM10', 'PERCENTAGE', '20');
    $check($orders->findSummary($id)['total_amount'] === '190.000' && $orders->findItems($id)[0]['unit_price_snapshot'] === '11.000' && (new OrderStatusRepository($db))->findMaterialRequirements($id)[0]['required_quantity'] === '800.000', 'Later base, tier, promo and recipe changes cannot mutate the saved order.');
    $payments = new App\Services\PaymentService(new App\Repositories\PaymentRepository($db), $tx, new App\Services\PaymentEvidenceStorage(sys_get_temp_dir()));
    $paymentSummary = $payments->orderSummary($id)['order'];
    $check(
        $paymentSummary['total_amount'] === '190.000' && $paymentSummary['remaining_balance'] === '190.000' && $paymentSummary['next_d17_amount'] === '76.000',
        'Payment and D17 consume the discounted saved total and its 40% deposit.'
    );
    $db->exec("INSERT INTO payments (order_id,payment_method,payment_type,payment_status,amount,currency,verified_at) VALUES ($id,'CASH_ON_DELIVERY','DEPOSIT','VERIFIED',76,'TND',NOW())");
    $check($payments->orderSummary($id)['order']['next_d17_amount'] === '114.000', 'D17 remaining amount uses the final order total after its verified deposit.');
    $edit = new App\Services\OrderEditService($orders, new OrderStatusRepository($db), $tx);
    $beforeEdit = $orders->findSummary($id);
    $editValues = array_replace(App\Services\OrderEditService::values($beforeEdit), ['delivery_method' => 'DELIVERY','delivery_fee' => '9.000','street_address' => '1 Candle Street','delegation' => 'Tunis','governorate' => 'Tunis','postal_code' => '1000']);
    $edited = $edit->update($id, $editValues, App\Services\OrderEditService::version($beforeEdit), $admin);
    $check($edited['success'] && $orders->findSummary($id)['total_amount'] === '199.000' && $orders->findSummary($id)['promo_discount_amount'] === '22.000'
        && $orders->findItems($id)[0] === $item, 'Permitted fulfilment correction retains saved discounts and all item price/recipe audits.');
    $reject(fn () => $db->exec("UPDATE order_item_materials SET quantity_required=50 WHERE order_item_id=" . $item['id']), 'Captured catalogue recipe cannot be edited directly.');
    $reject(fn () => $db->exec("UPDATE orders SET promo_discount_amount=0,total_amount=subtotal-manual_discount_amount+delivery_fee WHERE id=$id"), 'Agreed discount audit is immutable.');
    $override = $base;
    $override['promo_code'] = '';
    $override['manual_discount_type'] = 'NONE';
    $override['items'][0] += ['price_override' => '1','price_override_reason' => 'Approved one-off quotation'];
    $override['items'][0]['unit_price'] = '8.500';
    $over = $manual->create($admin, $override);
    $check($over['success'] && $orders->findItems($over['orderId'])[0]['unit_price_snapshot'] === '10.500' && $orders->findItems($over['orderId'])[0]['quantity_unit_price_snapshot'] === '10.500', 'Forged catalogue price overrides are ignored in favor of the authoritative price.');
    $override['items'][0]['price_override_reason'] = '';
    $check($manual->create($admin, $override)['success'], 'Obsolete override fields cannot change normal catalogue orders.');
    $multi = $base;
    $multi['promo_code'] = '';
    $multi['manual_discount_type'] = 'NONE';
    $multi['items'][] = ['product_id' => (string)$product,'quantity' => '2'];
    $many = $manual->create($admin, $multi);
    $check($many['success'] && $orders->findSummary($many['orderId'])['subtotal'] === '240.000', 'Multiple item lines are added after independent quantity pricing.');
    $delivery = $multi + ['street_address' => '1 Candle Street','delegation' => 'Tunis','governorate' => 'Tunis','postal_code' => '1000'];
    $delivery['delivery_method'] = 'DELIVERY';
    $delivery['delivery_fee'] = '9.000';
    $delivered = $manual->create($admin, $delivery);
    $check($delivered['success'] && $orders->findSummary($delivered['orderId'])['total_amount'] === '249.000', 'Existing delivery rules add the fee after merchandise pricing.');
    $db->exec("INSERT INTO products (category_id,slug,selling_price,is_active) VALUES (1,'missing-recipe',12,1)");
    $missing = (string)$db->lastInsertId();
    $bad = $base;
    $bad['items'][0]['product_id'] = $missing;
    $result = $manual->create($admin, $bad);
    $check(!$result['success'] && str_contains(implode(' ', $result['errors']), 'Catalogue'), 'Missing recipe gives a Catalogue configuration error.');
    foreach (['0','-1'] as $qty) {
        $bad = $base;
        $bad['items'][0]['quantity'] = $qty;
        $check(!$manual->create($admin, $bad)['success'], 'Nonpositive quantity is rejected.');
    }
    $bad = $base;
    $bad['items'][0]['scent_id'] = '999999';
    $check(!$manual->create($admin, $bad)['success'], 'Unassigned product option is rejected.');
    $requests = new CustomRequestService(new CustomRequestRepository($db), $tx, new CustomerModeService($orders, $accounts));
    $request = ['customer_mode' => 'guest','guest_customer_name' => 'Guest Request','guest_customer_email' => '','guest_customer_phone' => '22000102','preferred_contact_method' => 'PHONE','category_id' => '1','requested_quantity' => '10','customer_message' => 'Special candle design'];
    $account = ['customer_mode' => 'new','new_customer_name' => 'Request Customer','new_customer_email' => 'request@example.test','new_customer_phone' => '22000103','new_customer_second_phone' => '','account_street_address' => '1 Candle Street','account_delegation' => 'Tunis','account_governorate' => 'Tunis','account_postal_code' => '1000'];
    $new = $requests->create($admin, array_replace($request, $account));
    $check($new['success'], 'Custom request reuses secure new-account creation.');
    $requestRow = static fn (int $id): array => $db->query('SELECT * FROM custom_product_requests WHERE id=' . $id)->fetch();
    $accountId = (int)$requestRow($new['requestId'])['customer_id'];
    $check($accountId > 0 && $accounts->findByEmail('request@example.test') !== null, 'Custom request links the returned new-account ID.');
    $existing = $requests->create($admin, array_replace($request, ['customer_mode' => 'existing','customer_id' => (string)$accountId]));
    $check($existing['success'] && (int)$requestRow($existing['requestId'])['customer_id'] === $accountId && $requestRow($existing['requestId'])['customer_email_snapshot'] === 'request@example.test', 'Existing request mode requires explicit selection and captures profile contacts.');
    $guest = $requests->create($admin, $request + ['customer_id' => (string)$accountId,'guest_customer_email' => 'request@example.test']);
    $check($guest['success'] && $requestRow($guest['requestId'])['customer_id'] === null, 'Guest request ignores stale IDs and contact matches.');
    $check(!$requests->create($admin, array_replace($request, $account))['success'], 'Duplicate request account is refused.');
    $check(!$requests->create($admin, array_replace($request, ['customer_mode' => 'existing','customer_id' => '']))['success'], 'Existing request mode cannot infer ownership from contacts.');
    $check(!$requests->create($admin, array_replace($request, ['customer_mode' => ['guest']]))['success'], 'Malformed request mode is rejected.');
    $db->exec("UPDATE customers_accounts SET first_name='Changed' WHERE id=$accountId");
    $check($requestRow($existing['requestId'])['customer_name_snapshot'] === 'Request Customer', 'Later account edits do not rewrite request snapshots.');
    // Fail the request INSERT after a valid new account/address has been inserted.
    $db->exec("CREATE FUNCTION fail_request_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.customer_email_snapshot='rollback-request@example.test' THEN RAISE EXCEPTION 'fixture failure'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER fail_request_fixture BEFORE INSERT ON custom_product_requests FOR EACH ROW EXECUTE FUNCTION fail_request_fixture()");
    $reject(fn () => $requests->create($admin, array_replace($request, $account, ['new_customer_email' => 'rollback-request@example.test','new_customer_phone' => '22000104'])), 'Request insertion failure rolls back its account transaction.');
    $check($accounts->findByEmail('rollback-request@example.test') === null, 'No orphan customer remains after request failure.');
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED', product_id=$product WHERE id=" . $existing['requestId']);
    $conversion = new App\Services\CustomRequestConversionService(new CustomRequestRepository($db), $tx, production:$production);
    $conversionValues = ['product_name' => 'Agreed special candle','quantity' => '10','unit_price' => '7.000','delivery_method' => 'PICKUP','delivery_fee' => '0.000','required_date' => '2026-12-10'];
    $converted = $conversion->convert($existing['requestId'], $admin, $conversionValues);
    $check($converted['success'] && $orders->findSummary($converted['orderId'])['total_amount'] === '70.000', 'Custom conversion preserves its negotiated quote instead of applying catalogue bulk tiers.');
    $convertedItem = $orders->findItems($converted['orderId'])[0];
    $check($convertedItem['recipe_source'] === 'CUSTOM' && (new OrderStatusRepository($db))->findMaterialRequirements($converted['orderId'])[0]['required_quantity'] === '500.000', 'Linked custom conversion captures its available catalogue recipe without changing the agreed price.');
    $check(!$conversion->convert($existing['requestId'], $admin, $conversionValues)['success'], 'An agreed request cannot be converted twice.');
    $db->exec("INSERT INTO orders (order_number,language_code,order_source,order_status,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount) VALUES ('ORD-LEGACY-AUDIT','en','MANUAL','REQUESTED','PICKUP','2026-12-10','Legacy','22000105',15,0,15,0)");
    $legacy = (int)$db->lastInsertId();
    $db->exec("INSERT INTO order_items (order_id,product_id,product_name_snapshot,quantity,unit_price_snapshot,line_total_snapshot) VALUES ($legacy,$product,'Legacy candle',1,15,15)");
    $check(
        (new OrderStatusRepository($db))->findMaterialRequirements($legacy) === [] && $production->orderProduction($legacy, 'REQUESTED')['missingCount'] === 1,
        'Legacy orders without historical recipes require explicit confirmation and never consume today\'s catalogue recipe.'
    );
    $db->exec("UPDATE orders SET order_status='CONFIRMED' WHERE id IN ($id,$legacy)");
    $sheet = (new App\Services\ProductionService(new ProductionRepository($db)))->sheet(['date_from' => '2026-12-10','date_to' => '2026-12-10'], new DateTimeImmutable('2026-12-10'));
    $check(
        count($sheet['toProduce']) === 1 && $sheet['toProduce'][0]['recipe_source'] === 'CATALOGUE' && $sheet['orphanedItemCount'] === 1,
        'Production sheet groups saved catalogue work and clearly excludes unconfirmed legacy recipes.'
    );
    $definition = ['working_name' => 'New personalized candle','category_id' => '1','product_type_id' => (string)$db->query('SELECT id FROM product_types WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn(),'requested_quantity' => '2','color_preferences' => 'Ivory','scent_preferences' => 'Citrus','specifications' => 'Personalized name'];
    $custom = $request;
    $custom['custom_products'] = [$definition,array_replace($definition, ['working_name' => 'Second new product','requested_quantity' => '3'])];
    $multiRequest = $requests->create($admin, $custom);
    $check($multiRequest['success'], 'One custom request records two independent new product definitions.');
    $requestId = $multiRequest['requestId'];
    $repo = new CustomRequestRepository($db);
    $definitions = $repo->findRequestProducts($requestId);
    $check(count($definitions) === 2 && $requestRow($requestId)['product_id'] === null, 'New custom definitions do not select existing catalogue products.');
    $badCustom = $custom;
    $badCustom['custom_products'][1]['product_type_id'] = '999999';
    $check(!$requests->create($admin, $badCustom)['success'], 'Invalid taxonomy is rejected before writing a custom request.');
    $badCustom = $custom;
    $badCustom['custom_products'] = [];
    $check(!$requests->create($admin, $badCustom)['success'], 'Empty custom product lists are rejected.');
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED' WHERE id=$requestId");
    $quotes = $conversionValues;
    $quotes['custom_products'] = [];
    foreach ($definitions as $definitionRow) {
        $quotes['custom_products'][] = ['definition_id' => (string)$definitionRow['id'],'product_name' => $definitionRow['working_name'],'quantity' => (string)$definitionRow['requested_quantity'],'unit_price' => '10.000','agreed_batch_price' => '','customization_notes' => $definitionRow['specifications']];
    }
    $badQuote = $quotes;
    array_pop($badQuote['custom_products']);
    $check(!$conversion->convert($requestId, $admin, $badQuote)['success'], 'Every new product must be quoted exactly once.');
    $badQuote = $quotes;
    $badQuote['custom_products'][0]['definition_id'] = '999999';
    $check(!$conversion->convert($requestId, $admin, $badQuote)['success'], 'Foreign custom definition IDs cannot be converted.');
    $multiConverted = $conversion->convert($requestId, $admin, $quotes);
    $check($multiConverted['success'], 'Multiple agreed custom products reuse the existing conversion transaction.');
    $customOrder = $multiConverted['orderId'];
    $customItems = $orders->findItems($customOrder);
    $check(count($customItems) === 2 && $orders->findSummary($customOrder)['subtotal'] === '50.000', 'One converted order contains separately quoted products and the aggregate price.');
    $check(!$conversion->createDrafts($requestId)['success'], 'Drafts cannot be created before production completion.');
    foreach ($customItems as $customItem) {
        $check($production->saveItemRecipe($customOrder, (int)$customItem['id'], [['material_id' => (string)$material,'quantity' => '1.000']])['success'], 'Each custom product receives its own existing production recipe.');
    }
    $statuses = new App\Services\OrderStatusService(new OrderStatusRepository($db), new App\Repositories\MaterialStockRepository($db, $tx), $tx, $production, new AccessoryStockService(new AccessoryStockRepository($db), $tx));
    foreach (['CONFIRMED','PREPARING','PREPARED'] as $status) {
        $check($statuses->transition($customOrder, $admin, $status, null)['success'], 'Custom production follows the existing order status: ' . $status);
    }
    $drafts = $conversion->createDrafts($requestId);
    $check($drafts['success'] && count(array_unique($drafts['draftIds'])) === 2, 'Prepared products create separate inactive catalogue drafts.');
    $check($conversion->createDrafts($requestId)['draftIds'] === $drafts['draftIds'], 'Repeated draft creation is idempotent.');
    foreach ($drafts['draftIds'] as $draft) {
        $check((int)$db->query('SELECT is_active FROM products WHERE id=' . $draft)->fetchColumn() === 0 && $catalogue->findOrderableProducts([$draft]) === [], 'New draft is absent from active catalogue/order selection.');
        $slug = (string)$db->query('SELECT slug FROM products WHERE id=' . $draft)->fetchColumn();
        $check((new App\Repositories\ProductRepository($db))->findActiveBySlug($slug) === null, 'Draft products are not visible through the public catalogue repository.');
        $adminProducts = new App\Repositories\AdminProductRepository($db);
        $readiness = new App\Services\ProductReadinessService($adminProducts, $pricing, $tx);
        $catalogueAdmin = new App\Services\AdminProductService($adminProducts, new App\Repositories\TaxonomyRepository($db), $tx, new App\Services\ProductImageStorage(sys_get_temp_dir()), $pricing, $readiness, new AccessoryStockService(new AccessoryStockRepository($db), $tx));
        $check(!$catalogueAdmin->toggleActive($draft, true)['success'], 'Normal catalogue activation refuses incomplete generated drafts.');
        $check($db->query('SELECT quantity_required FROM product_materials WHERE product_id=' . $draft)->fetchColumn() === '1.000', 'Draft uses the completed per-unit production definition.');
    }
    $preview = $manual->preview($multi);
    $check($preview['success'] && $preview['lines'][0]['bulk_from'] === '9.000', 'Admin pricing preview uses the lowest real backend tier as indicative bulk pricing.');
    $estimate = App\Services\AdminReportService::grossEstimate(190000, 120000);
    $check($estimate['profit'] === '70.000' && $estimate['margin'] === '36.8%', 'Product gross estimate uses revenue after discounts.');
    $check(App\Services\AdminReportService::grossEstimate(1000, 1500)['profit'] === '-0.500', 'Loss estimates preserve a valid signed millime amount.');
    $detail = (new App\Services\OrderService($orders))->detail($id, new DateTimeImmutable('2026-12-10'));
    $check($detail['order']['admin_estimate']['cost'] === '120.000', 'Historical gross estimate reads frozen unit costs rather than current material costs.');
    $db->exec("INSERT INTO colors (slug,is_active) VALUES ('composition-ivory',1)");
    $color = (int)$db->lastInsertId();
    $db->exec("INSERT INTO color_translations (color_id,language_code,name) VALUES ($color,'en','Ivory'); INSERT INTO product_colors (product_id,color_id) VALUES ($product,$color)");
    $missingOptions = $base;
    $missingPreview = $manual->preview($missingOptions);
    $check($missingPreview['success'] && $missingPreview['lines'][0]['base'] === '15.000' && $missingPreview['lines'][0]['quantity_price'] === '10.500' && $missingPreview['lines'][0]['line'] === '210.000', 'Missing configured color does not block authoritative standard, applied and line prices.');
    $check($missingPreview['admin_estimate'] === null && $missingPreview['notice'] !== '', 'Incomplete options withhold production cost estimates.');
    $check(!$manual->create($admin, $missingOptions)['success'], 'Order creation still rejects missing required catalogue options.');
    $missingOptions['items'][0]['color_id'] = '999999';
    $check(!$manual->preview($missingOptions)['success'], 'Price preview continues to reject forged option IDs.');
    $setCategory = (string)$db->query("SELECT id FROM categories WHERE slug='candle-sets'")->fetchColumn();
    $existingComponent = ['source' => 'PRODUCT','product_id' => (string)$product,'quantity' => '1','color_choice' => (string)$color,'scent_choice' => '','specifications' => 'Special packaging'];
    $composed = $custom;
    $composed['custom_products'] = [array_replace($definition, ['working_name' => 'Referenced wedding box','category_id' => $setCategory,'requested_quantity' => '2','components' => [$existingComponent,array_replace($existingComponent, ['source' => 'ITEM','quantity' => '2'])]])];
    $compositionRequest = $requests->create($admin, $composed);
    $check($compositionRequest['success'], 'A requested product combines existing Product and Item references.');
    $compositionId = $compositionRequest['requestId'];
    $definitionRow = $repo->findRequestProducts($compositionId)[0];
    $check(count($definitionRow['components']) === 2 && $definitionRow['components'][0]['color_mode'] === 'EXISTING' && (int)$definitionRow['components'][0]['color_id'] === $color, 'Composition stores authoritative IDs, configured option names, and source roles.');
    foreach ([['source' => 'MATERIAL'],['product_id' => '999999'],['quantity' => '0'],['color_choice' => '999999'],['color_choice' => '__custom__','color_custom' => 'Impossible existing option']] as $tampering) {
        $badComposition = $composed;
        $badComposition['custom_products'][0]['components'][0] = array_replace($existingComponent, $tampering);
        $check(!$requests->create($admin, $badComposition)['success'], 'Invalid source, reference, quantity, or existing component option is rejected.');
    }
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED' WHERE id=$compositionId");
    $composedQuote = $conversionValues;
    $composedQuote['custom_products'] = [['definition_id' => (string)$definitionRow['id'],'product_name' => 'Referenced wedding box','quantity' => '2','unit_price' => '20.000','agreed_batch_price' => '','customization_notes' => 'Existing references']];
    $compositionOrder = $conversion->convert($compositionId, $admin, $composedQuote);
    $check($compositionOrder['success'], 'Existing composition converts through the same quotation/order transaction.');
    $compositionOrderId = $compositionOrder['orderId'];
    $requirements = (new OrderStatusRepository($db))->findMaterialRequirements($compositionOrderId);
    $check($requirements[0]['required_quantity'] === '300.000', 'Existing component quantities produce an aggregate frozen recipe without duplicate definitions.');
    foreach (['CONFIRMED','PREPARING','PREPARED'] as $status) {
        $check($statuses->transition($compositionOrderId, $admin, $status, null)['success'], 'Composed production uses the existing status transition: ' . $status);
    }
    $beforeProducts = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $compositionDraft = $conversion->createDrafts($compositionId);
    $check($compositionDraft['success'] && (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $beforeProducts + 1, 'Only the requested assembly becomes a draft; referenced Products and Items are not cloned.');
    $draft = $compositionDraft['draftIds'][0];
    $check((int)$db->query("SELECT quantity FROM set_components WHERE set_product_id=$draft AND component_product_id=$product")->fetchColumn() === 3, 'Draft set reuses existing set_components and aggregates repeated references.');
    $check($db->query('SELECT COUNT(*) FROM product_materials WHERE product_id=' . $draft)->fetchColumn() === 0, 'Draft does not double-count referenced component recipes as a full parent recipe.');
    $mixed = $composed;
    $mixed['custom_products'][0]['components'][] = ['source' => 'CUSTOM','custom_name' => 'New heart candle','quantity' => '1','color_choice' => '__custom__','color_custom' => 'Pearl glow','scent_choice' => '','specifications' => 'Heart-shaped'];
    $mixedRequest = $requests->create($admin, $mixed);
    $check($mixedRequest['success'], 'Existing and genuinely custom components can coexist.');
    $mixedId = $mixedRequest['requestId'];
    $mixedDefinition = $repo->findRequestProducts($mixedId)[0];
    $check($mixedDefinition['components'][2]['color_mode'] === 'CUSTOM' && $mixedDefinition['components'][2]['color_id'] === null && $mixedDefinition['components'][2]['color_value'] === 'Pearl glow', 'Custom requested values are persisted explicitly apart from standard option IDs.');
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED' WHERE id=$mixedId");
    $mixedQuote = $composedQuote;
    $mixedQuote['custom_products'][0]['definition_id'] = (string)$mixedDefinition['id'];
    $mixedOrder = $conversion->convert($mixedId, $admin, $mixedQuote);
    $check($mixedOrder['success'], 'Mixed custom assembly converts without publishing or cloning components.');
    $check($production->orderProduction($mixedOrder['orderId'], 'REQUESTED')['missingCount'] === 1, 'Partial existing recipes cannot masquerade as a complete custom production definition.');
    $check($statuses->transition($mixedOrder['orderId'], $admin, 'CONFIRMED', null)['success'] && !$statuses->transition($mixedOrder['orderId'], $admin, 'PREPARING', null)['success'], 'Mixed composition requires the existing production-definition step before preparation.');
    $accessoryCategory = (int)$db->query("SELECT id FROM categories WHERE slug='accessories'")->fetchColumn();
    $db->exec("INSERT INTO products (category_id,slug,is_active,stock_quantity,selling_price,actual_cost) VALUES ($accessoryCategory,'request-tray',1,5,5,5)");
    $tray = (int)$db->lastInsertId();
    $trayRequest = $composed;
    $trayRequest['custom_products'][0]['components'] = [$existingComponent,['source' => 'ITEM','product_id' => (string)$tray,'quantity' => '1','color_choice' => '','scent_choice' => '','specifications' => 'Concrete tray']];
    $trayMade = $requests->create($admin, $trayRequest);
    $check($trayMade['success'], 'A physical accessory Item can be referenced without exposing raw Materials.');
    $trayId = $trayMade['requestId'];
    $trayDefinition = $repo->findRequestProducts($trayId)[0];
    $db->exec("UPDATE products SET actual_cost=7 WHERE id=$tray; UPDATE custom_product_requests SET request_status='AGREED' WHERE id=$trayId");
    $trayQuote = $composedQuote;
    $trayQuote['custom_products'][0]['definition_id'] = (string)$trayDefinition['id'];
    $trayOrder = $conversion->convert($trayId, $admin, $trayQuote);
    $check($trayOrder['success'], 'Accessory composition uses the existing stock availability check during conversion.');
    $trayOrderId = $trayOrder['orderId'];
    $trayItem = $orders->findItems($trayOrderId)[0];
    $check($trayItem['unit_cost_snapshot'] === '12.500', 'Accessory component cost is frozen alongside production material cost.');
    $stockRepo = new AccessoryStockRepository($db);
    $check($stockRepo->orderRequirements($trayOrderId)[$tray] === 2 && $stockRepo->committedQuantities([$tray])[$tray] === 2, 'Custom component requirements and commitments join the existing accessory stock model.');
    $check($production->saveItemRecipe($trayOrderId, (int)$trayItem['id'], [['material_id' => (string)$material,'quantity' => '60.000']])['success'] && $orders->findItems($trayOrderId)[0]['unit_cost_snapshot'] === '14.000', 'Production definition changes retain the accessory component cost snapshot.');
    foreach (['CONFIRMED','PREPARING','PREPARED'] as $status) {
        $check($statuses->transition($trayOrderId, $admin, $status, null)['success'], 'Accessory assembly follows existing production: ' . $status);
    }
    $check((int)$db->query('SELECT stock_quantity FROM products WHERE id=' . $tray)->fetchColumn() === 3 && $stockRepo->heldByOrder($trayOrderId)[$tray] === 2, 'Prepared custom assembly deducts physical Item stock through the existing ledger.');
    echo "Verified $checks pricing, recipe and customer assertions.\n";
} finally {
    drop_throwaway_auth_schema($db,$schema);
}
