<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Admin Product Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\AdminProductService;
use Throwable;

final class AdminProductController
{
    /**
     * Receive the business service and CSRF checker from bootstrap wiring.
     * The controller does not create these dependencies itself, which keeps it
     * testable and separates construction from request handling.
     */
    public function __construct(
        private readonly AdminProductService $products,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * Load the filtered, paginated product list for the catalogue screen.
     * On failure, return the complete empty shape expected by the view so the
     * administrator sees a controlled error rather than undefined variables.
     *
     * @return array<string, mixed>
     */
    public function index(array $query): array
    {
        try {
            return $this->products->list($query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator product list could not be loaded.');

            return [
                'products' => [], 'categories' => [], 'productTypes' => [],
                'filters' => ['search' => null, 'category' => null, 'type' => null, 'active' => null, 'purchase_mode' => null],
                'page' => 1, 'perPage' => AdminProductService::PER_PAGE, 'total' => 0, 'totalPages' => 1,
                'queryState' => [], 'returnQuery' => '', 'filtersAdjusted' => false,
                'loadError' => 'Products are temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * Load an existing product for editing, or create the blank new-product
     * detail when no ID was supplied.
     *
     * `mixed $rawId` reflects that query-string data may be absent, text, or an
     * unexpected type. positiveId() converts only a safe positive integer.
     *
     * @return array{detail: array<string, mixed>|null, httpStatus: int, loadError: string|null}
     */
    public function show(mixed $rawId): array
    {
        $id = $this->positiveId($rawId);
        if ($rawId !== null && $rawId !== '' && $id === null) {
            return ['detail' => null, 'httpStatus' => 400, 'loadError' => 'The product identifier is invalid.'];
        }

        try {
            $detail = $this->products->detail($id);
        } catch (Throwable) {
            error_log('An administrator product record could not be loaded.');

            return ['detail' => null, 'httpStatus' => 503, 'loadError' => 'This product is temporarily unavailable. Please try again later.'];
        }

        return [
            'detail' => $detail,
            'httpStatus' => $detail === null ? 404 : 200,
            'loadError' => $detail === null ? 'The requested product could not be found.' : null,
        ];
    }

    /**
     * Validate one submitted product form and dispatch its named action.
     *
     * A single edit screen contains several forms (core fields, translation,
     * images, colors, scents, components, activation). The hidden `action`
     * value identifies which small operation the user requested.
     *
     * @return array<string, mixed> A uniform result used for flash messages and redirects.
     */
    public function processAction(array $post, array $files, int $administratorId): array
    {
        // Copy submitted values for redisplay after validation, but never place
        // the security token back into flash/session data.
        $productId = $this->positiveId($post['id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $values = $post;
        unset($values['csrf_token']);

        // Validate before any state-changing service method runs. Rotate the
        // token after every attempt so a captured token cannot simply be reused.
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->result(false, $productId, $action, ['form' => 'The form expired. Review the product and try again.'], $values);
        }

        try {
            // `match` is an expression: exactly one branch returns `$result`.
            // Unknown action names are rejected instead of silently doing work.
            $result = match ($action) {
                'save_core' => $this->products->saveCore($productId, $post),
                'save_translation' => $this->processTranslation($productId, $post),
                'generate_translations' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Invalid product.']]
                    : $this->products->generateTranslations($productId, is_string($post['language_code'] ?? null) ? $post['language_code'] : ''),
                'approve_translation' => $this->processTranslationReview($productId, $post, true, $administratorId),
                'reject_translation' => $this->processTranslationReview($productId, $post, false, $administratorId),
                'add_color' => $this->processColor($productId, $post),
                'remove_color' => $this->processRemoveColor($productId, $post),
                'upload_color_image' => $this->processColorImage($productId, $post, $files),
                'upload_main_image' => $this->processMainImage($productId, $files),
                'add_scent' => $this->processScent($productId, $post),
                'remove_scent' => $this->processRemoveScent($productId, $post),
                'add_component' => $this->processComponent($productId, $post),
                'remove_component' => $this->processRemoveComponent($productId, $post),
                'activate' => $this->processActivate($productId, true),
                'deactivate' => $this->processActivate($productId, false),
                default => ['success' => false, 'errors' => ['form' => 'The requested action is not supported.']],
            };
        } catch (Throwable) {
            error_log('An administrator product action could not be completed.');
            $result = ['success' => false, 'errors' => ['form' => 'This change could not be saved. Please try again.']];
        }

        // A newly inserted product did not have an ID at request time. Preserve
        // the generated database ID so the route can redirect to its edit page.
        if ($action === 'save_core' && $result['success'] && $productId === null) {
            $productId = $result['id'];
        }

        // `notice` names products returned to draft by ProductReadinessService, if any.
        return $this->result($result['success'], $productId, $action, $result['errors'], $values)
            + ['notice' => $result['notice'] ?? null];
    }

    /** Extract translation text from POST and delegate its validation/saving. */
    private function processTranslation(?int $productId, array $post): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'The product identifier is invalid.']];
        }
        $language = isset($post['language_code']) && is_string($post['language_code']) ? $post['language_code'] : '';
        $name = isset($post['name']) && is_string($post['name']) ? $post['name'] : '';
        $description = isset($post['description']) && is_string($post['description']) ? $post['description'] : '';

        return $this->products->saveTranslation($productId, $language, $name, $description);
    }

    private function processTranslationReview(?int $productId, array $post, bool $approve, int $administratorId): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'Invalid product.']];
        }
        $language = is_string($post['language_code'] ?? null) ? $post['language_code'] : '';
        return $this->products->reviewTranslation($productId, $language, $approve, $administratorId);
    }

    /** Validate IDs before assigning a color to the product. */
    private function processColor(?int $productId, array $post): array
    {
        $colorId = $this->positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['color' => 'Select a valid color.']];
        }

        return $this->products->addColor($productId, $colorId);
    }

    /** Validate IDs before removing an assigned color. */
    private function processRemoveColor(?int $productId, array $post): array
    {
        $colorId = $this->positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['form' => 'The color identifier is invalid.']];
        }

        return $this->products->removeColor($productId, $colorId);
    }

    /** Extract the selected color and uploaded file for color-image storage. */
    private function processColorImage(?int $productId, array $post, array $files): array
    {
        $colorId = $this->positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['color_image' => 'Select a valid color.']];
        }

        return $this->products->uploadColorImage($productId, $colorId, $files['image'] ?? null);
    }

    /** Pass the main uploaded image to the service for security validation. */
    private function processMainImage(?int $productId, array $files): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'The product identifier is invalid.']];
        }

        return $this->products->uploadMainImage($productId, $files['image'] ?? null);
    }

    /** Validate IDs before assigning a scent to the product. */
    private function processScent(?int $productId, array $post): array
    {
        $scentId = $this->positiveId($post['scent_id'] ?? null);
        if ($productId === null || $scentId === null) {
            return ['success' => false, 'errors' => ['scent' => 'Select a valid scent.']];
        }

        return $this->products->addScent($productId, $scentId);
    }

    /** Validate IDs before removing an assigned scent. */
    private function processRemoveScent(?int $productId, array $post): array
    {
        $scentId = $this->positiveId($post['scent_id'] ?? null);
        if ($productId === null || $scentId === null) {
            return ['success' => false, 'errors' => ['form' => 'The scent identifier is invalid.']];
        }

        return $this->products->removeScent($productId, $scentId);
    }

    /** Validate a set component and require a whole-number quantity of at least one. */
    private function processComponent(?int $productId, array $post): array
    {
        $componentId = $this->positiveId($post['component_product_id'] ?? null);
        // filter_var returns the integer on success and boolean false on failure.
        $quantity = filter_var($post['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === null || $componentId === null || $quantity === false) {
            return ['success' => false, 'errors' => ['component' => 'Select a valid component and quantity.']];
        }

        return $this->products->addComponent($productId, $componentId, $quantity);
    }

    /** Validate IDs before removing a product from a candle set. */
    private function processRemoveComponent(?int $productId, array $post): array
    {
        $componentId = $this->positiveId($post['component_product_id'] ?? null);
        if ($productId === null || $componentId === null) {
            return ['success' => false, 'errors' => ['form' => 'The component identifier is invalid.']];
        }

        return $this->products->removeComponent($productId, $componentId);
    }

    /** Send an explicit active/inactive state to the business service. */
    private function processActivate(?int $productId, bool $active): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'The product identifier is invalid.']];
        }

        return $this->products->toggleActive($productId, $active);
    }

    /**
     * Convert untrusted request data into a positive PHP integer.
     *
     * The regular expression rejects signs, decimals, leading zeroes, letters,
     * and excessively long values before FILTER_VALIDATE_INT checks platform
     * integer limits. Invalid input becomes null rather than a misleading zero.
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

    /** Build one consistent controller response for every product form action. */
    private function result(bool $success, ?int $productId, string $action, array $errors, array $values): array
    {
        return ['success' => $success, 'id' => $productId, 'action' => $action, 'errors' => $errors, 'values' => $values];
    }
}
