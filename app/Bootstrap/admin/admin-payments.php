<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Payments; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\PaymentController;
use App\Database\TransactionManager;
use App\Repositories\PaymentRepository;
use App\Services\PaymentEvidenceStorage;
use App\Services\PaymentService;

// DEPENDENCY PURPOSE: Create PaymentRepository to give the service a dedicated database-access object.
$paymentRepository = new PaymentRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$paymentTransactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create PaymentEvidenceStorage to keep file validation and filesystem paths out of controllers.
// PAYMENT_EVIDENCE_PATH is private storage outside the public web root
// (app/config/paths.php), so evidence files are never reachable by URL.
$paymentEvidenceStorage = new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH);
// DEPENDENCY PURPOSE: Create PaymentService to apply this feature’s business and validation rules.
$paymentService = new PaymentService($paymentRepository, $paymentTransactions, $paymentEvidenceStorage);
// DEPENDENCY PURPOSE: Create PaymentController to provide the public route with its request-facing coordinator.
$paymentController = new PaymentController($paymentService, $csrf);
