<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ListQuery;
use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use App\Repositories\TaxonomyRepository;
use DomainException;

/**
 * Entry point for every catalogue-administration product action.
 *
 * Owner: admin catalogue (AdminProductController, AdminLifecycleService).
 * The controller calls only this class, so its public method names are the
 * contract for /admin/products and must stay stable.
 *
 * Responsibilities kept here: the product list, the edit-screen data, saving
 * core settings (with the accessory stock-concurrency check), draft creation,
 * colors, scents, set components and activation.
 *
 * Delegated to focused collaborators:
 * - ProductCoreValidator       validates the core form (category rules);
 * - ProductTranslationService  saves, generates and reviews translations;
 * - ProductImageService        replaces photos with safe file clean-up;
 * - PricingService             decides every selling price and quantity tier;
 * - ProductReadinessService    wraps changes in a transaction, reprices, and
 *                              rejects changes that would break an active
 *                              product (or returns dependants to draft).
 *
 * Every write returns `['success' => bool, 'errors' => [field => message]]`
 * plus an optional `notice`; DomainException is used internally to abort a
 * transaction with a message the administrator can read.
 */
final class AdminProductService
{
    // Public constants are also read by the admin views when building filters.
    public const PURCHASE_MODES = ['CART', 'REQUEST_ONLY'];
    public const PER_PAGE = 20;

    private readonly ProductCoreValidator $coreValidator;
    private readonly ProductTranslationService $translations;
    private readonly ProductImageService $imageReplacement;

    /**
     * The constructor signature is shared by the bootstrap and several test
     * suites, so the focused collaborators are built here from the same
     * dependencies instead of being added as new parameters.
     *
     * - `$products` reads/writes product records.
     * - `$taxonomy` supplies category/type/color/scent options.
     * - `$transactions` commits related database writes together.
     * - `$images` validates and stores public product image files.
     * - `$pricing` is the only place selling prices are decided.
     * - `$readiness` owns activation requirements (see class comment).
     * - `$accessoryStock` ledgers accessory stock corrections.
     * - `$translationGenerator` is optional; without it drafts are marked FAILED.
     */
    public function __construct(
        private readonly AdminProductRepository $products,
        private readonly TaxonomyRepository $taxonomy,
        private readonly TransactionManager $transactions,
        ProductImageStorage $images,
        private readonly PricingService $pricing,
        private readonly ProductReadinessService $readiness,
        private readonly AccessoryStockService $accessoryStock,
        ?ProductTranslationGenerator $translationGenerator = null
    ) {
        $this->coreValidator = new ProductCoreValidator($products);
        $this->translations = new ProductTranslationService($products, $readiness, $translationGenerator);
        $this->imageReplacement = new ProductImageService($products, $transactions, $images);
    }

    public function addQuantityPrice(int $id, array $input): array
    {
        return $this->pricing->addQuantityPrice($id, $input);
    }

    public function removeQuantityPrice(int $id, int $tier): array
    {
        return $this->pricing->removeQuantityPrice($id, $tier);
    }

