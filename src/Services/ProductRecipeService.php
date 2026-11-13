<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRecipeRepository;
use DomainException;

final class ProductRecipeService
{
    public const RECIPE_TYPES = ['ACTUAL', 'CANDLE_EQUIVALENT'];


    public function __construct(
        private readonly ProductRecipeRepository $recipes,
        private readonly PricingService $pricing,
        private readonly ProductReadinessService $readiness
    ) {
    }


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
        $profit = $sellingPrice === null ? null : Money::signedDifference((string) $sellingPrice, $realCost);

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


    private function validateLine(string $recipeType, string $rawQuantity): array
    {
        $errors = [];
        if (!in_array($recipeType, self::RECIPE_TYPES, true)) {
            $errors['recipe_type'] = 'Select a valid recipe type.';
        }
        $quantityMillis = Money::parseMillis($rawQuantity);
        if ($quantityMillis === null || $quantityMillis <= 0) {
            $errors['quantity_required'] = 'Enter a quantity greater than zero, with up to three decimals.';
            $quantityMillis = 0;
        }

        return [$errors, Money::formatSignedMillis($quantityMillis)];
    }





}
