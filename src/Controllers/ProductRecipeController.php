<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Product Recipe Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\ProductRecipeService;
use Throwable;

final class ProductRecipeController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly ProductRecipeService $recipes,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `show` (show) and return its result.
     *
     * @return array{detail: array<string, mixed>|null, httpStatus: int, loadError: string|null}
     */
    public function show(mixed $rawProductId): array
    {
        $productId = $this->positiveId($rawProductId);
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

    /**
     * FUNCTION PURPOSE: Handle the action request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processAction(array $post): array
    {
        $productId = $this->positiveId($post['product_id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $recipeType = isset($post['recipe_type']) && is_string($post['recipe_type']) ? strtoupper($post['recipe_type']) : '';
        $materialId = $this->positiveId($post['material_id'] ?? null);
        $quantity = isset($post['quantity_required']) && is_string($post['quantity_required']) ? $post['quantity_required'] : '';
        $values = $post;
        unset($values['csrf_token']);

        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
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
     * FUNCTION PURPOSE: Convert id into a positive integer or reject it.
     */
    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `result` (result) and return its result.
     *
     * @return array<string, mixed>
     */
    private function result(bool $success, ?int $productId, array $errors, array $values): array
    {
        return ['success' => $success, 'productId' => $productId, 'errors' => $errors, 'values' => $values];
    }
}