    /**
     * Normalize list filters, run pagination queries, and assemble list data.
     *
     * @return array<string, mixed> Values consumed by the products-list view.
     */
    public function list(array $query): array
    {
        // Query strings are untrusted text. Normalize them before repositories
        // use them as filter parameters.
        $normalized = $this->normalizeListQuery($query);
        $filters = $normalized['filters'];
        $total = $this->products->countForList($filters);
        // ceil() includes a partially filled final page. max(1, ...) keeps an
        // empty result on page 1, and min() corrects an excessive requested page.
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->products->findForList($filters, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $state = $this->queryState($normalized, $page);

        return [
            'products' => $rows,
            'categories' => $this->taxonomy->findActiveOptions('categories'),
            'productTypes' => $this->taxonomy->findActiveOptions('product_types'),
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * Build all data needed by the product create/edit screen.
     *
     * A null ID means "new product" and returns blank values. A missing numeric
     * ID means "not found" and returns null so the controller can use HTTP 404.
     *
     * @return array<string, mixed>|null
     */
    public function detail(?int $productId): ?array
    {
        $categories = $this->taxonomy->findActiveOptions('categories');
        $productTypes = $this->taxonomy->findActiveOptions('product_types');
        $colors = $this->taxonomy->findActiveOptions('colors');
        $scents = $this->taxonomy->findActiveOptions('scents');

        if ($productId === null) {
            // Keep the new-product view shape identical to an edit view so the
            // template does not need separate variables for the two modes.
            return [
                'product' => null,
                'translations' => ['en' => ['name' => '', 'description' => ''], 'fr' => ['name' => '', 'description' => ''], 'ar' => ['name' => '', 'description' => '']],
                'translationReviews' => [],
                'assignedColors' => [],
                'assignedScents' => [],
                'components' => [],
                'diffuserCandidates' => [],
                'componentCandidates' => [],
                'categories' => $categories,
                'productTypes' => $productTypes,
                'colors' => $colors,
                'scents' => $scents,
                'readiness' => ['items' => [], 'ready' => false],
                'pricing' => null,
            ];
        }

        $product = $this->products->find($productId);
        if ($product === null) {
            return null;
        }

        if (!in_array((string)$product['category_id'], array_map('strval', array_column($categories, 'id')), true)) {
            $categories[] = ['id' => $product['category_id'],'name' => $product['category_name'] . ' (Inactive)'];
        }
        if ($product['product_type_id'] !== null && !in_array((string)$product['product_type_id'], array_map('strval', array_column($productTypes, 'id')), true)) {
            $productTypes[] = ['id' => $product['product_type_id'],'name' => $product['product_type_slug'] . ' (Inactive)'];
        }
        $translations = $this->products->findTranslations($productId);
        // Ensure all supported languages exist even if their database rows do not.
        foreach (ProductTranslationService::LANGUAGES as $languageCode) {
            $translations[$languageCode] ??= ['name' => '', 'description' => ''];
        }
        $assignedColors = $this->products->findColors($productId);
        $assignedScents = $this->products->findScents($productId);
        $isSet = $product['category_slug'] === CatalogueCategory::SETS;
        $components = $isSet ? $this->products->findComponents($productId) : [];

        return [
            'product' => $product,
            'translations' => $translations,
            'translationReviews' => $this->products->findTranslationReviews($productId),
            'assignedColors' => $assignedColors,
            'assignedScents' => $assignedScents,
            'components' => $components,
            'diffuserCandidates' => $product['category_slug'] === CatalogueCategory::DIFFUSERS
                ? $this->products->findActiveProductOptions(CatalogueCategory::CANDLES, $productId)
                : [],
            'componentCandidates' => $isSet ? $this->products->findActiveProductOptions(null, $productId) : [],
            'categories' => $categories,
            'productTypes' => $productTypes,
            'colors' => $colors,
            'scents' => $scents,
            'readiness' => $this->readinessChecklist($productId),
            // How the rules price this product (or what is still missing).
            'pricing' => $this->pricing->quote($productId),
            'quantityPrices' => $this->pricing->quantityPrices($productId),
        ];
    }

    /**
     * Validate and insert/update the product's category, pricing, and flags.
     *
     * @return array{success: bool, errors: array<string, string>, id?: int}
     */
    public function saveCore(?int $productId, array $input): array
    {
        // Destructuring assigns the two returned array positions to named variables.
        [$core, $errors] = $this->coreValidator->validate($input, $productId);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // The closure runs inside one transaction with the readiness check:
            // applyChange() reprices, then rejects a core change that would make
            // this active product incomplete (e.g. switching it to another
            // category's rules), and returns broken dependants to draft.
            // `use (...)` imports outer local variables into that closure.
            $originalStock = isset($input['stock_quantity_original']) && is_string($input['stock_quantity_original'])
                && ctype_digit($input['stock_quantity_original']) ? (int) $input['stock_quantity_original'] : null;
            $applied = $this->readiness->applyChange(function () use ($productId, $core, $originalStock): int {
                $stockAfter = $core['stock_quantity'] === null ? null : (int) $core['stock_quantity'];
                if ($productId === null) {
                    $newId = $this->products->insertCore($core);
                    // An accessory's first stock count is recorded as OPENING.
                    $this->accessoryStock->recordFormChange($newId, null, $stockAfter);

                    return $newId;
                }
                $existing = $this->products->lockCore($productId);
                // Locking prevents two administrators from updating the same
                // core row concurrently without a consistent transaction view.
                if ($existing === null) {
                    throw new DomainException('The product could not be found.');
                }
                // WHAT: protect order movements made while this form was open.
                // WHY:  the form posts the stock it displayed. Unchanged → keep
                //       the current database value (an order may have taken
                //       units meanwhile); changed while the database also moved
                //       → refuse, so a deduction is never silently overwritten.
                if ($stockAfter !== null && $existing['stock_quantity'] !== null && $originalStock !== null) {
                    $currentStock = (int) $existing['stock_quantity'];
                    if ($stockAfter === $originalStock) {
                        $stockAfter = $currentStock;
                        $core['stock_quantity'] = $currentStock;
                    } elseif ($originalStock !== $currentStock) {
                        throw new DomainException('Orders changed this accessory\'s stock to ' . $currentStock
                            . ' while the form was open. Review the current stock and save again.');
                    }
                }
                $this->products->updateCore($productId, $core);
                // WHAT: a changed stock count is a physical correction; it is
                //       recorded as an ADJUSTMENT in the same transaction.
                // WHY:  order deductions and restorations are ledgered too, so the
                //       history always explains the current number.
                $this->accessoryStock->recordFormChange(
                    $productId,
                    $existing['stock_quantity'] === null ? null : (int) $existing['stock_quantity'],
                    $stockAfter
                );

                return $productId;
            }, $productId === null ? [] : [$productId]);
            $savedId = $applied['result'];
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\PDOException $exception) {
            // SQLSTATE 23000 represents a database constraint violation. Even
            // after the friendly pre-check, the unique index is the final guard.
            if ($exception->getCode() === '23000') {
                return ['success' => false, 'errors' => ['slug' => 'This slug is already used by another product.']];
            }

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'id' => $savedId, 'notice' => $applied['notice']];
    }

    /** Start a useful named draft; configuration and publication remain separate. */
    public function createNamedDraft(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        if ($name === '' || Text::length($name) > 180) {
            return ['success' => false,'errors' => ['name' => 'Enter a product name of up to 180 characters.']];
        }
        if (Text::length($description) > 20000) {
            return ['success' => false,'errors' => ['description' => 'Keep the description to 20,000 characters.']];
        }
        $category = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $record = $category === false ? null : $this->products->findCategory($category);
        $latin = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($latin === false ? '' : $latin)) ?? '', '-');
        $slug = substr($slug ?: 'product', 0, 150) . '-' . bin2hex(random_bytes(3));
        $core = ['category_id' => is_scalar($input['category_id'] ?? null) ? (string)$input['category_id'] : '',
            'product_type_id' => is_string($input['product_type_id'] ?? null) ? $input['product_type_id'] : '',
            'slug' => $slug,'stock_quantity' => ($record['slug'] ?? '') === CatalogueCategory::ACCESSORIES ? '0' : '',
            'minimum_quantity' => ($record['slug'] ?? '') === CatalogueCategory::EVENT_FAVORS ? '10' : '1',
            'purchase_mode' => 'CART','is_made_to_order' => '1','can_purchase_alone' => '1'];
        return $this->transactions->run(function () use ($core, $name, $description): array {
            $saved = $this->saveCore(null, $core);
            if (!$saved['success']) {
                return $saved;
            }
            // A draft may have a name before its description is written.
            $this->products->upsertTranslation((int)$saved['id'], 'en', $name, $description);
            return $saved;
        });
    }

