<?php
declare(strict_types=1);
// Local test router only: a random runner-generated capability is required.
$token = getenv('HOMAS_UPLOAD_TEST_TOKEN');
if (PHP_SAPI !== 'cli-server' || !is_string($token) || $token === '' || !hash_equals($token, $_SERVER['HTTP_X_UPLOAD_TEST_TOKEN'] ?? '')) {
    http_response_code(403); exit;
}
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
$storage = new \App\Services\PaymentEvidenceStorage((string) getenv('HOMAS_UPLOAD_TEST_DIRECTORY'));
header('Content-Type: application/json');
echo json_encode($storage->store($_FILES['evidence'] ?? null), JSON_THROW_ON_ERROR);
