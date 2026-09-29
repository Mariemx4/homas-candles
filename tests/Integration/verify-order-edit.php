<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../../app/config/database.php';

use App\Controllers\OrderEditController;
use App\Controllers\OrderController;
use App\Database\TransactionManager;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Security\CsrfTokenManager;
use App\Services\OrderEditService;
use App\Services\OrderService;

$connection = database();
$repository = new OrderRepository($connection);
$service = new OrderEditService($repository, new OrderStatusRepository($connection), new TransactionManager($connection));
$csrf = new CsrfTokenManager();
$controller = new OrderEditController($service, $csrf);
$_SESSION = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo '[PASS] ' . $message . PHP_EOL;
};
$connection->beginTransaction();
try {
    $insert = $connection->prepare("INSERT INTO orders
        (order_number, language_code, order_source, order_status, delivery_method, required_date,
         customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount)
        VALUES (:number, 'en', 'MANUAL', 'REQUESTED', 'PICKUP', '2026-12-10', 'Edit fixture', '90000001', 95.125, 0.000, 95.125, 0.000)");
    $insert->execute(['number' => 'ORD-EDIT-' . bin2hex(random_bytes(5))]);
    $id = (int) $connection->lastInsertId();
    $repository->insertManualOrderItem(['order_id' => $id, 'product_name' => 'Snapshot candle', 'quantity' => 1,
        'unit_price' => '95.125', 'line_total' => '95.125', 'customization_notes' => 'Keep this snapshot']);
    $original = $repository->findSummary($id);
    $originalItems = $repository->findItems($id);
    $values = OrderEditService::values($original);
    $version = OrderEditService::version($original);
    $check($version === OrderEditService::version($repository->lockForEdit($id)), 'The edit version matches between the display query and locked row.');
    $values['customer_name'] = '<Updated customer>';
    $values['required_date'] = '2026-12-12';
    $values['administrator_notes'] = "First line\nSecond line";
    $badCsrf = $controller->process($id, $values + ['version' => $version, 'csrf_token' => 'invalid']);
    $check(!$badCsrf['success'] && $repository->findSummary($id) === $original, 'Invalid CSRF leaves the order untouched.');
    $check(!isset($badCsrf['values']['csrf_token']), 'CSRF tokens are excluded from redisplay values.');
    foreach (['customer_phone' => 'abc', 'required_date' => '2026-02-30', 'customer_email' => 'bad-email', 'customer_name' => '', 'administrator_notes' => str_repeat('a', 10001)] as $field => $invalid) {
        $result = $service->update($id, array_replace($values, [$field => $invalid]), $version);
        $check(!$result['success'] && isset($result['errors'][$field]), 'Invalid ' . $field . ' is rejected.');
    }
    $result = $controller->process($id, $values + ['version' => $version, 'csrf_token' => $csrf->token(), 'order_status' => 'DELIVERED']);
    $updated = $repository->findSummary($id);
    $check($result['success'] && $updated['customer_name_snapshot'] === '<Updated customer>' && $updated['required_date'] === '2026-12-12', 'Customer and date edits persist through the controller.');
    $check($updated['administrator_notes'] === "First line\nSecond line" && $updated['order_status'] === 'REQUESTED'
        && $updated['order_number'] === $original['order_number'] && $repository->findItems($id) === $originalItems,
        'Notes retain line breaks, and item snapshots, order number, and status are preserved.');
    $check(!$service->update($id, $values, $version)['success'], 'A stale form cannot overwrite a newer edit.');
    $delivery = array_replace(OrderEditService::values($updated), ['delivery_method' => 'DELIVERY', 'delivery_fee' => '9.000',
        'street_address' => '1 Test Street', 'delegation' => 'Test delegation', 'governorate' => 'Tunis', 'postal_code' => '1000']);
    $check(!$service->update($id, array_replace($delivery, ['street_address' => '']), OrderEditService::version($updated))['success'], 'Delivery requires a complete address.');
    $check($service->update($id, $delivery, OrderEditService::version($updated))['success'], 'An unconfirmed order can switch to delivery.');
    $updated = $repository->findSummary($id);
    $check($updated['total_amount'] === '104.125' && $updated['required_deposit_amount'] === '41.650', 'Delivery recalculates total and deposit in exact TND thousandths.');
    $payment = $connection->prepare("INSERT INTO payments (order_id, payment_method, payment_type, payment_status, amount, currency, verified_at)
        VALUES (:id, 'CASH_ON_DELIVERY', 'BALANCE', 'VERIFIED', 100.000, 'TND', CURRENT_TIMESTAMP)");
    $payment->execute(['id' => $id]);
    $pickup = array_replace($delivery, ['delivery_method' => 'PICKUP', 'delivery_fee' => '0.000']);
    $check($service->update($id, $pickup, OrderEditService::version($updated))['success'], 'A paid order can switch to pickup.');
    $creditDetail = (new OrderService($repository))->detail($id, new DateTimeImmutable('today'));
    $check($creditDetail['order']['credit_balance'] === '4.875' && $creditDetail['order']['amount_paid'] === '100.000', 'Switching to pickup preserves verified payments and shows the credit due.');
    $status = $connection->prepare("UPDATE orders SET order_status = 'PREPARED', price_locked_at = CURRENT_TIMESTAMP WHERE id = :id");
    $status->execute(['id' => $id]);
    $updated = $repository->findSummary($id);
    $check($service->update($id, array_replace($delivery, ['delivery_fee' => '10.000']), OrderEditService::version($updated))['success'], 'A prepared order can switch to delivery with an updated fee.');
    $updated = $repository->findSummary($id);
    $delivery['administrator_notes'] = 'Corrected preparation note';
    $check($service->update($id, $delivery, OrderEditService::version($updated))['success'], 'A prepared order still allows details and notes to be corrected.');
    $check(!$service->update(PHP_INT_MAX, $values, $version)['success'], 'Missing orders fail safely.');

    define('BASE_URL', '/homas-candles/public/');
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $order = $repository->findSummary($id);
    $editValues = OrderEditService::values($order) + ['version' => OrderEditService::version($order)];
    $editFlash = ['errors' => [], 'values' => [], 'success' => null];
    $canChangeFulfilment = OrderEditService::canChangeFulfilment($order);
    $editUrl = BASE_URL . 'admin/orders/edit.php?id=' . $id . '&return=q%3Dtest';
    $detailUrl = BASE_URL . 'admin/orders/detail.php?id=' . $id;
    ob_start();
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-order-edit.php';
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $check($xpath->query('//input[@name="customer_name"]')->item(0)->getAttribute('value') === '<Updated customer>'
        && !str_contains($html, 'value="<Updated customer>"'), 'The form prefills exact values and escapes stored HTML.');
    $check($xpath->query('//select[@name="delivery_method"]/option')->length === 2
        && $xpath->query('//input[@name="delivery_fee" and not(@readonly)]')->length === 1
        && str_contains($html, 'Save changes') && str_contains($html, 'Cancel'), 'The form provides delivery/pickup choices and an editable delivery fee at every status.');
    $orderList = (new OrderController(new OrderService($repository)))->index(['q' => $order['order_number']]);
    ob_start();
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-orders.php';
    $html = (string) ob_get_clean();
    $check(str_contains($html, '>Actions</th>') && str_contains($html, 'admin/orders/edit.php?id=' . $id . '&amp;return='), 'Orders expose an Edit action that retains the list filters.');
    $check(str_contains($html, 'admin/orders/delete.php?id=' . $id) && str_contains($html, 'admin/orders/trash.php'), 'Orders expose Delete and Trash actions.');

    $tag = bin2hex(random_bytes(6));
    $account = $connection->prepare("INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash, account_status) VALUES ('Linked', 'Fixture', :email, :phone, :hash, 'ACTIVE')");
    $account->execute(['email' => $tag . '@order-link.test', 'phone' => '98' . random_int(100000, 999999), 'hash' => password_hash('fixture-only', PASSWORD_DEFAULT)]);
    $customerId = (int) $connection->lastInsertId();
    $customerRepository = new \App\Repositories\AdminCustomerRepository($connection);
    $updated = $repository->findSummary($id);
    $linkedValues = array_replace(OrderEditService::values($updated), ['customer_id' => (string) $customerId]);
    $check($service->update($id, $linkedValues, OrderEditService::version($updated))['success']
        && $customerRepository->countOrders($customerId) === 1, 'Linking an existing order adds it to the selected account history.');
    $linkedOrder = $repository->findSummary($id);
    $check($linkedOrder['customer_name_snapshot'] === $updated['customer_name_snapshot'], 'Linking an account preserves the order contact snapshot.');
    $check(!$service->update($id, array_replace($linkedValues, ['customer_id' => (string) PHP_INT_MAX]), OrderEditService::version($linkedOrder))['success'], 'A nonexistent customer account cannot be linked.');
    $check($service->update($id, array_replace($linkedValues, ['customer_id' => '']), OrderEditService::version($linkedOrder))['success']
        && $customerRepository->countOrders($customerId) === 0, 'A guest order can be unlinked without changing its contact details.');
    $check($repository->findCustomer($customerId)['customer_name'] === 'Linked Fixture'
        && count($repository->searchCustomers($tag)) === 1, 'Account search finds selectable real accounts.');
    $adminInsert = $connection->prepare("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status) VALUES ('Order', 'Fixture', :email, :phone, :hash, 'ACTIVE')");
    $adminInsert->execute(['email' => $tag . '@order-admin.test', 'phone' => '97' . random_int(100000, 999999), 'hash' => password_hash('fixture-only', PASSWORD_DEFAULT)]);
    $adminId = (int) $connection->lastInsertId();
    $transactions = new TransactionManager($connection);
    $productionRepository = new \App\Repositories\OrderProductionRepository($connection);
    $manualService = new \App\Services\ManualOrderService($repository, $transactions, $productionRepository,
        new \App\Services\OrderProductionService($productionRepository, $transactions),
        new \App\Services\AccessoryStockService(new \App\Repositories\AccessoryStockRepository($connection), $transactions),
        new \App\Services\ScentAvailabilityService(new \App\Repositories\ScentStockRepository($connection), $transactions));
    // A custom (unlinked) manual item must carry an explicit production recipe.
    $connection->prepare("INSERT INTO materials (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
        VALUES (1, :sku, 'Edit fixture wax', 'kg', NULL, 20.000, 0, 1)")->execute(['sku' => 'EDIT-' . $tag]);
    $materialId = (string) $connection->lastInsertId();
    $created = $manualService->create($adminId, array_replace($linkedValues, ['items' => [['product_name' => 'Linked candle', 'quantity' => '1', 'unit_price' => '25.000',
        'customization_notes' => '', 'recipe' => [['material_id' => $materialId, 'quantity' => '0.250']]]]]));
    $check($created['success'] && $repository->findSummary($created['orderId'])['customer_id'] === $customerId
        && $customerRepository->countOrders($customerId) === 1, 'New manual orders persist the selected customer account.');

    $connection->prepare("UPDATE orders SET order_status = 'DELIVERED' WHERE id = :id")->execute(['id' => $id]);
    $updated = $repository->findSummary($id);
    $switchToPickup = array_replace(OrderEditService::values($updated), ['customer_id' => (string) $customerId, 'delivery_method' => 'PICKUP', 'delivery_fee' => '9.000']);
    $check($controller->process($id, $switchToPickup + ['version' => OrderEditService::version($updated), 'csrf_token' => $csrf->token()], $adminId)['success'], 'A completed delivery order can switch to pickup through the protected form.');
    $updated = $repository->findSummary($id);
    $check($updated['delivery_fee'] === '0.000' && $updated['order_status'] === 'PICKED_UP' && $updated['street_address_snapshot'] === null,
        'Pickup removes delivery fees and addresses and maps Delivered to Picked up.');
    $switchToDelivery = array_replace($delivery, ['customer_id' => (string) $customerId]);
    $check($service->update($id, $switchToDelivery, OrderEditService::version($updated), $adminId)['success']
        && $repository->findSummary($id)['order_status'] === 'DELIVERED', 'Switching a completed pickup back to delivery keeps a matching status.');
    $history = $repository->findStatusHistory($id);
    $check(count($history) === 2 && (int) $history[0]['id'] > 0, 'Fulfilment status changes are added to order history.');

    $trashController = new \App\Controllers\OrderTrashController($repository, new TransactionManager($connection), $csrf,
        new \App\Services\AccessoryStockService(new \App\Repositories\AccessoryStockRepository($connection), new TransactionManager($connection)));
    $updated = $repository->findSummary($id);
    $deletePost = ['id' => (string) $id, 'version' => OrderEditService::version($updated)];
    $check(!$trashController->process($deletePost + ['csrf_token' => 'invalid'], $adminId)['success'], 'Delete requires a valid CSRF token.');
    $check(!$trashController->process(array_replace($deletePost, ['version' => 'stale', 'csrf_token' => $csrf->token()]), $adminId)['success'], 'A stale delete confirmation cannot remove a changed order.');
    $check($trashController->process($deletePost + ['csrf_token' => $csrf->token()], $adminId)['success'], 'Delete moves a paid order to Trash.');
    $check($repository->findSummary($id) === null && $repository->lockForEdit($id) === null
        && (new OrderStatusRepository($connection))->lockOrder($id) === null, 'Deleted orders cannot be edited or have their status changed.');
    $check(count($repository->findPayments($id)) === 1 && $repository->findItems($id) === $originalItems && count($repository->findStatusHistory($id)) === 2,
        'Trash preserves payments, item snapshots, and order history.');
    $check($customerRepository->countOrders($customerId) === 1
        && (new OrderController(new OrderService($repository)))->index(['q' => $updated['order_number']])['total'] === 0,
        'Trashed orders disappear from active order and account-history lists.');
    $check(in_array($id, array_column($repository->trashPage(1)['orders'], 'id'), true), 'The deleted order is available in the Trash list.');
    $check($trashController->process(['id' => (string) $id, 'csrf_token' => $csrf->token()], $adminId, true)['success']
        && $repository->findSummary($id)['customer_id'] === $customerId && $customerRepository->countOrders($customerId) === 2,
        'Restore returns the order with its account link and history intact.');
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
}
echo $checks . ' checks passed; all test rows rolled back.' . PHP_EOL;