    public function saveIdentity(int $id, array $input): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $errors = [];
        if ($name === '' || Text::length($name) > 180) {
            $errors['name'] = 'Enter a product name of up to 180 characters.';
        }
        if (Text::length($description) > 20000) {
            $errors['description'] = 'Keep the description to 20,000 characters.';
        }
        if ($errors !== []) {
            return ['success' => false,'errors' => $errors];
        }
        $current = $this->products->find($id);
        if ($current === null) {
            return ['success' => false,'errors' => ['form' => 'Product not found.']];
        }
        if ((int)$current['is_active'] === 1 || $description !== '') {
            return $this->saveTranslation($id, 'en', $name, $description);
        }
        try {
            $applied = $this->readiness->applyChange(function () use ($id, $name, $description): void {
                $this->products->upsertTranslation($id, 'en', $name, $description);
            }, [$id]);
            return ['success' => true,'errors' => [],'notice' => $applied['notice'] ?? ''];
        } catch (DomainException $error) {
            return ['success' => false,'errors' => ['form' => $error->getMessage()]];
        }
    }

    /** @see ProductTranslationService::save() */
    public function saveTranslation(int $productId, string $languageCode, string $name, string $description): array
    {
        return $this->translations->save($productId, $languageCode, $name, $description);
    }

    /** @see ProductTranslationService::generate() */
    public function generateTranslations(int $productId, string $sourceLanguage): array
    {
        return $this->translations->generate($productId, $sourceLanguage);
    }

    /** @see ProductTranslationService::review() */
    public function reviewTranslation(int $productId, string $languageCode, bool $approve, int $administratorId): array
    {
        return $this->translations->review($productId, $languageCode, $approve, $administratorId);
    }

    /** @see ProductImageService::replaceMainImage() */
    public function uploadMainImage(int $productId, ?array $file): array
    {
        return $this->imageReplacement->replaceMainImage($productId, $file);
    }

    /**
     * Assign one active color, rejecting missing and duplicate relationships.
     *
     * A new color has no photo yet, so an active product goes back to draft
     * (RETURN_TO_DRAFT) until the photo is uploaded; the photo form only
     * exists once the color is assigned, so refusing would be a dead end.
     */
    public function addColor(int $productId, int $colorId): array
    {
        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $colorId): void {
                if (!$this->products->productExists($productId)) {
                    throw new DomainException('The product could not be found.');
                }
                if (!$this->products->activeColorExists($colorId)) {
                    throw new DomainException('Select an active color.');
                }
                if ($this->products->colorAssigned($productId, $colorId)) {
                    throw new DomainException('This color is already assigned to the product.');
                }
                $this->products->addColor($productId, $colorId);
            }, [$productId], ProductReadinessService::RETURN_TO_DRAFT);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['color' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Remove a color relationship and then discard its now-unused image file.
     *
     * Removing an active product's last active color is rejected, and the photo
     * file is only deleted once the removal has actually committed.
     */
    public function removeColor(int $productId, int $colorId): array
    {
        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $colorId): ?string {
                $path = $this->products->findColorImagePath($productId, $colorId);
                $this->products->removeColor($productId, $colorId);

                return $path;
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['color' => $exception->getMessage()]];
        }
        $this->imageReplacement->discardIfUnused($applied['result']);

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /** @see ProductImageService::replaceColorImage() */
    public function uploadColorImage(int $productId, int $colorId, ?array $file): array
    {
        return $this->imageReplacement->replaceColorImage($productId, $colorId, $file);
    }

    /** Assign one active scent, rejecting missing and duplicate relationships. */
    public function addScent(int $productId, int $scentId): array
    {
        try {
            $this->transactions->run(function () use ($productId, $scentId): void {
                if (!$this->products->productExists($productId)) {
                    throw new DomainException('The product could not be found.');
                }
                if (!$this->products->activeScentExists($scentId)) {
                    throw new DomainException('Select an active scent.');
                }
                if ($this->products->scentAssigned($productId, $scentId)) {
                    throw new DomainException('This scent is already assigned to the product.');
                }
                $this->products->addScent($productId, $scentId);
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['scent' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * Remove the relationship between a product and a scent.
     *
     * Removing an active product's last active scent is rejected.
     */
    public function removeScent(int $productId, int $scentId): array
    {
        try {
            $applied = $this->readiness->applyChange(
                fn () => $this->products->removeScent($productId, $scentId),
                [$productId]
            );
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['scent' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Add an active non-set product as a component of a candle set.
     *
     * This prevents self-reference, nested sets, duplicates, invalid quantities,
     * and components being attached to ordinary products.
     */
    public function addComponent(int $setProductId, int $componentProductId, int $quantity): array
    {
        if ($quantity < 1 || $quantity > 1000) {
            return ['success' => false, 'errors' => ['component' => 'Enter a whole component quantity of at least 1.']];
        }
        if ($componentProductId === $setProductId) {
            return ['success' => false, 'errors' => ['component' => 'A set cannot contain itself.']];
        }

        try {
            // applyChange() reprices the set, then rejects a component that would leave
            // an active set unpriceable (for example a component with no cost yet).
            $applied = $this->readiness->applyChange(function () use ($setProductId, $componentProductId, $quantity): void {
                $set = $this->products->find($setProductId);
                if ($set === null) {
                    throw new DomainException('The set product could not be found.');
                }
                if ($set['category_slug'] !== CatalogueCategory::SETS) {
                    throw new DomainException('Only a product in the candle-sets category can have components.');
                }
                $component = $this->products->find($componentProductId);
                if ($component === null || (int) $component['is_active'] !== 1) {
                    throw new DomainException('Select an active product to add as a component.');
                }
                if ($component['category_slug'] === CatalogueCategory::SETS) {
                    throw new DomainException('A candle set cannot contain another set.');
                }
                if ($this->products->componentAssigned($setProductId, $componentProductId)) {
                    throw new DomainException('This product is already a component of the set.');
                }
                $this->products->addComponent($setProductId, $componentProductId, $quantity);
            }, [$setProductId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['component' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Remove one component relationship from a candle set.
     *
     * Rejected when it would leave an active set with fewer than two candles
     * (or otherwise incomplete); the set's price is recalculated either way.
     */
    public function removeComponent(int $setProductId, int $componentProductId): array
    {
        try {
            $applied = $this->readiness->applyChange(
                fn () => $this->products->removeComponent($setProductId, $componentProductId),
                [$setProductId]
            );
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['component' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Activate or deactivate a product.
     *
     * Activation first evaluates readiness so incomplete products cannot appear
     * in the customer catalogue. Deactivation is always allowed, but it can
     * break products that depend on this one (a set loses an active component),
     * so those are returned to draft in the same transaction.
     */
    public function toggleActive(int $productId, bool $active): array
    {
        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $active): void {
                if ($this->products->lockCore($productId) === null) {
                    throw new DomainException('The product could not be found.');
                }
                if ($active) {
                    $readiness = $this->readiness->evaluate($productId);
                    if ($readiness === null || !$readiness['ready']) {
                        throw new DomainException('This product cannot be activated yet: ' . implode('; ', $readiness['missing'] ?? []) . '.');
                    }
                }
                $this->products->setActive($productId, $active);
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Return the activation checklist shown on the edit screen.
     *
     * @return array{items: list<array{key: string, label: string, met: bool}>, ready: bool}
     */
    private function readinessChecklist(int $productId): array
    {
        $readiness = $this->readiness->evaluate($productId);

        return $readiness === null
            ? ['items' => [], 'ready' => false]
            : ['items' => $readiness['items'], 'ready' => $readiness['ready']];
    }

    /**
     * Sanitize list-page query parameters and record whether any were corrected.
     * Invalid filters become neutral values rather than reaching SQL.
     *
     * @return array<string, mixed>
     */
    private function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = trim(ListQuery::string($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $categoryText = ListQuery::string($query, 'category', $adjusted);
        $category = $categoryText === '' ? null : filter_var($categoryText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($category === false) {
            $category = null;
            $adjusted = true;
        }
        $typeText = ListQuery::string($query, 'type', $adjusted);
        $type = $typeText === '' ? null : filter_var($typeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === false) {
            $type = null;
            $adjusted = true;
        }
        $active = ListQuery::string($query, 'active', $adjusted);
        if (!in_array($active, ['', '0', '1'], true)) {
            $active = '';
            $adjusted = true;
        }
        $purchaseMode = strtoupper(ListQuery::string($query, 'purchase_mode', $adjusted));
        if ($purchaseMode !== '' && !in_array($purchaseMode, self::PURCHASE_MODES, true)) {
            $purchaseMode = '';
            $adjusted = true;
        }
        $pageText = ListQuery::string($query, 'page', $adjusted);
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'category' => $category === null ? null : (string) $category,
                'type' => $type === null ? null : (string) $type,
                'active' => $active === '' ? null : $active,
                'purchase_mode' => $purchaseMode === '' ? null : $purchaseMode,
            ],
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }

    /** Build the minimal valid query string state used by links and pagination. */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        // array_filter removes null/empty filters but deliberately keeps "0",
        // which is a meaningful inactive-product filter.
        return array_filter([
            'q' => $filters['search'],
            'category' => $filters['category'],
            'type' => $filters['type'],
            'active' => $filters['active'],
            'purchase_mode' => $filters['purchase_mode'],
            'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

}
