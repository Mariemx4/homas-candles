<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Repositories\OrderRepository;
use App\Services\OrderService;

// An unconnected PDO lets this presentation check run without development data.
$pdo = new class () extends PDO {
    public function __construct()
    {
    }
};
$service = new OrderService(new OrderRepository($pdo));
$paymentSummary = new ReflectionMethod(OrderService::class, 'paymentSummary');
$check = TestCheck::printing();
foreach ([
    ['0.000', '40.000', 0, 'Unpaid'],
    ['0.000', '40.000', 1, 'Pending verification'],
    ['10.125', '40.000', 1, 'Partially paid'],
    ['40.000', '40.000', 0, 'Paid'],
    ['40.001', '40.000', 1, 'Credit/refund due'],
    ['0.000', '0.000', 0, 'Paid'],
] as [$paid, $total, $pending, $label]) {
    $summary = $paymentSummary->invoke($service, ['amount_paid' => $paid, 'total_amount' => $total, 'pending_payment_count' => $pending]);
    $check($summary['label'] === $label && $summary['hasPending'] === ($pending > 0), 'Payment summary: ' . $label . ' (' . $paid . '/' . $total . ')');
}

define('BASE_URL', '/');
$baseList = [
    'orders' => [], 'filters' => ['search' => null, 'status' => null, 'source' => null, 'method' => null, 'date_from' => null, 'date_to' => null],
    'sort' => 'required_date', 'direction' => 'asc', 'page' => 1, 'perPage' => 20,
    'total' => 0, 'totalPages' => 1, 'queryState' => [], 'returnQuery' => '',
    'filtersAdjusted' => false, 'hasActiveFilters' => false, 'loadError' => null,
];
$render = static function (array $orderList): string {
    $csrf = new class () {
        public function token(): string
        {
            return 'test-token';
        }
    };
    $administrator = ['first_name' => 'Test', 'last_name' => 'Admin'];
    $pageTitle = 'Orders';
    $activeNavigation = 'orders';
    $contentView = VIEW_PATH . '/Pages/admin/orders/index.php';
    ob_start();
    require VIEW_PATH . '/Layouts/admin.php';
    return (string) ob_get_clean();
};
$parse = static function (string $html): DOMXPath {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    return new DOMXPath($document);
};
$html = $render($baseList);
$xpath = $parse($html);
$check($xpath->query('//main[@id="admin-main"]')->length === 1, 'Layout exposes a real main landmark.');
$check(!str_contains($xpath->evaluate('string(//body)'), '-->'), 'HTML comment markers do not leak into visible text.');
$check(str_contains($html, 'No orders yet') && str_contains($html, '+ Create first order'), 'Unfiltered empty state offers first-order creation.');
$check($xpath->query('//header[contains(@class,"admin-page-header")]//a')->length === 2, 'Create and Trash remain visible without orders.');
$filtered = $render(array_replace($baseList, ['hasActiveFilters' => true, 'filters' => array_replace($baseList['filters'], ['search' => 'missing'])]));
$check(str_contains($filtered, 'No matching orders') && !str_contains($filtered, '+ Create first order') && str_contains($filtered, 'Reset filters'), 'Filtered empty state offers reset.');
$unavailable = $render(array_replace($baseList, ['loadError' => 'Unavailable']));
$check(str_contains($unavailable, 'Orders unavailable') && !str_contains($unavailable, 'No orders yet'), 'Load failure is distinct from an empty list.');
$row = [
    'id' => 7, 'order_number' => 'ORD-<unsafe>', 'customer_name_snapshot' => '<Customer>',
    'customer_id' => 42, 'order_source' => 'MANUAL', 'order_status' => 'REQUESTED',
    'required_date' => '2026-10-04', 'delivery_method' => 'PICKUP', 'total_amount' => '40.000',
    'deadline' => ['label' => 'Tomorrow', 'tone' => 'neutral'],
    'paymentSummary' => ['label' => 'Partially paid', 'tone' => 'info', 'hasPending' => true],
];
$html = $render(array_replace($baseList, ['orders' => [$row], 'total' => 1]));
$xpath = $parse($html);
$check($xpath->query('//tbody/tr/td')->length === 8, 'Order table contains eight operational columns.');
$check($xpath->query('//tbody//a[contains(@href,"customers/detail.php?id=42")]')->length === 1 && str_contains($html, '&lt;Customer&gt;'), 'Linked account uses explicit ID and snapshot is escaped.');
foreach (['detail', 'edit', 'delete'] as $route) {
    $check($xpath->query('//tbody//a[contains(@href,"orders/' . $route . '.php")]')->length > 0, 'Existing ' . $route . ' route is exposed.');
}
$check(str_contains($html, '04 Oct 2026') && str_contains($html, 'Tomorrow') && str_contains($html, '40.000 TND') && str_contains($html, 'Pending verification'), 'Real date, context, stored total and pending notice remain visible.');
$check($xpath->query('//div[@class="admin-order-row-actions"]/a[svg and @aria-label and @title]')->length === 3, 'View, edit and delete use labelled icons with tooltips.');
$row['statusOptions'] = [['status' => 'REQUESTED', 'disabled' => false], ['status' => 'CONFIRMED', 'disabled' => false]];
$row['statusReason'] = '';
$statusHtml = $render(array_replace($baseList, ['orders' => [$row], 'total' => 1]));
$statusXpath = $parse($statusHtml);
$check($statusXpath->query('//tbody/tr/td[7]//select[@name="new_status"]')->length === 1 && $statusXpath->query('//tbody/tr/td[7]//span[contains(@class,"admin-badge")]')->length === 0, 'Order status appears once in the dropdown without a duplicate badge.');
$row['customer_id'] = null;
$guest = $render(array_replace($baseList, ['orders' => [$row], 'total' => 1]));
$check(!str_contains($guest, 'customers/detail.php?id='), 'Guest snapshot does not infer customer ownership.');
