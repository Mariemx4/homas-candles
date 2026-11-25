<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\AdminProductService;
use App\Services\CandlePriceService;
use DomainException;
use App\Services\ProductImageService;
use App\Services\ProductTranslationService;
use App\Services\PricingService;
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
        private readonly ProductTranslationService $translations,
        private readonly ProductImageService $images,
        private readonly PricingService $pricing,
        private readonly CandlePriceService $candles,
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
            return $this->products->list($query) + ['candleComparison' => $this->candles->comparison(), 'candleSettings' => $this->candles->settings(), 'loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator product list could not be loaded.');

            return [
                'products' => [], 'categories' => [], 'productTypes' => [],
                'filters' => ['search' => null, 'category' => null, 'type' => null, 'active' => null, 'purchase_mode' => null],
                'page' => 1, 'perPage' => AdminProductService::PER_PAGE, 'total' => 0, 'totalPages' => 1, 'categoryCounts' => [], 'categoryRules' => [],
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
        $id = FormInput::positiveId($rawId);
        if ($rawId !== null && $rawId !== '' && $id === null) {
            return ['detail' => null, 'httpStatus' => 400, 'loadError' => 'The product identifier is invalid.'];
        }

        try {
            $detail = $this->products->detail($id);
            if ($detail !== null && $id !== null) {
                $detail['candle_pricing'] = $this->candles->panel($id);
            }
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
        $productId = FormInput::positiveId($post['id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $values = $post;
        foreach (['minimum_quantity','maximum_quantity','unit_price','tier_id'] as $field) {
            if (isset($values[$field]) && !is_string($values[$field])) {
                $values[$field] = '';
            }
        }
        unset($values['csrf_token']);

        // Validate before any state-changing service method runs. Rotate the
        // token after every attempt so a captured token cannot simply be reused.
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->result(false, $productId, $action, ['form' => 'The form expired. Review the product and try again.'], $values);
        }

        try {
            // `match` is an expression: exactly one branch returns `$result`.
            // Unknown action names are rejected instead of silently doing work.
            $result = match ($action) {
                'create_draft' => $productId === null ? $this->products->createNamedDraft($post) : ['success' => false,'errors' => ['form' => 'This product already exists. Continue editing the saved draft.']],
                'save_identity' => $productId === null ? ['success' => false,'errors' => ['form' => 'Save a draft first.']] : $this->products->saveIdentity($productId, $post),
                'add_quantity_price' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Save the product first.']] : $this->pricing->addQuantityPrice($productId, $post),
                'remove_quantity_price' => $productId === null || FormInput::positiveId($post['tier_id'] ?? null) === null
                    ? ['success' => false, 'errors' => ['form' => 'Choose a valid quantity tier.']]
                    : $this->pricing->removeQuantityPrice($productId, FormInput::positiveId($post['tier_id'])),
                'save_core' => $this->products->saveCore($productId, $post),
                'save_category_rule' => FormInput::positiveId($post['category_id'] ?? null) === null
                    ? ['success' => false, 'errors' => ['form' => 'The category could not be found.']]
                    : $this->products->saveCategoryRule(FormInput::positiveId($post['category_id']), (string) ($post['title'] ?? ''), (string) ($post['body'] ?? '')),
                'change_category' => $productId === null || FormInput::positiveId($post['category_id'] ?? null) === null
                    ? ['success' => false, 'errors' => ['category_id' => 'Choose a category.']]
                    : $this->products->changeCategory($productId, FormInput::positiveId($post['category_id'])),
                'save_candle_weight' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Save the product first.']]
                    : $this->candleAction($productId, $action, fn () => $this->candles->saveWeight($productId, (string) ($post['candle_weight_grams'] ?? ''))),
                'apply_suggested_price' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Save the product first.']]
                    : $this->candleAction($productId, $action, fn () => $this->candles->applySuggestion($productId, $administratorId)),
                'set_manual_price' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Save the product first.']]
                    : $this->candleAction($productId, $action, fn () => $this->candles->setManualPrice($productId, (string) ($post['selling_price'] ?? ''), $administratorId)),
                'apply_suggested_prices' => $this->candleBulkAction($post, $administratorId),
                'save_pricing_settings' => $this->candleSettingsAction($post),
                'save_translation' => $this->processTranslation($productId, $post),
                'generate_translations' => $productId === null ? ['success' => false, 'errors' => ['form' => 'Invalid product.']]
                    : $this->translations->generate($productId, is_string($post['language_code'] ?? null) ? $post['language_code'] : ''),
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
        if (in_array($action, ['save_core','create_draft'], true) && $result['success'] && $productId === null) {
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

        return $this->translations->save($productId, $language, $name, $description);
    }

    private function processTranslationReview(?int $productId, array $post, bool $approve, int $administratorId): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'Invalid product.']];
        }
        $language = is_string($post['language_code'] ?? null) ? $post['language_code'] : '';
        return $this->translations->review($productId, $language, $approve, $administratorId);
    }

    /** Validate IDs before assigning a color to the product. */
    private function processColor(?int $productId, array $post): array
    {
        $colorId = FormInput::positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['color' => 'Select a valid color.']];
        }

        return $this->products->addColor($productId, $colorId);
    }

    /** Validate IDs before removing an assigned color. */
    private function processRemoveColor(?int $productId, array $post): array
    {
        $colorId = FormInput::positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['form' => 'The color identifier is invalid.']];
        }

        return $this->products->removeColor($productId, $colorId);
    }

    /** Extract the selected color and uploaded file for color-image storage. */
    private function processColorImage(?int $productId, array $post, array $files): array
    {
        $colorId = FormInput::positiveId($post['color_id'] ?? null);
        if ($productId === null || $colorId === null) {
            return ['success' => false, 'errors' => ['color_image' => 'Select a valid color.']];
        }

        return $this->images->replaceColorImage($productId, $colorId, $files['image'] ?? null);
    }

    /** Pass the main uploaded image to the service for security validation. */
    private function processMainImage(?int $productId, array $files): array
    {
        if ($productId === null) {
            return ['success' => false, 'errors' => ['form' => 'The product identifier is invalid.']];
        }

        return $this->images->replaceMainImage($productId, $files['image'] ?? null);
    }

    /** Validate IDs before assigning a scent to the product. */
    private function processScent(?int $productId, array $post): array
    {
        $scentId = FormInput::positiveId($post['scent_id'] ?? null);
        if ($productId === null || $scentId === null) {
            return ['success' => false, 'errors' => ['scent' => 'Select a valid scent.']];
        }

        return $this->products->addScent($productId, $scentId);
    }

    /** Validate IDs before removing an assigned scent. */
    private function processRemoveScent(?int $productId, array $post): array
    {
        $scentId = FormInput::positiveId($post['scent_id'] ?? null);
        if ($productId === null || $scentId === null) {
            return ['success' => false, 'errors' => ['form' => 'The scent identifier is invalid.']];
        }

        return $this->products->removeScent($productId, $scentId);
    }

    /** Validate a set component and require a whole-number quantity of at least one. */
    private function processComponent(?int $productId, array $post): array
    {
        $componentId = FormInput::positiveId($post['component_product_id'] ?? null);
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
        $componentId = FormInput::positiveId($post['component_product_id'] ?? null);
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

    /** Runs one candle price change and turns a refused change into a form error, not a server error. */
    private function candleAction(int $productId, string $action, callable $change): array
    {
        try {
            $change();
        } catch (DomainException $error) {
            return $this->result(false, $productId, $action, ['form' => $error->getMessage()], []);
        }

        return $this->result(true, $productId, $action, [], []);
    }

    /** Saves the owner's pricing settings; a refused value is returned as a form error. */
    private function candleSettingsAction(array $post): array
    {
        try {
            $this->candles->saveSettings($post);
        } catch (DomainException $error) {
            return $this->result(false, null, 'save_pricing_settings', ['form' => $error->getMessage()], []);
        }

        return $this->result(true, null, 'save_pricing_settings', [], []);
    }

    /** Approves the calculated prices for the candles the owner ticked. */
    private function candleBulkAction(array $post, int $administratorId): array
    {
        $ids = $post['product_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->result(false, null, 'apply_suggested_prices', ['form' => 'Tick at least one candle to apply.'], []);
        }
        $outcome = $this->candles->applySuggestions(array_map('strval', $ids), $administratorId);
        $errors = $outcome['skipped'] === [] ? [] : ['form' => count($outcome['skipped']) . ' candle(s) were skipped: ' . implode(' ', array_values($outcome['skipped']))];

        return $this->result($outcome['applied'] > 0 && $errors === [], null, 'apply_suggested_prices', $errors, ['applied' => $outcome['applied']]);
    }

    /** Build one consistent controller response for every product form action. */
    private function result(bool $success, ?int $productId, string $action, array $errors, array $values): array
    {
        return ['success' => $success, 'id' => $productId, 'action' => $action, 'errors' => $errors, 'values' => $values];
    }
}
