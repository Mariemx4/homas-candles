<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap/autoload.php';

use App\Http\ErrorBoundary;
use App\Services\Locale;

try {
    require_once __DIR__ . '/../app/Bootstrap/session.php';
    $customerId = $customerSession->requireCustomer();
    header('Cache-Control: no-store, private');
    require_once __DIR__ . '/../app/Bootstrap/customer-account.php';
    $view = $customerAccountController->overview($customerId, $_GET);
    if ($view['account'] === null) {
        $customerSession->signOut();
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
    extract($view, EXTR_SKIP);
} catch (Throwable $exception) {
    ErrorBoundary::respond($exception, 'customer-account');
}

$pageTitle = Locale::text('account.title') . " | Hom'As Candles";
require VIEW_PATH . '/Pages/customer-account.php';
