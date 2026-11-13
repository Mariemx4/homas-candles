<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\PaymentController;
use App\Services\PaymentEvidenceStorage;
use App\Services\PaymentService;

$paymentRepository = $shared->paymentRepository();
$paymentTransactions = $shared->transactionManager();
// Create PaymentEvidenceStorage to keep file validation and filesystem paths out of controllers.
// PAYMENT_EVIDENCE_PATH is private storage outside the public web root
// (app/config/paths.php), so evidence files are never reachable by URL.
$paymentEvidenceStorage = new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH);
$paymentService = new PaymentService($paymentRepository, $paymentTransactions, $paymentEvidenceStorage, require __DIR__ . '/../../config/payments.php');
$paymentController = new PaymentController($paymentService, $csrf);
