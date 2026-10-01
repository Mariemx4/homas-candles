<?php   

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Product Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use App\Repositories\TaxonomyRepository;
use DomainException;

final class AdminProductService
{
    // Public constants are also read by the admin views when building filters.
    public const PURCHASE_MODES = ['CART', 'REQUEST_ONLY'];
    public const PER_PAGE = 20;

    // Slugs are stable machine-readable category identifiers. Business rules
    // compare slugs instead of translated category names.
    private const SET_CATEGORY_SLUG = 'candle-sets';
    private const ACCESSORY_CATEGORY_SLUG = 'accessories';
    private const CANDLE_CATEGORY_SLUG = 'candles';
    private const DIFFUSER_CATEGORY_SLUG = 'home-diffusers';
    private const LANGUAGES = ['en', 'fr', 'ar'];

    /**
     * Receive all collaborators used by product administration.
     *
     * - `$products` reads/writes product records.
     * - `$taxonomy` supplies category/type/color/scent options.
     * - `$transactions` commits related database writes together.
     * - `$images` validates and stores public product image files.
     * - `$pricing` is the only place selling prices are decided (PricingService).
     * - `$readiness` owns the activation requirements and re-checks them after
     *   every change that could break them (ProductReadinessService).
     */
    public function __construct(
        private readonly AdminProductRepository $products,
        private readonly TaxonomyRepository $taxonomy,
        private readonly TransactionManager $transactions,
        private readonly ProductImageStorage $images,
        private readonly PricingService $pricing,
        private readonly ProductReadinessService $readiness,
        private readonly AccessoryStockService $accessoryStock,
        private readonly ?ProductTranslationGenerator $translationGenerator = null
    ) {
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
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
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

        $translations = $this->products->findTranslations($productId);
        // Ensure all supported languages exist even if their database rows do not.
        foreach (self::LANGUAGES as $languageCode) {
            $translations[$languageCode] ??= ['name' => '', 'description' => ''];
        }
        $assignedColors = $this->products->findColors($productId);
        $assignedScents = $this->products->findScents($productId);
        $isSet = $product['category_slug'] === self::SET_CATEGORY_SLUG;
        $components = $isSet ? $this->products->findComponents($productId) : [];

        return [
            'product' => $product,
            'translations' => $translations,
            'translationReviews' => $this->products->findTranslationReviews($productId),
            'assignedColors' => $assignedColors,
            'assignedScents' => $assignedScents,
            'components' => $components,
            'diffuserCandidates' => $product['category_slug'] === self::DIFFUSER_CATEGORY_SLUG
                ? $this->products->findActiveProductOptions(self::CANDLE_CATEGORY_SLUG, $productId)
                : [],
            'componentCandidates' => $isSet ? $this->products->findActiveProductOptions(null, $productId) : [],
            'categories' => $categories,
            'productTypes' => $productTypes,
            'colors' => $colors,
            'scents' => $scents,
            'readiness' => $this->readinessChecklist($productId),
            // How the rules price this product (or what is still missing).
            'pricing' => $this->pricing->quote($productId),
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
        [$core, $errors] = $this->validateCore($input, $productId);
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

    /**
     * Save or clear one language's name and description as a pair.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function saveTranslation(int $productId, string $languageCode, string $name, string $description): array
    {
        if (!in_array($languageCode, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported language.']];
        }
        $name = trim($name);
        $description = trim($description);
        $errors = [];
        if ($name !== '' && $this->length($name) > 180) {
            $errors['name'] = 'The name must be 180 characters or fewer.';
        }
        if ($description !== '' && $this->length($description) > 20000) {
            $errors['description'] = 'The description must be 20,000 characters or fewer.';
        }
        // XOR-like comparison: this is true only when exactly one field is empty.
        if (($name === '') !== ($description === '')) {
            $errors['form'] = 'Provide both a name and a description for this language, or leave both blank.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            // Clearing a required language on an active product is rejected by applyChange().
            $applied = $this->readiness->applyChange(function () use ($productId, $languageCode, $name, $description): void {
                if (!$this->products->productExists($productId)) {
                    throw new DomainException('The product could not be found.');
                }
                // Empty pairs become SQL NULL values, allowing the repository's
                // upsert method to represent a deliberately absent translation.
                $this->products->upsertTranslation(
                    $productId,
                    $languageCode,
                    $name === '' ? null : $name,
                    $description === '' ? null : $description
                );
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }

        $generationNotice = $name !== '' ? $this->generateTranslations($productId, $languageCode)['notice'] : '';
        return ['success' => true, 'errors' => [], 'notice' => trim(($applied['notice'] ?? '') . ' ' . $generationNotice)];
    }

    /** Generate private drafts from a saved language; failures remain visible for retry. */
    public function generateTranslations(int $productId, string $sourceLanguage): array
    {
        if (!in_array($sourceLanguage, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported source language.']];
        }
        $source = $this->products->findTranslations($productId)[$sourceLanguage] ?? null;
        if ($source === null || trim($source['name']) === '' || trim($source['description']) === '') {
            return ['success' => false, 'errors' => ['form' => 'Save a complete source translation first.']];
        }
        $hash = self::translationHash($source['name'], $source['description']);
        $pending = 0;
        $failed = 0;
        foreach (self::LANGUAGES as $target) {
            if ($target === $sourceLanguage) {
                continue;
            }
            try {
                if ($this->translationGenerator === null) {
                    throw new \RuntimeException('Automatic translation is not configured.');
                }
                $translated = $this->translationGenerator->translate($sourceLanguage, $target, $source['name'], $source['description']);
                $candidateName = trim($translated['name']);
                $candidateDescription = trim($translated['description']);
                if ($candidateName === '' || $candidateDescription === '' || $this->length($candidateName) > 180 || $this->length($candidateDescription) > 20000) {
                    throw new \RuntimeException('Generated text was empty or exceeded the product field limits.');
                }
                $current = $this->products->findTranslations($productId)[$sourceLanguage] ?? null;
                if ($current === null || self::translationHash($current['name'], $current['description']) !== $hash) {
                    throw new \RuntimeException('The source changed during generation. Retry from the current text.');
                }
                $this->products->saveTranslationReview($productId, $target, $sourceLanguage, $hash, $candidateName, $candidateDescription, 'PENDING', null);
                $pending++;
            } catch (\Throwable $exception) {
                $message = $exception instanceof \RuntimeException ? $exception->getMessage() : 'Translation service failed. Retry later.';
                $this->products->saveTranslationReview($productId, $target, $sourceLanguage, $hash, null, null, 'FAILED', substr($message, 0, 255));
                $failed++;
            }
        }
        return ['success' => true, 'errors' => [], 'notice' => "{$pending} translation(s) pending administrator review; {$failed} failed. View the language cards for details."];
    }

    /** Promote one generated draft only after review and source validation. */
    public function reviewTranslation(int $productId, string $languageCode, bool $approve, int $administratorId): array
    {
        if (!in_array($languageCode, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported language.']];
        }
        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $languageCode, $approve, $administratorId): void {
                $review = $this->products->findTranslationReview($productId, $languageCode, true);
                if ($review === null || $review['status'] !== 'PENDING' || trim((string) $review['name']) === '' || trim((string) $review['description']) === '') {
                    throw new DomainException('There is no pending translation to review.');
                }
                if ($approve) {
                    $source = $this->products->findTranslations($productId)[$review['source_language_code']] ?? null;
                    if ($source === null || self::translationHash($source['name'], $source['description']) !== $review['source_hash']) {
                        throw new DomainException('The source text has changed. Generate this translation again.');
                    }
                    $this->products->upsertTranslation($productId, $languageCode, $review['name'], $review['description']);
                }
                $this->products->markTranslationReview($productId, $languageCode, $approve ? 'APPROVED' : 'REJECTED', $administratorId);
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    private static function translationHash(string $name, string $description): string
    {
        return hash('sha256', $name . "\0" . $description);
    }

    /**
     * Store a replacement main image and remove the old file after commit.
     *
     * The new file is written first. If the database update fails it is deleted;
     * if the update succeeds, the previous image is deleted. This avoids leaving
     * the database pointing at a file that was removed too early.
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, errors: array<string, string>}
     */
    public function uploadMainImage(int $productId, ?array $file): array
    {
        $stored = $this->images->store($file);
        if (!$stored['success']) {
            return ['success' => false, 'errors' => ['main_image' => (string) $stored['error']]];
        }
        if ($stored['path'] === null) {
            return ['success' => false, 'errors' => ['main_image' => 'Choose an image file to upload.']];
        }
        // Store a browser-usable relative path in the database, not a server path.
        $publicPath = $this->images->publicRelativePath($stored['path']);

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $previousPath = $this->transactions->run(function () use ($productId, $publicPath): ?string {
                $product = $this->products->lockCore($productId);
                if ($product === null) {
                    throw new DomainException('The product could not be found.');
                }
                $this->products->setMainImage($productId, $publicPath);

                return $product['main_image_path'] === null ? null : (string) $product['main_image_path'];
            });
        } catch (DomainException $exception) {
            $this->images->discard($stored['path']);

            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            $this->images->discard($stored['path']);

            throw $exception;
        }

        // The old row no longer points at this file. Delete it only after a
        // repository-wide reference check proves no other live record uses it.
        if ($previousPath !== null && !$this->products->imagePathInUse($previousPath)) {
            $this->images->discard($previousPath);
        }

        return ['success' => true, 'errors' => []];
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
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
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
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $applied = $this->readiness->applyChange(function () use ($productId, $colorId): ?string {
                $path = $this->products->findColorImagePath($productId, $colorId);
                $this->products->removeColor($productId, $colorId);

                return $path;
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['color' => $exception->getMessage()]];
        }
        $imagePath = $applied['result'];
        if ($imagePath !== null && !$this->products->imagePathInUse($imagePath)) {
            $this->images->discard($imagePath);
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * Store or replace the photo belonging to one assigned product color.
     * Cleanup follows the same new-file/database/old-file order as main images.
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, errors: array<string, string>}
     */
    public function uploadColorImage(int $productId, int $colorId, ?array $file): array
    {
        $stored = $this->images->store($file);
        if (!$stored['success']) {
            return ['success' => false, 'errors' => ['color_image' => (string) $stored['error']]];
        }
        if ($stored['path'] === null) {
            return ['success' => false, 'errors' => ['color_image' => 'Choose an image file to upload.']];
        }
        $publicPath = $this->images->publicRelativePath($stored['path']);

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $previousPath = $this->transactions->run(function () use ($productId, $colorId, $publicPath): ?string {
                if (!$this->products->colorAssigned($productId, $colorId)) {
                    throw new DomainException('Assign this color to the product before uploading its photo.');
                }
                $previous = $this->products->findColorImagePath($productId, $colorId);
                $this->products->setColorImage($productId, $colorId, $publicPath);

                return $previous;
            });
        } catch (DomainException $exception) {
            $this->images->discard($stored['path']);

            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            $this->images->discard($stored['path']);

            throw $exception;
        }

        if ($previousPath !== null && !$this->products->imagePathInUse($previousPath)) {
            $this->images->discard($previousPath);
        }

        return ['success' => true, 'errors' => []];
    }

    /** Assign one active scent, rejecting missing and duplicate relationships. */
    public function addScent(int $productId, int $scentId): array
    {
        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
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
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            // applyChange() reprices the set, then rejects a component that would leave
            // an active set unpriceable (for example a component with no cost yet).
            $applied = $this->readiness->applyChange(function () use ($setProductId, $componentProductId, $quantity): void {
                $set = $this->products->find($setProductId);
                if ($set === null) {
                    throw new DomainException('The set product could not be found.');
                }
                if ($set['category_slug'] !== self::SET_CATEGORY_SLUG) {
                    throw new DomainException('Only a product in the candle-sets category can have components.');
                }
                $component = $this->products->find($componentProductId);
                if ($component === null || (int) $component['is_active'] !== 1) {
                    throw new DomainException('Select an active product to add as a component.');
                }
                if ($component['category_slug'] === self::SET_CATEGORY_SLUG) {
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
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
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
     * Validate untrusted core-form input and produce database-ready values.
     *
     * The method returns `[clean values, field errors]`. It collects all errors
     * in one pass so the user can fix the entire form instead of one field per
     * submission.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validateCore(array $input, ?int $productId): array
    {
        $errors = [];
        // Local helper: safely obtain a trimmed string even when POST contains
        // a missing key or unexpected array value.
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        // Category selection controls several later conditional rules.
        $categoryId = filter_var($string('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = null;
        if ($categoryId === false) {
            $errors['category_id'] = 'Select a category.';
        } else {
            $category = $this->products->findCategory($categoryId);
            if ($category === null) {
                $errors['category_id'] = 'Select a valid category.';
            }
        }
        $categorySlug = $category['slug'] ?? null;
        $isSet = $categorySlug === self::SET_CATEGORY_SLUG;
        $isAccessory = $categorySlug === self::ACCESSORY_CATEGORY_SLUG;
        $isDiffuser = $categorySlug === self::DIFFUSER_CATEGORY_SLUG;
        $isBouquet = $categorySlug === 'bouquets';
        $isEventFavor = $categorySlug === 'event-favors';

        // A slug becomes the readable URL segment. The pattern allows groups of
        // lowercase letters/numbers separated by one hyphen.
        $slugRaw = strtolower($string('slug'));
        $slug = null;
        if ($slugRaw !== '') {
            // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
            if (strlen($slugRaw) > 180 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRaw) !== 1) {
                $errors['slug'] = 'Enter a slug using lowercase letters, numbers, and single hyphens only.';
            } elseif ($this->products->slugExists($slugRaw, $productId)) {
                $errors['slug'] = 'This slug is already used by another product.';
            } else {
                $slug = $slugRaw;
            }
        }

        // Candle sets deliberately have no product type; other categories require one.
        $productTypeRaw = $string('product_type_id');
        $productTypeId = null;
        if ($productTypeRaw !== '') {
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $productTypeId = filter_var($productTypeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productTypeId === false || !$this->products->activeProductTypeExists($productTypeId)) {
                $errors['product_type_id'] = 'Select a valid, active product type.';
                $productTypeId = null;
            }
        } elseif (!$isSet) {
            $errors['product_type_id'] = 'A product type is required for this category.';
        }
        if ($isSet) {
            $productTypeId = null;
        }

        // WHAT: selling_price is no longer accepted from the form.
        // WHY:  PricingService calculates it from costs; a crafted POST that
        //       includes selling_price is simply ignored.

        // Only accessories use direct stock quantity in this product model.
        $stockRaw = $string('stock_quantity');
        $stockQuantity = null;
        if ($isAccessory) {
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $stockQuantity = filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($stockQuantity === false) {
                $errors['stock_quantity'] = 'Accessories require a stock quantity of zero or more.';
                $stockQuantity = null;
            }
        } elseif ($stockRaw !== '') {
            $errors['stock_quantity'] = 'Stock quantity only applies to accessories.';
        }

        $candleCountRaw = $string('candle_count');
        $candleCount = null;
        if ($candleCountRaw !== '') {
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $candleCount = filter_var($candleCountRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($candleCount === false) {
                $errors['candle_count'] = 'Enter a whole candle count greater than zero, or leave it blank.';
                $candleCount = null;
            }
        }

        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $minimumQuantity = filter_var($string('minimum_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1]]);
        if ($minimumQuantity === false) {
            $errors['minimum_quantity'] = 'Enter a whole minimum quantity of at least 1.';
            $minimumQuantity = 1;
        }
        if ($isEventFavor && $minimumQuantity < 10) {
            $errors['minimum_quantity'] = 'Event favors require a minimum order quantity of at least 10.';
        }

        $purchaseMode = strtoupper($string('purchase_mode'));
        if ($purchaseMode === '') {
            $purchaseMode = 'CART';
        } elseif (!in_array($purchaseMode, self::PURCHASE_MODES, true)) {
            $errors['purchase_mode'] = 'Select a valid purchase mode.';
            $purchaseMode = 'CART';
        }

        // Bouquets and event favors require discussion before purchase. Never
        // trust a crafted POST that attempts to expose them as cart products.
        if ($isBouquet || $isEventFavor) {
            $purchaseMode = 'REQUEST_ONLY';
        }

        $referenceRaw = $string('pricing_reference_product_id');
        $referenceId = null;
        if ($referenceRaw !== '' && $isDiffuser) {
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $referenceId = filter_var($referenceRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $referenceProduct = $referenceId === false ? null : $this->products->find($referenceId);
            if ($referenceId === false || $referenceId === $productId || $referenceProduct === null
                || $referenceProduct['category_slug'] !== self::CANDLE_CATEGORY_SLUG
            ) {
                $errors['pricing_reference_product_id'] = 'Select a valid candle product to reference, or leave it blank.';
                $referenceId = null;
            }
        } elseif ($referenceRaw !== '') {
            $errors['pricing_reference_product_id'] = 'A pricing reference only applies to home diffusers.';
        }

        // Checkbox fields are submitted as "1" only when checked. Convert them
        // to integers because the database columns store 1/0 flags.
        $isMadeToOrder = ($input['is_made_to_order'] ?? '') === '1';
        $canPurchaseAlone = ($input['can_purchase_alone'] ?? '') === '1';
        if ($isAccessory) {
            $canPurchaseAlone = false;
        }
        if (in_array($categorySlug, [self::CANDLE_CATEGORY_SLUG, 'bouquets', 'event-favors', self::DIFFUSER_CATEGORY_SLUG], true)) {
            $isMadeToOrder = true;
        }

        $core = [
            'category_id' => $categoryId === false ? null : $categoryId,
            'product_type_id' => $productTypeId,
            'pricing_reference_product_id' => $referenceId,
            'slug' => $slug,
            'stock_quantity' => $stockQuantity,
            'candle_count' => $candleCount,
            'minimum_quantity' => $minimumQuantity,
            'purchase_mode' => $purchaseMode,
            'is_made_to_order' => $isMadeToOrder ? 1 : 0,
            'can_purchase_alone' => $canPurchaseAlone ? 1 : 0,
        ];

        return [$core, $errors];
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
        $search = trim($this->queryString($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $categoryText = $this->queryString($query, 'category', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $category = $categoryText === '' ? null : filter_var($categoryText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($category === false) {
            $category = null;
            $adjusted = true;
        }
        $typeText = $this->queryString($query, 'type', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $type = $typeText === '' ? null : filter_var($typeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === false) {
            $type = null;
            $adjusted = true;
        }
        $active = $this->queryString($query, 'active', $adjusted);
        if (!in_array($active, ['', '0', '1'], true)) {
            $active = '';
            $adjusted = true;
        }
        $purchaseMode = strtoupper($this->queryString($query, 'purchase_mode', $adjusted));
        if ($purchaseMode !== '' && !in_array($purchaseMode, self::PURCHASE_MODES, true)) {
            $purchaseMode = '';
            $adjusted = true;
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
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

    /**
     * Read one query value only when it is a string.
     * `$adjusted` is passed by reference (`&`), so changing it here changes the
     * caller's variable and lets the UI report normalized invalid filters.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }

    /** Count user-visible UTF-8 characters, falling back when mbstring is absent. */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
