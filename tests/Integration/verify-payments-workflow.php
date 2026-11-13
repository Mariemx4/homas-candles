<?php

declare(strict_types=1);



require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-payments.php';
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
[$databaseConnection, $paymentTestSchema] = create_throwaway_auth_schema(true);


$results = [];
$check = TestCheck::collecting($results);

$suffix = substr((string) hrtime(true), -8);
$administratorId = null;
$orderIds = [];

$paymentRow = static function (int $paymentId) use ($databaseConnection): array {
    $statement = $databaseConnection->prepare('SELECT * FROM payments WHERE id = :id');
    $statement->execute(['id' => $paymentId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException("Payment {$paymentId} could not be reloaded.");
    }

    return $row;
};

try {
    $administrator = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, \'ACTIVE\')'
    );
    $administrator->execute([
        'first_name' => 'Payments',
        'last_name' => 'Verifier',
        'email' => "payments-{$suffix}@example.test",
        'phone' => '7' . substr($suffix, -7),
        'password_hash' => password_hash('PaymentsVerifier123!', PASSWORD_DEFAULT),
    ]);
    $administratorId = (int) $databaseConnection->lastInsertId();

    $insertOrder = $databaseConnection->prepare(
        'INSERT INTO orders
            (order_number, language_code, order_source, order_status, delivery_method,
             required_date, customer_name_snapshot, customer_phone_snapshot,
             subtotal, delivery_fee, total_amount, required_deposit_amount)
         VALUES
            (:number, \'en\', \'CART\', \'REQUESTED\', \'PICKUP\', \'2026-09-15\',
             :customer, \'90000006\', :subtotal, 0.000, :total, :deposit)'
    );
    $addOrder = static function (string $label, string $total, string $deposit) use (
        $insertOrder,
        $databaseConnection,
        $suffix,
        &$orderIds
    ): int {
        $insertOrder->execute([
            'number' => "M05-{$label}-{$suffix}",
            'customer' => "Payments {$label}",
            'subtotal' => $total,
            'total' => $total,
            'deposit' => $deposit,
        ]);
        $id = (int) $databaseConnection->lastInsertId();
        $orderIds[$label] = $id;

        return $id;
    };

    $orderId = $addOrder('MAIN', '100.000', '40.000');

    // --- Order summary starts empty. ---
    $emptySummary = $paymentService->orderSummary($orderId);
    $check(
        $emptySummary['order']['verified_amount'] === '0.000'
        && $emptySummary['order']['pending_amount'] === '0.000'
        && $emptySummary['order']['remaining_balance'] === '100.000',
        'A new order starts with zero verified/pending payments and the full remaining balance.'
    );

    // --- Recording a CASH_ON_DELIVERY payment is immediately verified with an audit identity. ---
    $codResult = $paymentService->record(
        ['order_id' => (string) $orderId, 'payment_method' => 'CASH_ON_DELIVERY', 'payment_type' => 'DEPOSIT', 'amount' => '40.000'],
        null,
        $administratorId,
        new DateTimeImmutable()
    );
    $check($codResult['success'], 'A cash-on-delivery payment can be recorded.');
    $codRow = $paymentRow($codResult['paymentId']);
    $check(
        $codRow['payment_status'] === 'VERIFIED'
        && (int) $codRow['verified_by_administrator_id'] === $administratorId
        && $codRow['verified_at'] !== null,
        'Cash-on-delivery payments are recorded already verified with the recording administrator identity.'
    );

    // --- Recording a D17 payment stays pending until reviewed. ---
    $d17Result = $paymentService->record(
        [
            'order_id' => (string) $orderId,
            'payment_method' => 'D17',
            'payment_type' => 'BALANCE',
            'amount' => '30.000',
            'd17_reference' => "D17-REF-{$suffix}",
        ],
        null,
        $administratorId,
        new DateTimeImmutable()
    );
    $check($d17Result['success'], 'A D17 payment can be recorded.');
    $d17Row = $paymentRow($d17Result['paymentId']);
    $check(
        $d17Row['payment_status'] === 'PENDING_VERIFICATION'
        && $d17Row['verified_by_administrator_id'] === null
        && $d17Row['verified_at'] === null,
        'D17 payments are recorded pending, with no reviewer identity until an administrator decides.'
    );

    // --- Overpayment is rejected server-side using authoritative sums (verified 40 + pending 30, remaining 30). ---
    $overpay = $paymentService->record(
        ['order_id' => (string) $orderId, 'payment_method' => 'D17', 'payment_type' => 'BALANCE', 'amount' => '30.001'],
        null,
        $administratorId,
        new DateTimeImmutable()
    );
    $check(
        !$overpay['success'] && isset($overpay['errors']['form']),
        'An amount that would exceed the order total once pending payments are verified is rejected.'
    );

    // --- Zero, negative, and malformed amounts are rejected. ---
    foreach (['0.000', '-5.000', 'abc', '1.2345'] as $badAmount) {
        $bad = $paymentService->record(
            ['order_id' => (string) $orderId, 'payment_method' => 'D17', 'payment_type' => 'BALANCE', 'amount' => $badAmount],
            null,
            $administratorId,
            new DateTimeImmutable()
        );
        $check(!$bad['success'] && isset($bad['errors']['amount']), "The amount '{$badAmount}' is rejected server-side.");
    }

    // --- Duplicate D17 references are rejected. ---
    $duplicateReference = $paymentService->record(
        [
            'order_id' => (string) $orderId,
            'payment_method' => 'D17',
            'payment_type' => 'BALANCE',
            'amount' => '1.000',
            'd17_reference' => "D17-REF-{$suffix}",
        ],
        null,
        $administratorId,
        new DateTimeImmutable()
    );
    $check(
        !$duplicateReference['success'] && isset($duplicateReference['errors']['d17_reference']),
        'A D17 reference already used on another payment is rejected.'
    );

    // --- Verifying the pending D17 payment records the reviewer and timestamp. ---
    $verify = $paymentService->verify($d17Result['paymentId'], $administratorId, new DateTimeImmutable());
    $check($verify['success'] && $verify['orderId'] === $orderId, 'A pending D17 payment can be verified.');
    $verifiedRow = $paymentRow($d17Result['paymentId']);
    $check(
        $verifiedRow['payment_status'] === 'VERIFIED'
        && (int) $verifiedRow['verified_by_administrator_id'] === $administratorId
        && $verifiedRow['verified_at'] !== null,
        'Verification permanently records the reviewing administrator and timestamp.'
    );

    // --- A payment cannot be reviewed twice. ---
    $doubleVerify = $paymentService->verify($d17Result['paymentId'], $administratorId, new DateTimeImmutable());
    $check(!$doubleVerify['success'], 'An already-verified payment cannot be verified again.');
    $doubleReject = $paymentService->reject($d17Result['paymentId'], $administratorId, 'Trying to reject after verification.');
    $check(!$doubleReject['success'], 'An already-verified payment cannot be rejected afterward.');

    // --- Only a pending D17 payment can be reviewed; a CASH_ON_DELIVERY payment cannot. ---
    $codReview = $paymentService->verify($codResult['paymentId'], $administratorId, new DateTimeImmutable());
    $check(!$codReview['success'], 'A cash-on-delivery payment, never pending, cannot be verified through the review workflow.');

    // --- Rejection requires a non-empty reason and records the reviewer without a verified timestamp. ---
    $rejectableOrder = $addOrder('REJECT', '50.000', '0.000');
    $rejectable = $paymentService->record(
        ['order_id' => (string) $rejectableOrder, 'payment_method' => 'D17', 'payment_type' => 'FULL_PAYMENT', 'amount' => '50.000'],
        null,
        $administratorId,
        new DateTimeImmutable()
    );
    $check($rejectable['success'], 'A second order can also record a pending D17 payment.');
    $emptyReasonReject = $paymentService->reject($rejectable['paymentId'], $administratorId, '   ');
    $check(!$emptyReasonReject['success'] && isset($emptyReasonReject['errors']['reason']), 'Rejection requires a non-empty reason.');
    $longReasonReject = $paymentService->reject($rejectable['paymentId'], $administratorId, str_repeat('n', 501));
    $check(!$longReasonReject['success'], 'Rejection reasons are limited to the schema-safe maximum length.');
    $reject = $paymentService->reject($rejectable['paymentId'], $administratorId, 'No matching D17 transfer was found.');
    $check($reject['success'], 'A pending D17 payment can be rejected with a valid reason.');
    $rejectedRow = $paymentRow($rejectable['paymentId']);
    $check(
        $rejectedRow['payment_status'] === 'REJECTED'
        && $rejectedRow['rejection_reason'] === 'No matching D17 transfer was found.'
        && (int) $rejectedRow['verified_by_administrator_id'] === $administratorId
        && $rejectedRow['verified_at'] === null,
        'Rejection records the reason and reviewing administrator without setting a verified timestamp.'
    );

    // --- A rejected payment does not count toward the remaining balance. ---
    $rejectSummary = $paymentService->orderSummary($rejectableOrder);
    $check(
        $rejectSummary['order']['rejected_amount'] === '50.000'
        && $rejectSummary['order']['verified_amount'] === '0.000'
        && $rejectSummary['order']['remaining_balance'] === '50.000',
        'A rejected payment is tracked separately and does not reduce the remaining balance.'
    );

    // --- The final order summary reflects all recorded decisions. ---
    $summary = $paymentService->orderSummary($orderId);
    $check(
        $summary['order']['verified_amount'] === '70.000'
        && $summary['order']['pending_amount'] === '0.000'
        && $summary['order']['remaining_balance'] === '30.000'
        && count($summary['payments']) === 2,
        'The order payment summary reflects authoritative verified, pending, and remaining totals.'
    );

    // --- Evidence access resolves to nothing when no evidence was uploaded. ---
    $missingEvidence = $paymentController->resolveEvidence(['id' => (string) $codResult['paymentId']]);
    $check(!$missingEvidence['allowed'], 'A payment recorded without evidence exposes no evidence file.');
    $unknownEvidence = $paymentController->resolveEvidence(['id' => '9223372036854775807']);
    $check(!$unknownEvidence['allowed'], 'Evidence lookup for a nonexistent payment is safely denied.');

    // --- Evidence storage rejects fabricated (non-HTTP) uploads and oversized/invalid files. ---
    $check(
        !$paymentEvidenceStorage->store(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'size' => 10])['success'],
        'A file not submitted through a genuine HTTP upload is rejected, even with a valid-looking path.'
    );
    $check(
        $paymentEvidenceStorage->store(['error' => UPLOAD_ERR_NO_FILE])['success'] === true
        && $paymentEvidenceStorage->store(['error' => UPLOAD_ERR_NO_FILE])['path'] === null,
        'An omitted (optional) evidence file is accepted as "no evidence" without error.'
    );
    $check(
        !$paymentEvidenceStorage->store(['error' => UPLOAD_ERR_INI_SIZE])['success'],
        'An upload error from PHP itself is surfaced as a rejection rather than silently ignored.'
    );

    // --- Evidence path resolution refuses traversal and only serves files inside the evidence directory. ---
    $check(
        $paymentEvidenceStorage->resolve('../../config/database.local.php') === null
        && $paymentEvidenceStorage->resolve('sub/evil.jpg') === null
        && $paymentEvidenceStorage->resolve('') === null
        && $paymentEvidenceStorage->resolve(null) === null,
        'Evidence path resolution refuses traversal, nested, empty, and null paths.'
    );
    $plantedName = bin2hex(random_bytes(10)) . '.jpg';
    $plantedDirectory = PAYMENT_EVIDENCE_PATH;
    file_put_contents($plantedDirectory . DIRECTORY_SEPARATOR . $plantedName, 'not a real jpeg, just proving the round trip');
    try {
        $resolvedPlanted = $paymentEvidenceStorage->resolve($plantedName);
        $check(
            is_string($resolvedPlanted) && str_ends_with($resolvedPlanted, $plantedName),
            'A legitimate flat filename inside the evidence directory resolves to its absolute path.'
        );
    } finally {
        @unlink($plantedDirectory . DIRECTORY_SEPARATOR . $plantedName);
    }

    // --- CSRF protects both mutation endpoints. ---
    $invalidCsrfRecord = $paymentController->processRecord(
        ['csrf_token' => str_repeat('0', 64), 'order_id' => (string) $orderId, 'payment_method' => 'CASH_ON_DELIVERY', 'payment_type' => 'DEPOSIT', 'amount' => '1.000'],
        null,
        $administratorId
    );
    $check(!$invalidCsrfRecord['success'], 'Invalid CSRF prevents a payment from being recorded.');
    $invalidCsrfDecision = $paymentController->processDecision(
        ['csrf_token' => str_repeat('0', 64), 'id' => (string) $rejectable['paymentId'], 'action' => 'verify'],
        $administratorId
    );
    $check(!$invalidCsrfDecision['success'], 'Invalid CSRF prevents a payment decision from being processed.');

    // --- A valid CSRF-protected controller round trip succeeds. ---
    $validCsrfOrder = $addOrder('CSRF', '20.000', '0.000');
    $databaseConnection->prepare("UPDATE orders SET order_status = 'PICKED_UP' WHERE id = :id")->execute(['id' => $validCsrfOrder]);
    $check($paymentService->orderSummary($validCsrfOrder)['order']['payment_status_label'] === 'Unpaid', 'A picked-up order remains unpaid until a receipt is explicitly recorded.');
    $unconfirmed = $paymentController->processRecord([
        'csrf_token' => $csrf->token(), 'order_id' => (string) $validCsrfOrder,
        'payment_method' => ' cash_on_delivery ', 'payment_type' => 'FULL_PAYMENT', 'amount' => '20.000',
    ], null, $administratorId);
    $check(!$unconfirmed['success'] && $paymentService->orderSummary($validCsrfOrder)['order']['verified_amount'] === '0.000', 'Cash receipt requires explicit confirmation even when method casing/whitespace is normalized.');
    $validCsrfRecord = $paymentController->processRecord(
        [
            'csrf_token' => $csrf->token(),
            'order_id' => (string) $validCsrfOrder,
            'payment_method' => 'CASH_ON_DELIVERY',
            'payment_type' => 'FULL_PAYMENT',
            'amount' => '20.000',
            'received_confirmation' => '1',
        ],
        null,
        $administratorId
    );
    $check($validCsrfRecord['success'], 'A valid CSRF-protected POST reaches the record service through the controller.');
    $check($paymentService->orderSummary($validCsrfOrder)['order']['payment_status_label'] === 'Paid', 'Explicit cash receipt marks the amount paid independently of fulfilment.');

    $replayOrder = $addOrder('REPLAY', '20.000', '0.000');
    $receipt = ['order_id' => (string) $replayOrder, 'payment_method' => 'CASH_ON_DELIVERY', 'payment_type' => 'BALANCE', 'amount' => '3.000', 'request_key' => hash('sha256', 'receipt-' . $suffix)];
    $firstReceipt = $paymentService->record($receipt, null, $administratorId, new DateTimeImmutable());
    $secondReceipt = $paymentService->record($receipt, null, $administratorId, new DateTimeImmutable());
    $check($firstReceipt['success'] && !$secondReceipt['success'] && $paymentService->orderSummary($replayOrder)['order']['verified_amount'] === '3.000', 'A repeated partial cash submission cannot create a second receipt.');

    $customerService = new \App\Services\PaymentService($paymentRepository, $paymentTransactions, $paymentEvidenceStorage, ['available' => true]);
    $customerOrder = $addOrder('CUSTOMER', '100.000', '40.000');
    $check($customerService->customerSummary($customerOrder, null, []) === null
        && $customerService->customerSummary($customerOrder, 999999, []) === null, 'Knowing an order ID or matching a contact does not grant customer payment access.');
    $guestDetail = $customerService->customerSummary($customerOrder, null, [$customerOrder]);
    $check($guestDetail['order']['next_d17_amount'] === '40.000', 'Checkout-session access receives the authoritative required deposit, not a browser amount.');
    $missingProof = $customerService->submitCustomerEvidence($customerOrder, null, null, [$customerOrder], hash('sha256', 'proof-' . $suffix));
    $check(!$missingProof['success'] && isset($missingProof['errors']['evidence']), 'Customer D17 submission requires proof.');
    // The configured production service may be enabled; check missing configuration in an explicit test instance.
    $noD17 = new \App\Services\PaymentService($paymentRepository, $paymentTransactions, $paymentEvidenceStorage);
    $check(!$noD17->submitCustomerEvidence($customerOrder, null, null, [$customerOrder], hash('sha256', 'disabled-' . $suffix))['success'], 'Customer submission fails safely when D17 recipient information is not configured.');
    $check($paymentRow($rejectable['paymentId'])['rejected_at'] !== null, 'Rejection retains its own timestamp without fabricating a verification time.');

    $databaseConnection->prepare("INSERT INTO customers_accounts (first_name, last_name, email, phone_number, password_hash, account_status) VALUES ('Payment', 'Owner', :email, :phone, :password, 'ACTIVE')")
        ->execute(['email' => 'owner-' . $suffix . '@example.test', 'phone' => '6' . substr($suffix, -7), 'password' => password_hash('TestReceipt123!', PASSWORD_DEFAULT)]);
    $ownerId = (int) $databaseConnection->lastInsertId();
    $databaseConnection->prepare('UPDATE orders SET customer_id = :customer WHERE id = :id')->execute(['customer' => $ownerId, 'id' => $customerOrder]);
    $check($customerService->customerSummary($customerOrder, $ownerId, []) !== null
        && $customerService->customerSummary($customerOrder, $ownerId + 100, []) === null, 'Customer-account access is scoped to the stored customer_id.');
    $databaseConnection->prepare('UPDATE orders SET deleted_at = statement_timestamp() WHERE id = :id')->execute(['id' => $customerOrder]);
    $check($customerService->customerSummary($customerOrder, $ownerId, [$customerOrder]) === null, 'Trashed orders cannot expose customer payment details or accept new proof.');

    // --- List filters and pagination reject unsupported input safely. ---
    $filteredList = $paymentService->list(['status' => 'VERIFIED', 'method' => 'CASH_ON_DELIVERY', 'q' => "M05-MAIN-{$suffix}"]);
    $check(
        $filteredList['total'] >= 1 && $filteredList['filters']['status'] === 'VERIFIED',
        'The payment queue applies status, method, and order-number search filters together.'
    );
    $invalidList = $paymentService->list(['status' => "PENDING' OR 1=1 --", 'date_from' => '2026-99-99', 'page' => '-3']);
    $check(
        $invalidList['filters']['status'] === null
        && $invalidList['filters']['date_from'] === null
        && $invalidList['page'] === 1
        && $invalidList['filtersAdjusted'] === true,
        'Unsupported status, date, and page values fall back through allow lists instead of reaching SQL.'
    );
} finally {
    drop_throwaway_auth_schema($databaseConnection, $paymentTestSchema);
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Payments workflow verification completed without leaving test rows.' . PHP_EOL;
