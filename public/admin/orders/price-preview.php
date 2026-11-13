<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administratorAuthorization->requireActiveAdministrator();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$result = $manualOrderController->preview($_POST);
echo json_encode($result, JSON_THROW_ON_ERROR);
