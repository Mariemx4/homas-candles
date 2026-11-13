<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\PaymentController;
use App\Services\PaymentEvidenceStorage;
use App\Services\PaymentService;

$databaseConnection = database();
$shared = \App\Container\SharedServices::for($databaseConnection);
$paymentService = new PaymentService(
    $shared->paymentRepository(),
    $shared->transactionManager(),
    new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH),
    require __DIR__ . '/../config/payments.php'
);
$paymentController = new PaymentController($paymentService, $csrf);
$paymentCustomerId = $customerSession->customerId();
if ($paymentCustomerId !== null) {
    $accounts = new \App\Repositories\CustomerAccountReadRepository($databaseConnection);
    if ($accounts->activeAccount($paymentCustomerId) === null) {
        $paymentCustomerId = null;
    }
}
// Same guest capability used by order-confirmation.php; never supplied by a URL/form.
$paymentSessionOrderIds = array_values(array_filter(
    is_array($_SESSION['checkout_placed_orders'] ?? null) ? $_SESSION['checkout_placed_orders'] : [],
    static fn (mixed $id): bool => is_int($id) && $id > 0
));
