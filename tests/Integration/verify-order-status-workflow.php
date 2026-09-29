<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Order Status Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-orders.php';

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$administratorId = null;
$materialIds = [];
$productIds = [];
$orderIds = [];

$statusOf = static function (int $orderId) use ($databaseConnection): string {
    $statement = $databaseConnection->prepare('SELECT order_status FROM orders WHERE id = :id');
    $statement->execute(['id' => $orderId]);
    return (string) $statement->fetchColumn();
};
$historyCount = static function (int $orderId) use ($databaseConnection): int {
    $statement = $databaseConnection->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id = :id');
    $statement->execute(['id' => $orderId]);
    return (int) $statement->fetchColumn();
};
$stockOf = static function (int $materialId) use ($databaseConnection): string {
    $statement = $databaseConnection->prepare('SELECT stock_quantity FROM materials WHERE id = :id');
    $statement->execute(['id' => $materialId]);
    return (string) $statement->fetchColumn();
};
$movementCount = static function (int $orderId) use ($databaseConnection): int {
    $statement = $databaseConnection->prepare(
        "SELECT COUNT(*) FROM material_stock_movements
         WHERE order_id = :id AND movement_type = 'ORDER_DEDUCTION'"
    );
    $statement->execute(['id' => $orderId]);
    return (int) $statement->fetchColumn();
};

