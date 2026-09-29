<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Password Reset; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Auth\AuthFormFlash;
use App\Auth\PasswordResetController;
use App\Auth\PasswordResetService;
use App\Database\TransactionManager;
use App\Mail\PhpMailPasswordResetMailer;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\PasswordResetRepository;

$databaseConnection = database();
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$transactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create CustomerAccountRepository to give the service a dedicated database-access object.
$customerAccounts = new CustomerAccountRepository($databaseConnection, $transactions);
// DEPENDENCY PURPOSE: Create PasswordResetRepository to give the service a dedicated database-access object.
$passwordResets = new PasswordResetRepository($databaseConnection, $transactions);
$mailConfig = require __DIR__ . '/../config/mail.php';
// DEPENDENCY PURPOSE: Create PhpMailPasswordResetMailer to supply one reusable dependency to the objects constructed later.
$resetMailer = new PhpMailPasswordResetMailer($mailConfig['from_address'], $mailConfig['from_name']);
// DEPENDENCY PURPOSE: Create AuthFormFlash to supply one reusable dependency to the objects constructed later.
$authFlash = new AuthFormFlash();
// DEPENDENCY PURPOSE: Create PasswordResetService to apply this feature’s business and validation rules.
$passwordResetService = new PasswordResetService(
    $customerAccounts,
    $passwordResets,
    $transactions,
    $resetMailer
);
// DEPENDENCY PURPOSE: Create PasswordResetController to provide the public route with its request-facing coordinator.
$passwordResetController = new PasswordResetController(
    $passwordResetService,
    $csrf,
    $authFlash,
    BASE_URL
);
