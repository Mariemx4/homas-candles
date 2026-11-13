<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\ProductRecipeService;
use Throwable;

final class ProductRecipeController
{
    public function __construct(
        private readonly ProductRecipeService $recipes,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * @return array{detail: array<string, mixed>|null, httpStatus: int, loadError: string|null}
     */
    public function show(mixed $rawProductId): array
    {
        $productId = FormInput::positiveId($rawProductId);
        if ($productId === null) {
            return ['detail' => null, 'httpStatus' => 400, 'loadError' => 'The product identifier is invalid.'];
        }

        try {
            $detail = $this->recipes->detail($productId);
        } catch (Throwable) {
            error_log('An administrator product recipe could not be loaded.');

            return ['detail' => null, 'httpStatus' => 503, 'loadError' => 'This recipe is temporarily unavailable. Please try again later.'];
        }

        return [
            'detail' => $detail,
            'httpStatus' => $detail === null ? 404 : 200,
            'loadError' => $detail === null ? 'The requested product could not be found.' : null,
        ];
    }


    public function processAction(array $post): array
    {
        $productId = FormInput::positiveId($post['product_id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $recipeType = isset($post['recipe_type']) && is_string($post['recipe_type']) ? strtoupper($post['recipe_type']) : '';
        $materialId = FormInput::positiveId($post['material_id'] ?? null);
        $quantity = isset($post['quantity_required']) && is_string($post['quantity_required']) ? $post['quantity_required'] : '';
        $values = $post;
        unset($values['csrf_token']);

        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->result(false, $productId, ['form' => 'The form expired. Review the recipe and try again.'], $values);
        }
        if ($productId === null) {
            return $this->result(false, null, ['form' => 'The product identifier is invalid.'], $values);
        }
        if ($materialId === null && $action !== '') {
            return $this->result(false, $productId, ['recipe' => 'Select a valid material.'], $values);
        }

        try {
            // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
            $result = match ($action) {
                'add_line' => $this->recipes->addLine($productId, $recipeType, $materialId, $quantity),
                'update_line' => $this->recipes->updateLine($productId, $recipeType, $materialId, $quantity),
                'remove_line' => $this->recipes->removeLine($productId, $recipeType, $materialId),
                default => ['success' => false, 'errors' => ['form' => 'The requested action is not supported.']],
            };
        } catch (Throwable) {
            error_log('An administrator product recipe action could not be completed.');
            $result = ['success' => false, 'errors' => ['form' => 'This change could not be saved. Please try again.']];
        }

        // `notice` names products returned to draft by ProductReadinessService, if any.
        return $this->result($result['success'], $productId, $result['errors'], $values)
            + ['notice' => $result['notice'] ?? null];
    }


    /**
     * @return array<string, mixed>
     */
    private function result(bool $success, ?int $productId, array $errors, array $values): array
    {
        return ['success' => $success, 'productId' => $productId, 'errors' => $errors, 'values' => $values];
    }
}
