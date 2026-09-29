<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Product Recipe Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\ProductRecipeRepository;
use DomainException;

final class ProductRecipeService
{
    public const RECIPE_TYPES = ['ACTUAL', 'CANDLE_EQUIVALENT'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly ProductRecipeRepository $recipes,
        private readonly PricingService $pricing,
        private readonly ProductReadinessService $readiness
    ) {
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $productId): ?array
    {
        $product = $this->recipes->findProduct($productId);
        if ($product === null) {
            return null;
        }

        $actual = $this->recipes->findRecipe($productId, 'ACTUAL');
        $candleEquivalent = $this->recipes->findRecipe($productId, 'CANDLE_EQUIVALENT');
        $actualCost = $this->recipes->totalCost($productId, 'ACTUAL');
        $candleEquivalentCost = $this->recipes->totalCost($productId, 'CANDLE_EQUIVALENT');
        $sellingPrice = $product['selling_price'];
        // WHAT: explain how PricingService derives this product's price.
        // WHY:  the recipe screen is where cost inputs change, so it shows the
        //       rule, the calculation, and anything still blocking a price.
        $pricing = $this->pricing->quote($productId);
        // Profit uses the product's real cost (for sets: all components; for
        // diffusers: the actual recipe, never the candle-equivalent one).
        $realCost = $pricing !== null && $pricing['actual_cost_millis'] !== null
            ? PricingService::formatMoney($pricing['actual_cost_millis'])
            : $actualCost;
        $profit = $sellingPrice === null ? null : $this->subtractMoney((string) $sellingPrice, $realCost);

        return [
            'product' => $product,
            'actual' => $actual,
            'candleEquivalent' => $candleEquivalent,
            'actualCost' => $actualCost,
            'candleEquivalentCost' => $candleEquivalentCost,
            'profit' => $profit,
            'realCost' => $realCost,
            'pricing' => $pricing,
            'materialOptions' => $this->recipes->findRecipeEligibleMaterials(),
        ];
    }

    /**
     * FUNCTION PURPOSE: Add line after enforcing the relevant rules.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function addLine(int $productId, string $recipeType, int $materialId, string $rawQuantity): array
    {
        [$errors, $quantity] = $this->validateLine($recipeType, $rawQuantity);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            if ($this->recipes->findProduct($productId) === null) {
                throw new DomainException('The product could not be found.');
            }
            $material = $this->recipes->findMaterialForRecipe($materialId);
            if ($material === null || (int) $material['is_active'] !== 1) {
                throw new DomainException('Select an active material.');
            }
            if ((int) $material['is_recipe_input'] !== 1) {
                throw new DomainException('This material\'s type is not permitted in a product recipe.');
            }
            if ($this->recipes->recipeExists($productId, $materialId, $recipeType)) {
                throw new DomainException('This material already has a recipe line of this type. Edit the existing line instead.');
            }
            // WHAT: the recipe change, repricing and readiness check commit together.
            // WHY:  a new line can change prices of this product and of sets and
            //       diffusers that depend on it (ProductReadinessService::applyChange).
            $applied = $this->readiness->applyChange(
                fn () => $this->recipes->insert($productId, $materialId, $recipeType, $quantity),
                [$productId]
            );
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['recipe' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * FUNCTION PURPOSE: Update the stored line values.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function updateLine(int $productId, string $recipeType, int $materialId, string $rawQuantity): array
    {
        [$errors, $quantity] = $this->validateLine($recipeType, $rawQuantity);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        if (!$this->recipes->recipeExists($productId, $materialId, $recipeType)) {
            return ['success' => false, 'errors' => ['recipe' => 'This recipe line no longer exists.']];
        }
        try {
            $applied = $this->readiness->applyChange(
                fn () => $this->recipes->updateQuantity($productId, $materialId, $recipeType, $quantity),
                [$productId]
            );
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['recipe' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * FUNCTION PURPOSE: Remove line and any relationship-specific state it owns.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function removeLine(int $productId, string $recipeType, int $materialId): array
    {
        if (!in_array($recipeType, self::RECIPE_TYPES, true)) {
            return ['success' => false, 'errors' => ['recipe' => 'Unsupported recipe type.']];
        }
        // WHAT: removing an active product's last actual recipe line is rejected
        //       (it would lose its cost and calculated price); sets or diffusers
        //       that lose their price because of it go back to draft.
        try {
            $applied = $this->readiness->applyChange(
                fn () => $this->recipes->remove($productId, $materialId, $recipeType),
                [$productId]
            );
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['recipe' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * FUNCTION PURPOSE: Check line against the application rules and return usable values or errors.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function validateLine(string $recipeType, string $rawQuantity): array
    {
        $errors = [];
        if (!in_array($recipeType, self::RECIPE_TYPES, true)) {
            $errors['recipe_type'] = 'Select a valid recipe type.';
        }
        $quantityMillis = $this->parseMoney($rawQuantity);
        if ($quantityMillis === null || $quantityMillis <= 0) {
            $errors['quantity_required'] = 'Enter a quantity greater than zero, with up to three decimals.';
            $quantityMillis = 0;
        }

        return [$errors, $this->millisToMoney($quantityMillis)];
    }

    /**
     * FUNCTION PURPOSE: Subtract money using exact integer money arithmetic.
     */
    private function subtractMoney(string $a, string $b): string
    {
        return $this->millisToMoney($this->moneyToMillis($a) - $this->moneyToMillis($b));
    }

    /**
     * FUNCTION PURPOSE: Parse money text without accepting unsupported formats.
     */
    private function parseMoney(string $value): ?int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) explode('.', $value)[0] * 1000) + (int) str_pad($matches[1] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Convert to millis using integer thousandths to avoid floating-point errors.
     */
    private function moneyToMillis(string $amount): int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,3}))?$/', $amount, $matches) !== 1) {
            return 0;
        }
        $sign = $matches[1] === '-' ? -1 : 1;

        return $sign * (((int) $matches[2] * 1000) + (int) str_pad($matches[3] ?? '', 3, '0'));
    }

    /**
     * FUNCTION PURPOSE: Convert to money between integer thousandths and a money string.
     */
    private function millisToMoney(int $millis): string
    {
        $sign = $millis < 0 ? '-' : '';
        $millis = abs($millis);

        return $sign . intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
