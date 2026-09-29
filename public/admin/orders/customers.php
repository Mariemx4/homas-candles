<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administratorAuthorization->requireActiveAdministrator();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
try {
    if (isset($_GET['id'])) {
        $id = is_string($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $customer = $id ? $orderRepository->findCustomer($id) : null;
        if ($customer === null) { http_response_code(404); echo json_encode(['error' => 'Customer account not found.']); exit; }
        echo json_encode(['customer' => $customer], JSON_THROW_ON_ERROR);
    } else {
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        echo json_encode(['customers' => strlen($q) >= 2 && strlen($q) <= 100 ? $orderRepository->searchCustomers($q) : []], JSON_THROW_ON_ERROR);
    }
} catch (Throwable) { http_response_code(503); echo json_encode(['error' => 'Customer accounts are temporarily unavailable.']); }
