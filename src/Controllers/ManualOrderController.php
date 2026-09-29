<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Adapt the manual-order HTML form to the business service.
 *
 * The controller owns HTTP-shaped concerns such as CSRF and safe redisplay;
 * pricing and order rules remain in ManualOrderService.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\ManualOrderService;
use App\Services\OrderProductionService;
use Throwable;

final class ManualOrderController
{
    public function __construct(
        private readonly ManualOrderService $orders,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /** @return array<string, mixed> */
    public function process(array $post, int $administratorId): array
    {
        // Only a small allow-list is retained for flash redisplay. In particular,
        // the CSRF token is never copied into session flash data.
        $values = $this->safeValues($post);
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'errors' => ['form' => 'The order form expired. Review it and try again.'], 'values' => $values];
        }
        try {
            $result = $this->orders->create($administratorId, $values);
        } catch (Throwable) {
            error_log('An administrator manual order could not be created.');
            $result = ['success' => false, 'errors' => ['form' => 'The order could not be created. No data was saved.']];
        }
        return $result + ['values' => $values];
    }

    /**
     * FUNCTION PURPOSE: Supply the catalogue products and materials the form can offer.
     *
     * @return array{products: array<int, array<string, mixed>>, materials: array<int, array<string, mixed>>}
     */
    public function formOptions(): array
    {
        try {
            return $this->orders->formOptions();
        } catch (Throwable) {
            error_log('The manual order catalogue could not be loaded.');

            return ['products' => [], 'materials' => []];
        }
    }

    /** @return array<string, mixed> */
    private function safeValues(array $post): array
    {
        $values = [];
        foreach (['customer_id', 'customer_name', 'customer_email', 'customer_phone', 'delivery_method', 'required_date', 'delivery_fee', 'street_address', 'delegation', 'governorate', 'postal_code', 'customer_notes', 'administrator_notes'] as $key) {
            $values[$key] = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
        }
        $values['items'] = [];
        if (isset($post['items']) && is_array($post['items'])) {
            // Read at most 51 entries: the service can then report that the
            // documented limit of 50 was exceeded without accepting an
            // unbounded attacker-controlled array into session flash data.
            foreach (array_slice($post['items'], 0, 51) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $safeItem = [];
                foreach (['product_id', 'color_id', 'scent_id', 'product_name', 'quantity', 'unit_price', 'customization_notes'] as $key) {
                    $safeItem[$key] = isset($item[$key]) && is_string($item[$key]) ? $item[$key] : '';
                }
                // Recipe lines are bounded the same way: one more than the
                // service limit, so an oversized recipe is reported, not truncated silently.
                $safeItem['recipe'] = [];
                if (isset($item['recipe']) && is_array($item['recipe'])) {
                    foreach (array_slice($item['recipe'], 0, OrderProductionService::MAX_RECIPE_LINES + 1) as $line) {
                        if (is_array($line)) {
                            $safeItem['recipe'][] = [
                                'material_id' => isset($line['material_id']) && is_string($line['material_id']) ? $line['material_id'] : '',
                                'quantity' => isset($line['quantity']) && is_string($line['quantity']) ? $line['quantity'] : '',
                            ];
                        }
                    }
                }
                $values['items'][] = $safeItem;
            }
        }
        return $values;
    }
}
