<?php

declare(strict_types=1);

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
$transactions = new TransactionManager($databaseConnection);
$customerAccounts = new CustomerAccountRepository($databaseConnection, $transactions);
$passwordResets = new PasswordResetRepository($databaseConnection, $transactions);
$mailConfig = require __DIR__ . '/../config/mail.php';
$resetMailer = new PhpMailPasswordResetMailer($mailConfig['from_address'], $mailConfig['from_name']);
$authFlash = new AuthFormFlash();
$passwordResetService = new PasswordResetService(
    $customerAccounts,
    $passwordResets,
    $transactions,
    $resetMailer
);
$passwordResetController = new PasswordResetController(
    $passwordResetService,
    $csrf,
    $authFlash,
    BASE_URL
);
