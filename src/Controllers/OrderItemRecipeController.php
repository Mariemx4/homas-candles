<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\OrderProductionService;
use Throwable;

final class OrderItemRecipeController
{
    public function __construct(
        private readonly OrderProductionService $production,
        private readonly CsrfTokenManager $csrf
    ) {
    }


    public function process(array $post): array
    {
        $orderId = $this->id($post, 'order_id');
        $itemId = $this->id($post, 'item_id');
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'errors' => ['recipe' => 'The recipe form expired. Review it and try again.'], 'orderId' => $orderId];
        }

        // Keep only string material/quantity pairs, bounded to one more line
        // than the service accepts so an oversized recipe is reported.
        $lines = [];
        if (isset($post['recipe']) && is_array($post['recipe'])) {
            foreach (array_slice($post['recipe'], 0, OrderProductionService::MAX_RECIPE_LINES + 1) as $line) {
                if (is_array($line)) {
                    $lines[] = [
                        'material_id' => isset($line['material_id']) && is_string($line['material_id']) ? $line['material_id'] : '',
                        'quantity' => isset($line['quantity']) && is_string($line['quantity']) ? $line['quantity'] : '',
                    ];
                }
            }
        }

        try {
            $result = $this->production->saveItemRecipe($orderId, $itemId, $lines);
        } catch (Throwable) {
            error_log('An order item production recipe could not be saved.');
            $result = ['success' => false, 'errors' => ['recipe' => 'The recipe could not be saved. No data was changed.']];
        }

        return $result + ['orderId' => $orderId];
    }


    private function id(array $post, string $key): int
    {
        $value = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? 0 : (int) $id;
    }
}
