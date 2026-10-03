<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';

use App\Database\TransactionManager;
use App\Repositories\{OrderRepository, OrderProductionRepository, OrderStatusRepository, ProductionRepository, PricingRepository, CustomerAccountRepository, CustomRequestRepository, AccessoryStockRepository, ScentStockRepository};
use App\Services\{ManualOrderService, OrderProductionService, OrderPricingService, PricingService, CustomerModeService, CustomRequestService, AccessoryStockService, ScentAvailabilityService};

[$db, $schema] = create_throwaway_auth_schema(true);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks; echo '[PASS] ' . $message . PHP_EOL;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (DomainException|PDOException $e) { $check(true, $message); return; }
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
    $tx = new TransactionManager($db); $orders = new OrderRepository($db);
    $catalogue = new OrderProductionRepository($db); $production = new OrderProductionService($catalogue,$tx);
    $pricingRepo = new PricingRepository($db); $pricing = new PricingService($pricingRepo,$tx);
    $accounts = new CustomerAccountRepository($db,$tx);
    $manual = new ManualOrderService($orders,$tx,$catalogue,$production,new AccessoryStockService(new AccessoryStockRepository($db),$tx),new ScentAvailabilityService(new ScentStockRepository($db),$tx),customers:$accounts);
    $p = static fn (): array => $catalogue->findOrderableProducts([$product])[$product];
    $check(OrderPricingService::unitPrice($p(),1)['quantity_millis'] === 12000,'Catalogue base price is authoritative without a tier.');
    foreach ([['10','24','11.000'],['25','49','10.000'],['50','','9.000']] as [$min,$max,$price]) {
        $check($pricing->addQuantityPrice($product,['minimum_quantity'=>$min,'maximum_quantity'=>$max,'unit_price'=>$price])['success'],'Configure a quantity price through the existing pricing service.');
    }
    foreach ([1=>12000,9=>12000,10=>11000,24=>11000,25=>10000,49=>10000,50=>9000,100=>9000] as $qty=>$price) {
        $check(OrderPricingService::unitPrice($p(),$qty)['quantity_millis'] === $price,'Inclusive tier boundary: quantity ' . $qty);
    }
    foreach ([['20','30','10'],['0','9','10'],['30','25','10'],['100','','13']] as [$min,$max,$price]) {
        $check(!$pricing->addQuantityPrice($product,['minimum_quantity'=>$min,'maximum_quantity'=>$max,'unit_price'=>$price])['success'],'Invalid or overlapping quantity configuration is rejected.');
    }
    $reject(fn ()=>$db->exec("INSERT INTO product_quantity_prices (product_id,minimum_quantity,maximum_quantity,unit_price) VALUES ($product,22,23,10)"),'Database also rejects ambiguous tiers.');
    $savePromo = static fn (string $code,string $type,string $value,array $extra=[]): array => $pricing->savePromotion($extra+['code'=>$code,'discount_type'=>$type,'discount_value'=>$value,'is_active'=>'1','minimum_subtotal'=>'0','valid_from'=>'','valid_until'=>'']);
    $check($savePromo('MARIEM10','PERCENTAGE','10')['success'] && $savePromo('FIXED5','FIXED_TND','5')['success'],'Percentage and fixed promotions have explicit configuration.');
    $check($savePromo('NAME99','PERCENTAGE','2')['success'],'Code text does not determine the configured discount.');
    $sale = new OrderPricingService($pricingRepo);
    $quote = $sale->quote(220000,9000,['promo_code'=>'mariem10','manual_discount_type'=>'FIXED_TND','manual_discount_value'=>'8']);
    $check($quote['promo_millis']===22000 && $quote['manual_millis']===8000 && $quote['total_millis']===199000 && $quote['deposit_millis']===79600,'Promo, manual discount, delivery and existing 40% deposit stack in the required order.');
    $check($sale->quote(220000,0,['promo_code'=>'FIXED5'])['total_millis']===215000,'Fixed promotion and pickup zero fee.');
    $check($sale->quote(220000,0,['promo_code'=>'NAME99'])['promo_millis']===4400,'Configured 2% is used even when a code contains 99.');
    $check($sale->quote(220000,9000,['promo_code'=>'MARIEM10','manual_discount_type'=>'PERCENTAGE','manual_discount_value'=>'5'])['manual_millis']===9900,'Manual percentage is calculated after the promo.');
    $check(OrderPricingService::calculate(1001,0,['manual_discount_type'=>'PERCENTAGE','manual_discount_value'=>'50'])['manual_millis']===501,'Millime rounding is exact and half-up.');
    $savePromo('EXPIRED','FIXED_TND','5',['valid_until'=>'2020-01-01']);
    $savePromo('FUTURE','FIXED_TND','5',['valid_from'=>'2099-01-01']);
    $savePromo('INACTIVE','FIXED_TND','5',['is_active'=>'0']);
    $savePromo('MINIMUM','FIXED_TND','5',['minimum_subtotal'=>'300']);
    foreach (['INVALID','EXPIRED','FUTURE','INACTIVE','MINIMUM'] as $code) $reject(fn ()=>$sale->quote(220000,0,['promo_code'=>$code]),'Unavailable promotion is rejected: ' . $code);
    foreach ([['FIXED_TND','221'],['FIXED_TND','-1'],['PERCENTAGE','101'],['PERCENTAGE','abc']] as [$type,$value]) $reject(fn ()=>$sale->quote(220000,0,['manual_discount_type'=>$type,'manual_discount_value'=>$value]),'Invalid or excessive manual discount is rejected.');
    $base=['customer_mode'=>'guest','guest_customer_name'=>'Pricing Guest','guest_customer_phone'=>'22000101','guest_customer_email'=>'','delivery_method'=>'PICKUP','required_date'=>'2026-12-10','delivery_fee'=>'0.000','items'=>[['product_id'=>(string)$product,'quantity'=>'20','unit_price'=>'0.001','recipe'=>[['material_id'=>(string)$material,'quantity'=>'999']]]],'catalogue_only'=>true,'promo_code'=>'MARIEM10','manual_discount_type'=>'FIXED_TND','manual_discount_value'=>'8','manual_discount_reason'=>'Repeat customer','total_amount'=>'0.001','promo_discount_amount'=>'999'];
    $made=$manual->create($admin,$base); $check($made['success'],'A normal catalogue order loads server prices and recipes.');
    $id=$made['orderId']; $row=$orders->findSummary($id); $item=$orders->findItems($id)[0];
    $check($row['subtotal']==='220.000' && $row['total_amount']==='190.000' && $row['delivery_fee']==='0.000' && $row['quantity_savings_snapshot']==='20.000','Forged prices, totals and discount amounts are ignored; pickup has zero fee.');
    $check($item['catalogue_unit_price_snapshot']==='12.000' && $item['quantity_unit_price_snapshot']==='11.000' && $item['unit_price_snapshot']==='11.000','Base, tier and effective prices are saved independently.');
    $saved=$db->query('SELECT * FROM order_item_materials WHERE order_item_id=' . $item['id'])->fetch();
    $check($saved['quantity_required']==='40.000' && $item['recipe_source']==='CATALOGUE','Recipe is saved per unit; browser recipe quantities are ignored.');
    $requirements=(new OrderStatusRepository($db))->findMaterialRequirements($id);
    $check($requirements[0]['required_quantity']==='800.000' && (new ProductionRepository($db))->findMaterialRequirements([$id])[0]['required_quantity']==='800.000','Production planning and inventory deduction both multiply the saved recipe by ordered quantity.');
    $workLines=(new ProductionRepository($db))->findProductionLines([$id]);
    $check((int)$workLines[0]['custom_recipe']===2 && $workLines[0]['product_name']===$item['product_name_snapshot'],'Production groups identify a saved catalogue recipe and use the sold product name.');
    $db->exec("UPDATE products SET selling_price=15 WHERE id=$product; UPDATE product_materials SET quantity_required=50 WHERE product_id=$product; UPDATE product_quantity_prices SET unit_price=10.500 WHERE product_id=$product AND minimum_quantity=10");
    $savePromo('MARIEM10','PERCENTAGE','20');
    $check($orders->findSummary($id)['total_amount']==='190.000' && $orders->findItems($id)[0]['unit_price_snapshot']==='11.000' && (new OrderStatusRepository($db))->findMaterialRequirements($id)[0]['required_quantity']==='800.000','Later base, tier, promo and recipe changes cannot mutate the saved order.');
    $payments = new App\Services\PaymentService(new App\Repositories\PaymentRepository($db),$tx,new App\Services\PaymentEvidenceStorage(sys_get_temp_dir()));
    $paymentSummary=$payments->orderSummary($id)['order'];
    $check($paymentSummary['total_amount']==='190.000' && $paymentSummary['remaining_balance']==='190.000' && $paymentSummary['next_d17_amount']==='76.000',
        'Payment and D17 consume the discounted saved total and its 40% deposit.');
    $db->exec("INSERT INTO payments (order_id,payment_method,payment_type,payment_status,amount,currency,verified_at) VALUES ($id,'CASH_ON_DELIVERY','DEPOSIT','VERIFIED',76,'TND',NOW())");
    $check($payments->orderSummary($id)['order']['next_d17_amount']==='114.000','D17 remaining amount uses the final order total after its verified deposit.');
    $edit=new App\Services\OrderEditService($orders,new OrderStatusRepository($db),$tx);
    $beforeEdit=$orders->findSummary($id);
    $editValues=array_replace(App\Services\OrderEditService::values($beforeEdit),['delivery_method'=>'DELIVERY','delivery_fee'=>'9.000','street_address'=>'1 Candle Street','delegation'=>'Tunis','governorate'=>'Tunis','postal_code'=>'1000']);
    $edited=$edit->update($id,$editValues,App\Services\OrderEditService::version($beforeEdit),$admin);
    $check($edited['success'] && $orders->findSummary($id)['total_amount']==='199.000' && $orders->findSummary($id)['promo_discount_amount']==='22.000'
        && $orders->findItems($id)[0]===$item,'Permitted fulfilment correction retains saved discounts and all item price/recipe audits.');
    $reject(fn ()=>$db->exec("UPDATE order_item_materials SET quantity_required=50 WHERE order_item_id=".$item['id']),'Captured catalogue recipe cannot be edited directly.');
    $reject(fn ()=>$db->exec("UPDATE orders SET promo_discount_amount=0,total_amount=subtotal-manual_discount_amount+delivery_fee WHERE id=$id"),'Agreed discount audit is immutable.');
    $override=$base; $override['promo_code']=''; $override['manual_discount_type']='NONE'; $override['items'][0]+=['price_override'=>'1','price_override_reason'=>'Approved one-off quotation']; $override['items'][0]['unit_price']='8.500';
    $over=$manual->create($admin,$override); $check($over['success'] && $orders->findItems($over['orderId'])[0]['unit_price_snapshot']==='8.500' && $orders->findItems($over['orderId'])[0]['quantity_unit_price_snapshot']==='10.500','Exceptional override preserves the server-calculated price and its reason.');
    $override['items'][0]['price_override_reason']=''; $check(!$manual->create($admin,$override)['success'],'Override requires a reason.');
    $multi=$base; $multi['promo_code']=''; $multi['manual_discount_type']='NONE'; $multi['items'][]=['product_id'=>(string)$product,'quantity'=>'2'];
    $many=$manual->create($admin,$multi); $check($many['success'] && $orders->findSummary($many['orderId'])['subtotal']==='240.000','Multiple item lines are added after independent quantity pricing.');
    $delivery=$multi+['street_address'=>'1 Candle Street','delegation'=>'Tunis','governorate'=>'Tunis','postal_code'=>'1000']; $delivery['delivery_method']='DELIVERY'; $delivery['delivery_fee']='9.000';
    $delivered=$manual->create($admin,$delivery); $check($delivered['success'] && $orders->findSummary($delivered['orderId'])['total_amount']==='249.000','Existing delivery rules add the fee after merchandise pricing.');
    $db->exec("INSERT INTO products (category_id,slug,selling_price,is_active) VALUES (1,'missing-recipe',12,1)"); $missing=(string)$db->lastInsertId();
    $bad=$base; $bad['items'][0]['product_id']=$missing; $result=$manual->create($admin,$bad);
    $check(!$result['success'] && str_contains(implode(' ',$result['errors']),'Catalogue'),'Missing recipe gives a Catalogue configuration error.');
    foreach (['0','-1'] as $qty) { $bad=$base; $bad['items'][0]['quantity']=$qty; $check(!$manual->create($admin,$bad)['success'],'Nonpositive quantity is rejected.'); }
    $bad=$base; $bad['items'][0]['scent_id']='999999'; $check(!$manual->create($admin,$bad)['success'],'Unassigned product option is rejected.');
    $requests=new CustomRequestService(new CustomRequestRepository($db),$tx,new CustomerModeService($orders,$accounts));
    $request=['customer_mode'=>'guest','guest_customer_name'=>'Guest Request','guest_customer_email'=>'','guest_customer_phone'=>'22000102','preferred_contact_method'=>'PHONE','category_id'=>'1','requested_quantity'=>'10','customer_message'=>'Special candle design'];
    $account=['customer_mode'=>'new','new_customer_name'=>'Request Customer','new_customer_email'=>'request@example.test','new_customer_phone'=>'22000103','new_customer_second_phone'=>'','account_street_address'=>'1 Candle Street','account_delegation'=>'Tunis','account_governorate'=>'Tunis','account_postal_code'=>'1000'];
    $new=$requests->create($admin,array_replace($request,$account)); $check($new['success'],'Custom request reuses secure new-account creation.');
    $requestRow=static fn (int $id): array => $db->query('SELECT * FROM custom_product_requests WHERE id='.$id)->fetch();
    $accountId=(int)$requestRow($new['requestId'])['customer_id']; $check($accountId>0 && $accounts->findByEmail('request@example.test')!==null,'Custom request links the returned new-account ID.');
    $existing=$requests->create($admin,array_replace($request,['customer_mode'=>'existing','customer_id'=>(string)$accountId]));
    $check($existing['success'] && (int)$requestRow($existing['requestId'])['customer_id']===$accountId && $requestRow($existing['requestId'])['customer_email_snapshot']==='request@example.test','Existing request mode requires explicit selection and captures profile contacts.');
    $guest=$requests->create($admin,$request+['customer_id'=>(string)$accountId,'guest_customer_email'=>'request@example.test']);
    $check($guest['success'] && $requestRow($guest['requestId'])['customer_id']===null,'Guest request ignores stale IDs and contact matches.');
    $check(!$requests->create($admin,array_replace($request,$account))['success'],'Duplicate request account is refused.');
    $check(!$requests->create($admin,array_replace($request,['customer_mode'=>'existing','customer_id'=>'']))['success'],'Existing request mode cannot infer ownership from contacts.');
    $check(!$requests->create($admin,array_replace($request,['customer_mode'=>['guest']]))['success'],'Malformed request mode is rejected.');
    $db->exec("UPDATE customers_accounts SET first_name='Changed' WHERE id=$accountId");
    $check($requestRow($existing['requestId'])['customer_name_snapshot']==='Request Customer','Later account edits do not rewrite request snapshots.');
    // Fail the request INSERT after a valid new account/address has been inserted.
    $db->exec("CREATE FUNCTION fail_request_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.customer_email_snapshot='rollback-request@example.test' THEN RAISE EXCEPTION 'fixture failure'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER fail_request_fixture BEFORE INSERT ON custom_product_requests FOR EACH ROW EXECUTE FUNCTION fail_request_fixture()");
    $reject(fn ()=>$requests->create($admin,array_replace($request,$account,['new_customer_email'=>'rollback-request@example.test','new_customer_phone'=>'22000104'])),'Request insertion failure rolls back its account transaction.');
    $check($accounts->findByEmail('rollback-request@example.test')===null,'No orphan customer remains after request failure.');
    $db->exec("UPDATE custom_product_requests SET request_status='AGREED', product_id=$product WHERE id=".$existing['requestId']);
    $conversion = new App\Services\CustomRequestConversionService(new CustomRequestRepository($db),$tx,production:$production);
    $conversionValues=['product_name'=>'Agreed special candle','quantity'=>'10','unit_price'=>'7.000','delivery_method'=>'PICKUP','delivery_fee'=>'0.000','required_date'=>'2026-12-10'];
    $converted=$conversion->convert($existing['requestId'],$admin,$conversionValues);
    $check($converted['success'] && $orders->findSummary($converted['orderId'])['total_amount']==='70.000','Custom conversion preserves its negotiated quote instead of applying catalogue bulk tiers.');
    $convertedItem=$orders->findItems($converted['orderId'])[0];
    $check($convertedItem['recipe_source']==='CUSTOM' && (new OrderStatusRepository($db))->findMaterialRequirements($converted['orderId'])[0]['required_quantity']==='500.000','Linked custom conversion captures its available catalogue recipe without changing the agreed price.');
    $check(!$conversion->convert($existing['requestId'],$admin,$conversionValues)['success'],'An agreed request cannot be converted twice.');
    $db->exec("INSERT INTO orders (order_number,language_code,order_source,order_status,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount) VALUES ('ORD-LEGACY-AUDIT','en','MANUAL','REQUESTED','PICKUP','2026-12-10','Legacy','22000105',15,0,15,0)");
    $legacy=(int)$db->lastInsertId();
    $db->exec("INSERT INTO order_items (order_id,product_id,product_name_snapshot,quantity,unit_price_snapshot,line_total_snapshot) VALUES ($legacy,$product,'Legacy candle',1,15,15)");
    $check((new OrderStatusRepository($db))->findMaterialRequirements($legacy)===[] && $production->orderProduction($legacy,'REQUESTED')['missingCount']===1,
        'Legacy orders without historical recipes require explicit confirmation and never consume today\'s catalogue recipe.');
    $db->exec("UPDATE orders SET order_status='CONFIRMED' WHERE id IN ($id,$legacy)");
    $sheet=(new App\Services\ProductionService(new ProductionRepository($db)))->sheet(['date_from'=>'2026-12-10','date_to'=>'2026-12-10'],new DateTimeImmutable('2026-12-10'));
    $check(count($sheet['toProduce'])===1 && $sheet['toProduce'][0]['recipe_source']==='CATALOGUE' && $sheet['orphanedItemCount']===1,
        'Production sheet groups saved catalogue work and clearly excludes unconfirmed legacy recipes.');
    echo "Verified $checks pricing, recipe and customer assertions.\n";
} finally { drop_throwaway_auth_schema($db,$schema); }
