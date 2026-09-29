<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\OrderEditService;
use Throwable;

final class OrderEditController
{
    public function __construct(private readonly OrderEditService $orders, private readonly CsrfTokenManager $csrf)
    {
    }

    public function process(int $orderId, array $post, ?int $administratorId = null): array
    {
        $values = [];
        foreach ([...OrderEditService::FIELDS, 'version'] as $field) {
            $values[$field] = isset($post[$field]) && is_string($post[$field]) ? $post[$field] : '';
        }
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$valid) {
            return ['success' => false, 'errors' => ['form' => 'The order form expired. Review it and try again.'], 'values' => $values];
        }
        try {
            $result = $this->orders->update($orderId, $values, $values['version'], $administratorId);
        } catch (Throwable) {
            error_log('An administrator order edit could not be saved.');
            $result = ['success' => false, 'errors' => ['form' => 'The order could not be updated. No changes were saved.']];
        }
        return $result + ['values' => $values];
    }
}