try {
    $administrator = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, \'ACTIVE\')'
    );
    $administrator->execute([
        'first_name' => 'Workflow',
        'last_name' => 'Verifier',
        'email' => "workflow-{$suffix}@example.test",
        'phone' => '7' . substr($suffix, -7),
        'password_hash' => password_hash('WorkflowVerifier123!', PASSWORD_DEFAULT),
    ]);
    $administratorId = (int) $databaseConnection->lastInsertId();

    $material = $databaseConnection->prepare(
        'INSERT INTO materials
            (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
         VALUES (1, :sku, :name, \'piece\', :stock, 1.000, 1, 1)'
    );
    foreach ([['MAIN', '100.000'], ['SCARCE', '1.000']] as [$label, $stock]) {
        $material->execute([
            'sku' => "M03-{$label}-{$suffix}",
            'name' => "Module 03 {$label} {$suffix}",
            'stock' => $stock,
        ]);
        $materialIds[$label] = (int) $databaseConnection->lastInsertId();
    }

    $product = $databaseConnection->prepare(
        'INSERT INTO products
            (slug, selling_price, minimum_quantity, purchase_mode,
             is_made_to_order, can_purchase_alone, is_active)
         VALUES (:slug, 50.000, 1, \'CART\', :made_to_order, 1, 0)'
    );
    foreach ([
        ['SIMPLE', 1],
        ['COMPONENT', 1],
        ['SET', 1],
        ['FAILING', 1],
    ] as [$label, $madeToOrder]) {
        $product->execute([
            'slug' => 'm03-' . strtolower($label) . '-' . $suffix,
            'made_to_order' => $madeToOrder,
        ]);
        $productIds[$label] = (int) $databaseConnection->lastInsertId();
    }

    $recipe = $databaseConnection->prepare(
        "INSERT INTO product_materials
            (product_id, material_id, recipe_type, quantity_required)
         VALUES (:product_id, :material_id, 'ACTUAL', :quantity)"
    );
    foreach ([
        [$productIds['SIMPLE'], $materialIds['MAIN'], '2.000'],
        [$productIds['COMPONENT'], $materialIds['MAIN'], '2.000'],
        [$productIds['SET'], $materialIds['MAIN'], '1.000'],
        [$productIds['FAILING'], $materialIds['MAIN'], '2.000'],
        [$productIds['FAILING'], $materialIds['SCARCE'], '5.000'],
    ] as [$productId, $materialId, $quantity]) {
        $recipe->execute(['product_id' => $productId, 'material_id' => $materialId, 'quantity' => $quantity]);
    }
    $component = $databaseConnection->prepare(
        'INSERT INTO set_components (set_product_id, component_product_id, quantity)
         VALUES (:set_id, :component_id, 2)'
    );
    $component->execute(['set_id' => $productIds['SET'], 'component_id' => $productIds['COMPONENT']]);

    $insertOrder = $databaseConnection->prepare(
        'INSERT INTO orders
            (order_number, language_code, order_source, order_status,
             delivery_method, required_date, customer_name_snapshot,
             customer_phone_snapshot, street_address_snapshot, delegation_snapshot,
             governorate_snapshot, postal_code_snapshot, subtotal, delivery_fee,
             total_amount, required_deposit_amount)
         VALUES
            (:number, \'en\', \'CART\', :status, :method, \'2026-09-10\',
             :customer, \'90000005\', :street, :delegation, :governorate,
             :postal_code, :subtotal, :delivery_fee, :total, :deposit)'
    );
    $addOrder = static function (
        string $label,
        string $status = 'REQUESTED',
        string $method = 'PICKUP',
        string $total = '50.000',
        string $deposit = '0.000'
    ) use ($insertOrder, $databaseConnection, $suffix, &$orderIds): int {
        $delivery = $method === 'DELIVERY';
        $deliveryFee = $delivery ? '9.000' : '0.000';
        $subtotal = number_format((float) $total - (float) $deliveryFee, 3, '.', '');
        $insertOrder->execute([
            'number' => "M03-{$label}-{$suffix}",
            'status' => $status,
            'method' => $method,
            'customer' => "Workflow {$label}",
            'street' => $delivery ? '1 Workflow Street' : null,
            'delegation' => $delivery ? 'Tunis' : null,
            'governorate' => $delivery ? 'Tunis' : null,
            'postal_code' => $delivery ? '1000' : null,
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'deposit' => $deposit,
        ]);
        $id = (int) $databaseConnection->lastInsertId();
        $orderIds[$label] = $id;
        return $id;
    };

    $insertItem = $databaseConnection->prepare(
        'INSERT INTO order_items
            (order_id, product_id, product_name_snapshot, quantity,
             unit_price_snapshot, line_total_snapshot)
         VALUES (:order_id, :product_id, :name, :quantity, 10.000, :line_total)'
    );
    $addItem = static function (int $orderId, int $productId, int $quantity, string $name) use ($insertItem): void {
        $insertItem->execute([
            'order_id' => $orderId,
            'product_id' => $productId,
            'name' => $name,
            'quantity' => $quantity,
            'line_total' => number_format(10 * $quantity, 3, '.', ''),
        ]);
    };

    $pickupId = $addOrder('PICKUP');
    $addItem($pickupId, $productIds['SIMPLE'], 3, 'Simple candle snapshot');

    $confirmation = $orderStatusService->transition(
        $pickupId,
        $administratorId,
        'CONFIRMED',
        'Customer confirmation recorded.'
    );
    $check($confirmation['success'] && $statusOf($pickupId) === 'CONFIRMED', 'REQUESTED may advance to CONFIRMED.');
    $repeat = $orderStatusService->transition($pickupId, $administratorId, 'CONFIRMED', null);
    $check(!$repeat['success'] && $historyCount($pickupId) === 1, 'Repeated submissions cannot duplicate a status-history effect.');
    $check($orderStatusService->transition($pickupId, $administratorId, 'PREPARING', null)['success'], 'CONFIRMED may advance to PREPARING.');
    $check($orderStatusService->transition($pickupId, $administratorId, 'PREPARED', null)['success'], 'PREPARING may advance to PREPARED.');
    $check(
        $stockOf($materialIds['MAIN']) === '94.000' && $movementCount($pickupId) === 1,
        'PREPARED deducts item quantity multiplied by its consumable ACTUAL recipe exactly once.'
    );
    $priceLock = $databaseConnection->prepare('SELECT price_locked_at FROM orders WHERE id = :id');
    $priceLock->execute(['id' => $pickupId]);
    $check($priceLock->fetchColumn() !== null, 'PREPARED permanently records the price-lock timestamp.');
    $wrongBranch = $orderStatusService->transition($pickupId, $administratorId, 'IN_DELIVERY', null);
    $check(!$wrongBranch['success'] && $statusOf($pickupId) === 'PREPARED', 'Pickup orders cannot enter the delivery branch.');
    $check($orderStatusService->transition($pickupId, $administratorId, 'WAITING_FOR_PICKUP', null)['success'], 'Prepared pickup orders may become WAITING_FOR_PICKUP.');
    $check($orderStatusService->transition($pickupId, $administratorId, 'PICKED_UP', null)['success'], 'WAITING_FOR_PICKUP may advance to PICKED_UP.');
    $check(
        !$orderStatusService->transition($pickupId, $administratorId, 'WAITING_FOR_PICKUP', null)['success'],
        'Completed pickup orders reject reversed transitions.'
    );

    $deliveryId = $addOrder('DELIVERY', 'PREPARED', 'DELIVERY', '59.000');
    $wrongPickupBranch = $orderStatusService->transition($deliveryId, $administratorId, 'WAITING_FOR_PICKUP', null);
    $check(!$wrongPickupBranch['success'], 'Delivery orders cannot enter the pickup branch.');
    $check($orderStatusService->transition($deliveryId, $administratorId, 'IN_DELIVERY', null)['success'], 'Prepared delivery orders may become IN_DELIVERY.');
    $check($orderStatusService->transition($deliveryId, $administratorId, 'DELIVERED', null)['success'], 'IN_DELIVERY may advance to DELIVERED.');

    $illegalId = $addOrder('ILLEGAL');
    $check(
        !$orderStatusService->transition($illegalId, $administratorId, 'PREPARING', null)['success']
        && !$orderStatusService->transition($illegalId, $administratorId, 'NOT_A_STATUS', null)['success']
        && $statusOf($illegalId) === 'REQUESTED'
        && $historyCount($illegalId) === 0,
        'Skipped and unknown transitions are rejected without changing the order or audit trail.'
    );

    $reversedId = $addOrder('REVERSED', 'PREPARING');
    $check(
        !$orderStatusService->transition($reversedId, $administratorId, 'CONFIRMED', null)['success'],
        'Backward transitions are rejected server-side.'
    );

    $paymentId = $addOrder('PAYMENT', 'REQUESTED', 'PICKUP', '100.000', '40.000');
    $payment = $databaseConnection->prepare(
        'INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status,
             amount, currency, verified_at)
         VALUES (:order_id, \'CASH_ON_DELIVERY\', :type, :status, :amount, \'TND\', :verified_at)'
    );
    $payment->execute([
        'order_id' => $paymentId,
        'type' => 'DEPOSIT',
        'status' => 'VERIFIED',
        'amount' => '39.999',
        'verified_at' => '2026-08-28 10:00:00',
    ]);
    $payment->execute([
        'order_id' => $paymentId,
        'type' => 'BALANCE',
        'status' => 'PENDING_VERIFICATION',
        'amount' => '10.000',
        'verified_at' => null,
    ]);
    $blockedPayment = $orderStatusService->transition($paymentId, $administratorId, 'CONFIRMED', null);
    $check(
        !$blockedPayment['success'] && $statusOf($paymentId) === 'REQUESTED',
        'Pending payments do not satisfy the authoritative required-deposit rule.'
    );
    $paymentDetailForUi = $orderController->show(['id' => (string) $paymentId]);
    $blockedSummary = $orderStatusController->confirmation($paymentDetailForUi['order'], 'CONFIRMED');
    $check(
        !$blockedSummary['allowed']
        && str_contains((string) $blockedSummary['reason'], 'required deposit'),
        'The confirmation model explains the payment block without relying on color.'
    );
    $orderDetail = $paymentDetailForUi;
    $transitionConfirmation = $blockedSummary;
    $transitionForm = [
        'errors' => ['transition' => 'The required deposit must be fully verified before confirmation.'],
        'values' => ['note' => '<script>unsafe note</script>'],
        'success' => null,
    ];
    $backUrl = BASE_URL . 'admin/orders/detail.php?id=' . $paymentId;
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-order-status.php';
    $blockedHtml = (string) ob_get_clean();
    $check(
        str_contains($blockedHtml, 'Transition blocked')
        && str_contains($blockedHtml, 'disabled')
        && str_contains($blockedHtml, '&lt;script&gt;unsafe note&lt;/script&gt;')
        && !str_contains($blockedHtml, '<script>unsafe note</script>'),
        'Blocked confirmation UI disables submission, states the reason, and escapes retained notes.'
    );
    $illegalConfirmation = $orderStatusController->confirmation($paymentDetailForUi['order'], 'PREPARING');
    $check(!$illegalConfirmation['validProposal'], 'Crafted confirmation targets are rejected before form display.');
    $payment->execute([
        'order_id' => $paymentId,
        'type' => 'DEPOSIT',
        'status' => 'VERIFIED',
        'amount' => '0.001',
        'verified_at' => '2026-08-28 10:01:00',
    ]);
    $check(
        $orderStatusService->transition($paymentId, $administratorId, 'CONFIRMED', null)['success'],
        'Confirmation succeeds once verified payments exactly meet the required deposit.'
    );

    $setId = $addOrder('SET', 'PREPARING');
    $addItem($setId, $productIds['SET'], 1, 'Set snapshot');
    $check($orderStatusService->transition($setId, $administratorId, 'PREPARED', null)['success'], 'Set order may advance to PREPARED.');
    $check(
        $stockOf($materialIds['MAIN']) === '89.000' && $movementCount($setId) === 1,
        'Set deductions aggregate the set recipe and component recipes into one material movement.'
    );

    $rollbackId = $addOrder('ROLLBACK', 'PREPARING');
    $addItem($rollbackId, $productIds['FAILING'], 1, 'Insufficient recipe snapshot');
    $stockBeforeRollback = $stockOf($materialIds['MAIN']);
    $failedPreparation = $orderStatusService->transition($rollbackId, $administratorId, 'PREPARED', null);
    $check(
        !$failedPreparation['success']
        && $statusOf($rollbackId) === 'PREPARING'
        && $historyCount($rollbackId) === 0
        && $movementCount($rollbackId) === 0
        && $stockOf($materialIds['MAIN']) === $stockBeforeRollback,
        'Insufficient stock rolls back every material, status, and history change atomically.'
    );

    $stockBeforeDuplicate = $stockOf($materialIds['MAIN']);
    try {
        $orderMaterialStockRepository->recordMovement(
            $materialIds['MAIN'],
            'ORDER_DEDUCTION',
            '-1.000',
            $pickupId,
            $administratorId,
            'Duplicate protection check.'
        );
        throw new RuntimeException('A duplicate stock movement was unexpectedly accepted.');
    } catch (PDOException) {
        // The unique order/material/type key is the database idempotency backstop.
    }
    $check(
        $stockOf($materialIds['MAIN']) === $stockBeforeDuplicate && $movementCount($pickupId) === 1,
        'Duplicate stock movement attempts roll back their balance update and preserve one ledger row.'
    );

    try {
        $orderStatusRepository->updateStatus($paymentId, 'REQUESTED', 'PREPARING');
        throw new RuntimeException('A stale status write was unexpectedly accepted.');
    } catch (DomainException) {
        // Expected optimistic current-state guard.
    }
    $check($statusOf($paymentId) === 'CONFIRMED', 'A stale concurrent-state expectation cannot overwrite the current status.');

    $historyAudit = $databaseConnection->prepare(
        'SELECT previous_status, new_status, changed_by_administrator_id, change_note
         FROM order_status_history
         WHERE order_id = :id
         ORDER BY id ASC LIMIT 1'
    );
    $historyAudit->execute(['id' => $pickupId]);
    $audit = $historyAudit->fetch();
    $check(
        $audit['previous_status'] === 'REQUESTED'
        && $audit['new_status'] === 'CONFIRMED'
        && (int) $audit['changed_by_administrator_id'] === $administratorId
        && $audit['change_note'] === 'Customer confirmation recorded.',
        'Immutable history records previous/new status, administrator identity, and the permitted note.'
    );

    $csrfId = $addOrder('CSRF');
    $invalidCsrf = $orderStatusController->process([
        'csrf_token' => str_repeat('0', 64),
        'id' => (string) $csrfId,
        'new_status' => 'CONFIRMED',
        'note' => '',
    ], $administratorId);
    $check(
        !$invalidCsrf['success'] && $statusOf($csrfId) === 'REQUESTED',
        'Invalid CSRF prevents a transition before business logic runs.'
    );
    $validCsrf = $orderStatusController->process([
        'csrf_token' => $csrf->token(),
        'id' => (string) $csrfId,
        'new_status' => 'CONFIRMED',
        'note' => ['invalid'],
    ], $administratorId);
    $check(
        !$validCsrf['success'] && isset($validCsrf['errors']['note']) && $statusOf($csrfId) === 'REQUESTED',
        'Non-string administrator notes are rejected by strict request validation.'
    );
    $validTransition = $orderStatusController->process([
        'csrf_token' => $csrf->token(),
        'id' => (string) $csrfId,
        'new_status' => 'CONFIRMED',
        'note' => 'Controller validation passed.',
    ], $administratorId);
    $check($validTransition['success'] && $statusOf($csrfId) === 'CONFIRMED', 'A valid CSRF-protected POST reaches the transition service.');
    $longNoteId = $addOrder('LONGNOTE');
    $longNote = $orderStatusService->transition($longNoteId, $administratorId, 'CONFIRMED', str_repeat('n', 501));
    $check(
        !$longNote['success'] && isset($longNote['errors']['note']) && $statusOf($longNoteId) === 'REQUESTED',
        'Administrator audit notes are limited to the schema-safe maximum length.'
    );

    $selectId = $addOrder('SELECT');
    $addItem($selectId, $productIds['SIMPLE'], 2, 'Dropdown candle');
    $beforeSelectionStock = (float) $stockOf($materialIds['MAIN']);
    $selectionPost = [
        'id' => (string) $selectId, 'new_status' => 'PREPARED', 'expected_status' => 'REQUESTED',
    ];
    $badSelection = $orderStatusController->process($selectionPost + ['csrf_token' => 'invalid'], $administratorId, true);
    $check(!$badSelection['success'] && $statusOf($selectId) === 'REQUESTED', 'Direct dropdown changes require a valid CSRF token.');
    $selection = $orderStatusController->process($selectionPost + ['csrf_token' => $csrf->token()], $administratorId, true);
    $check($selection['success'] && $statusOf($selectId) === 'PREPARED' && $historyCount($selectId) === 3,
        'Selecting a later status applies required steps and records history without a note or confirmation page.');
    $check((float) $stockOf($materialIds['MAIN']) === $beforeSelectionStock - 4 && $movementCount($selectId) === 1,
        'A direct selection deducts preparation materials exactly once.');
    $staleSelection = $orderStatusService->selectStatus($selectId, $administratorId, 'PICKED_UP', 'REQUESTED');
    $check(!$staleSelection['success'] && $statusOf($selectId) === 'PREPARED', 'A stale dropdown cannot silently update a newer order status.');
    $sameSelection = $orderStatusService->selectStatus($selectId, $administratorId, 'PREPARED', 'PREPARED');
    $check($sameSelection['success'] && $historyCount($selectId) === 3 && $movementCount($selectId) === 1,
        'Choosing the current status has no duplicate history or stock effects.');
    $wrongSelection = $orderStatusService->selectStatus($selectId, $administratorId, 'DELIVERED', 'PREPARED');
    $check(!$wrongSelection['success'] && $historyCount($selectId) === 3, 'The dropdown rejects the wrong fulfilment branch.');

    $blockedSelectId = $addOrder('SELDEPOSIT', 'REQUESTED', 'PICKUP', '100.000', '40.000');
    $blockedSelection = $orderStatusService->selectStatus($blockedSelectId, $administratorId, 'PREPARED', 'REQUESTED');
    $check(!$blockedSelection['success'] && $statusOf($blockedSelectId) === 'REQUESTED' && $historyCount($blockedSelectId) === 0,
        'Selecting a later status cannot bypass the verified-deposit requirement.');
    $failedSelectId = $addOrder('SELSTOCK');
    $addItem($failedSelectId, $productIds['FAILING'], 1, 'Stock failure');
    $stockBeforeFailure = $stockOf($materialIds['MAIN']);
    $failedSelection = $orderStatusService->selectStatus($failedSelectId, $administratorId, 'PICKED_UP', 'REQUESTED');
    $check(!$failedSelection['success'] && $statusOf($failedSelectId) === 'REQUESTED' && $historyCount($failedSelectId) === 0
        && $movementCount($failedSelectId) === 0 && $stockOf($materialIds['MAIN']) === $stockBeforeFailure,
        'A failed direct selection rolls back every intermediate status, audit row, and stock effect.');

    $orderDetail = $orderController->show(['id' => (string) $selectId, 'return' => 'q=SELECT']);
    $orderStatusOptions = $orderStatusController->selectionOptions($orderDetail['order']);
    $orderTransition = $orderStatusController->summary($orderDetail['order']);
    $check(array_column($orderStatusOptions, 'status') === \App\Services\OrderService::STATUSES,
        'The status list always includes all eight statuses, including previous ones.');
    $transitionNotice = ['errors' => [], 'values' => [], 'success' => null];
    ob_start();
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-order-detail.php';
    $dropdownHtml = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML($dropdownHtml);
    $xpath = new DOMXPath($dom);
    $check($xpath->query('//div[@class="admin-order-header__title"]/form//select[@name="new_status"]')->length === 1
        && $xpath->query('//div[@class="admin-order-header__title"]/form/following-sibling::a[contains(@href,"edit.php")]')->length === 1
        && !str_contains($dropdownHtml, 'next-status-title'), 'The header dropdown sits before Edit order and replaces the separate transition panel.');
    $check($xpath->query('//form[@data-order-status-form]//textarea')->length === 0
        && $xpath->query('//form[@data-order-status-form]//input[@name="return"]')->item(0)->getAttribute('value') !== '',
        'The dropdown needs no note and retains the order-list return filters.');

    $orderList = $orderController->index(['q' => $orderDetail['order']['order_number']]);
    foreach ($orderList['orders'] as &$listedOrder) {
        $listedOrder['statusOptions'] = $orderStatusController->selectionOptions($listedOrder);
        $listedOrder['statusReason'] = $orderStatusController->summary($listedOrder)['reason'];
    }
    unset($listedOrder);
    ob_start();
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-orders.php';
    $listHtml = (string) ob_get_clean();
    $listDom = new DOMDocument();
    @$listDom->loadHTML($listHtml);
    $listXPath = new DOMXPath($listDom);
    $check($listXPath->query('//table//select[@name="new_status"]/option[@selected]')->item(0)->getAttribute('value') === 'PREPARED'
        && $listXPath->query('//table//select[@name="new_status"]/option[@value="SCHEDULED"]')->length === 0,
        'The list dropdown displays the saved order status, independently of its scheduled timing.');
    $check($listXPath->query('//td[form[@data-order-status-form]]/following-sibling::td[1]/a[contains(@href,"edit.php")]')->length === 1
        && $listXPath->query('//form[@data-order-status-form]/input[@name="return_to" and @value="list"]')->length === 1,
        'The list has a status dropdown immediately before Edit, with a return destination for the list.');
    $check($listXPath->query('//select[@name="new_status" and @aria-label="Order status"]')->length === 1
        && str_contains($listHtml, 'assets/js/admin-order-status.js'),
        'The native dropdown has an accessible name and loads the save behavior.');
    $check($listXPath->query('//button[@data-status-save and @aria-label="Save order status"]/*[@data-lucide="save-check" and @class="admin-order-status-select__save-icon"]')->length === 1,
        'The save icon has a styling class and its button has an accessible name.');
    $blockedList = $orderController->index(['q' => 'M03-SELDEPOSIT-' . $suffix]);
    $blockedListOptions = $orderStatusController->selectionOptions($blockedList['orders'][0]);
    $check(count($blockedListOptions) === 8 && count(array_filter($blockedListOptions, static fn (array $option): bool => !$option['disabled'])) === 6,
        'Orders awaiting a deposit still show the complete status list; payment checks run when saving.');

    $originalPriceLock = $orderController->show(['id' => (string) $selectId])['order']['price_locked_at'];
    $stockBeforeCorrection = $stockOf($materialIds['MAIN']);
    $check($orderStatusService->selectStatus($selectId, $administratorId, 'PICKED_UP', 'PREPARED')['success'],
        'A pickup order can be completed using the status selector.');
    $completedOrder = $orderController->show(['id' => (string) $selectId])['order'];
    $completedOptions = $orderStatusController->selectionOptions($completedOrder);
    $check(array_column($completedOptions, 'status') === \App\Services\OrderService::STATUSES
        && !$completedOptions[0]['disabled'], 'Completed orders retain every status and allow selecting Requested again.');
    $correction = $orderStatusController->process([
        'id' => (string) $selectId, 'new_status' => 'REQUESTED', 'expected_status' => 'PICKED_UP', 'csrf_token' => $csrf->token(),
    ], $administratorId, true);
    $check($correction['success'] && $statusOf($selectId) === 'REQUESTED',
        'The protected save handler lets an administrator correct a completed order to an earlier status.');
    $correctedOrder = $orderController->show(['id' => (string) $selectId])['order'];
    $check($correctedOrder['price_locked_at'] === $originalPriceLock
        && $stockOf($materialIds['MAIN']) === $stockBeforeCorrection && $movementCount($selectId) === 1,
        'A status correction preserves recorded material use and the original locked price.');
    $lastHistory = $databaseConnection->prepare('SELECT previous_status, new_status, changed_by_administrator_id FROM order_status_history WHERE order_id = :id ORDER BY id DESC LIMIT 1');
    $lastHistory->execute(['id' => $selectId]);
    $correctionAudit = $lastHistory->fetch();
    $check($correctionAudit['previous_status'] === 'PICKED_UP' && $correctionAudit['new_status'] === 'REQUESTED'
        && (int) $correctionAudit['changed_by_administrator_id'] === $administratorId,
        'Backward corrections keep an audit entry identifying the administrator and both statuses.');
    $check($orderStatusService->selectStatus($selectId, $administratorId, 'PREPARED', 'REQUESTED')['success']
        && $stockOf($materialIds['MAIN']) === $stockBeforeCorrection && $movementCount($selectId) === 1,
        'Re-advancing a corrected order does not deduct its materials again.');
    $check($orderStatusService->selectStatus($selectId, $administratorId, 'CONFIRMED', 'PREPARED')['success']
        && $orderStatusService->selectStatus($selectId, $administratorId, 'PREPARED', 'CONFIRMED')['success']
        && $movementCount($selectId) === 1,
        'Repeated corrections and saves continue to preserve a single material deduction.');
    $check($orderStatusService->selectStatus($deliveryId, $administratorId, 'IN_DELIVERY', 'DELIVERED')['success']
        && $statusOf($deliveryId) === 'IN_DELIVERY', 'A delivered order can be corrected back to In delivery.');

    $emptyRecipeId = $addOrder('RECIPEEDIT');
    $check($orderStatusService->selectStatus($emptyRecipeId, $administratorId, 'PREPARED', 'REQUESTED')['success'],
        'Preparation without tracked materials still records a price-lock marker.');
    $addItem($emptyRecipeId, $productIds['SIMPLE'], 1, 'Later recipe reference');
    $check($orderStatusService->selectStatus($emptyRecipeId, $administratorId, 'PREPARING', 'PREPARED')['success']
        && $orderStatusService->selectStatus($emptyRecipeId, $administratorId, 'PREPARED', 'PREPARING')['success']
        && $movementCount($emptyRecipeId) === 0 && $stockOf($materialIds['MAIN']) === $stockBeforeCorrection,
        'Re-advancing an already prepared order does not apply recipes added after original preparation.');

    $trashOrder = $orderController->show(['id' => (string) $selectId])['order'];
    $stockBeforeTrash = $stockOf($materialIds['MAIN']);
    $historyBeforeTrash = $historyCount($selectId);
    $check($orderTrashController->process(['id' => (string) $selectId, 'version' => \App\Services\OrderEditService::version($trashOrder), 'csrf_token' => $csrf->token()], $administratorId)['success']
        && $stockOf($materialIds['MAIN']) === $stockBeforeTrash && $movementCount($selectId) === 1 && $historyCount($selectId) === $historyBeforeTrash,
        'Deleting a prepared order preserves its material ledger and status history.');
    $check(!$orderStatusService->selectStatus($selectId, $administratorId, 'REQUESTED', 'PREPARED')['success'],
        'A stale status form cannot modify an order in Trash.');
    $check($orderTrashController->process(['id' => (string) $selectId, 'csrf_token' => $csrf->token()], $administratorId, true)['success']
        && $statusOf($selectId) === 'PREPARED' && $stockOf($materialIds['MAIN']) === $stockBeforeTrash,
        'Restoring a prepared order preserves its status and never consumes materials again.');

    $queueTrashId = $addOrder('TRASHQUEUE', 'CONFIRMED');
    $queueRepository = new \App\Repositories\ProductionRepository($databaseConnection);
    $queueFilter = ['search' => 'M03-TRASHQUEUE-' . $suffix];
    $queueTrashOrder = $orderController->show(['id' => (string) $queueTrashId])['order'];
    $check($queueRepository->countForQueue($queueFilter) === 1, 'An active confirmed order appears in production.');
    $orderTrashController->process(['id' => (string) $queueTrashId, 'version' => \App\Services\OrderEditService::version($queueTrashOrder), 'csrf_token' => $csrf->token()], $administratorId);
    $check($queueRepository->countForQueue($queueFilter) === 0, 'Deleted orders are excluded from the production queue.');
    $orderTrashController->process(['id' => (string) $queueTrashId, 'csrf_token' => $csrf->token()], $administratorId, true);
    $check($queueRepository->countForQueue($queueFilter) === 1, 'Restored orders return to the production queue.');

    $activeAdministrator = $administratorAuthorization->activeAdministrator($administratorId);
    $check(is_array($activeAdministrator), 'An active administrator passes the server-side authorization check.');
    $suspend = $databaseConnection->prepare("UPDATE administrators_accounts SET account_status = 'SUSPENDED' WHERE id = :id");
    $suspend->execute(['id' => $administratorId]);
    $check(
        $administratorAuthorization->activeAdministrator($administratorId) === null,
        'An inactive administrator is rejected before protected route work can proceed.'
    );
} finally {
    if ($orderIds !== []) {
        $ids = array_values($orderIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach (['material_stock_movements', 'order_status_history', 'payments', 'order_items'] as $table) {
            $delete = $databaseConnection->prepare("DELETE FROM {$table} WHERE order_id IN ({$placeholders})");
            $delete->execute($ids);
        }
        $deleteOrders = $databaseConnection->prepare("DELETE FROM orders WHERE id IN ({$placeholders})");
        $deleteOrders->execute($ids);
    }
    if ($productIds !== []) {
        $ids = array_values($productIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM set_components WHERE set_product_id IN ({$placeholders}) OR component_product_id IN ({$placeholders})")
            ->execute(array_merge($ids, $ids));
        $databaseConnection->prepare("DELETE FROM product_materials WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM products WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($materialIds !== []) {
        $ids = array_values($materialIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM materials WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($administratorId !== null) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Order-status workflow verification completed without leaving test rows.' . PHP_EOL;
