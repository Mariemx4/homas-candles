<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CandlePricingRepository;
use DomainException;

/**
 * Individual candle prices: the calculated suggestion, the owner's selling price, and the
 * approval that moves one to the other.
 *
 * Selling prices are only ever written by an explicit call here: applySuggestion() (the owner
 * approves a calculated price) or setManualPrice() (the owner types a price). Every change is
 * recorded in product_price_history. A selling price can never be below the minimum markup.
 * Bouquets, gift sets and other products are not handled here.
 */
final class CandlePriceService
{
    /** Quantities shown in the quantity table on the admin page. */
    private const QUANTITY_TABLE = [1, 2, 5, 10, 25, 50, 100];

    public function __construct(
        private readonly CandlePricingRepository $repository,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * Everything the admin page shows for one candle: its calculation for a quantity, the current
     * selling price and the difference. Returns null for a product that is not a candle.
     *
     * @return array<string, mixed>|null
     */
    public function panel(int $productId, int $quantity = 1): ?array
    {
        $product = $this->repository->product($productId);
        if ($product === null || $product['category_slug'] !== 'candles') {
            return null;
        }

        $current = $product['selling_price'] === null ? null : Money::parseMillis((string) $product['selling_price']);
        $calculation = $this->calculation($product, $quantity);
        $suggested = $calculation['calc']['retail_millis'] ?? null;

        return [
            'product_id' => $productId,
            'weight_grams' => $product['candle_weight_grams'] === null ? null : (string) $product['candle_weight_grams'],
            'quantity' => $quantity,
            'current_price_millis' => $current,
            'selling_price_source' => $product['selling_price_source'],
            'suggested_price_millis' => $suggested,
            'difference_millis' => $current === null || $suggested === null ? null : $current - $suggested,
            'issue' => $calculation['issue'],
            'calc' => $calculation['calc'],
            'quantity_table' => array_map(
                fn (int $quantity): array => ['quantity' => $quantity, 'calc' => $this->calculation($product, $quantity)['calc']],
                self::QUANTITY_TABLE
            ),
            'history' => $this->repository->priceHistory($productId),
        ];
    }

    /**
     * The candle's retail price and minimum for its weight, or the reason it cannot be priced yet.
     *
     * @return array{calc: ?array<string, mixed>, issue: ?string}
     */
    public function calculation(array $product, int $quantity = 1): array
    {
        if ($product['candle_weight_grams'] === null) {
            return ['calc' => null, 'issue' => 'no_weight'];
        }
        $recipe = $this->repository->recipe((int) $product['id']);
        if ($recipe === []) {
            return ['calc' => null, 'issue' => 'no_recipe'];
        }

        $lines = [];
        foreach ($recipe as $row) {
            if ($row['unit_cost'] === null || (float) $row['unit_cost'] < 0) {
                return ['calc' => null, 'issue' => 'no_unit_cost'];
            }
            $lines[] = [
                'name' => (string) $row['name'],
                'grams_milli' => (int) round((float) $row['quantity_required'] * 1000),
                'cost_nano_per_milligram' => (int) round((float) $row['unit_cost'] * 1_000_000),
                'fragrance' => $row['unit'] === 'ml',
            ];
        }

        $calc = CandlePricing::price(
            (int) round((float) $product['candle_weight_grams'] * 1000),
            $lines,
            $quantity,
            $this->calculationSettings()
        );

        // Name and fragrance flag for each material line, in the same order as the recipe.
        foreach ($calc['materials'] as $index => $material) {
            $calc['materials'][$index] += ['name' => $lines[$index]['name'], 'fragrance' => $lines[$index]['fragrance']];
        }

        return ['calc' => $calc, 'issue' => null];
    }

    /** Unit price for any quantity of one candle (same rule as the admin page). Null if not priceable. */
    public function unitPriceFor(int $productId, int $quantity): ?int
    {
        $product = $this->repository->product($productId);
        if ($product === null || $product['category_slug'] !== 'candles') {
            return null;
        }

        return $this->calculation($product, $quantity)['calc']['unit_price_millis'] ?? null;
    }

    /**
     * Owner approves the calculated retail price for one candle. Refuses when there is no
     * calculation yet, so an unpriced candle is never given a made-up price.
     */
    public function applySuggestion(int $productId, ?int $administratorId): array
    {
        return $this->transactions->run(function () use ($productId, $administratorId): array {
            $product = $this->repository->product($productId, true);
            if ($product === null || $product['category_slug'] !== 'candles') {
                throw new DomainException('Only individual candles have calculated prices.');
            }
            $calculation = $this->calculation($product);
            if ($calculation['calc'] === null) {
                throw new DomainException('This candle cannot be priced yet: ' . $this->issueLabel($calculation['issue']) . '.');
            }
            $price = $calculation['calc']['retail_millis'];
            $this->write($product, $price, 'calculated', $administratorId);

            return ['selling_price_millis' => $price];
        });
    }

    /** Approves the calculated price for several candles at once. Unpriceable candles are skipped and reported. */
    public function applySuggestions(array $productIds, ?int $administratorId): array
    {
        $applied = 0;
        $skipped = [];
        foreach (array_unique(array_map('intval', $productIds)) as $id) {
            try {
                $this->applySuggestion($id, $administratorId);
                $applied++;
            } catch (DomainException $error) {
                $skipped[$id] = $error->getMessage();
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Owner types a selling price. It must be a positive amount and not below the candle's
     * minimum (40% markup on known cost).
     */
    public function setManualPrice(int $productId, string $priceTnd, ?int $administratorId): array
    {
        $millis = Money::parseMillis(trim($priceTnd));
        if ($millis === null || $millis <= 0) {
            throw new DomainException('Enter a selling price greater than zero.');
        }

        return $this->transactions->run(function () use ($productId, $millis, $administratorId): array {
            $product = $this->repository->product($productId, true);
            if ($product === null || $product['category_slug'] !== 'candles') {
                throw new DomainException('Only individual candles have calculated prices.');
            }
            $calculation = $this->calculation($product);
            if ($calculation['calc'] !== null && $millis < $calculation['calc']['minimum_millis']) {
                throw new DomainException(
                    'The price is below the minimum of ' . PricingService::formatMoney($calculation['calc']['minimum_millis']) . ' TND (40% over known cost).'
                );
            }
            $this->write($product, $millis, 'manual', $administratorId);

            return ['selling_price_millis' => $millis];
        });
    }

    /** Saves the finished candle weight in grams (blank clears it). Changing weight never changes a price. */
    public function saveWeight(int $productId, string $gramsInput): void
    {
        $value = trim($gramsInput);
        if ($value === '') {
            $this->repository->setCandleWeight($productId, null);

            return;
        }
        if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $value) || (float) $value <= 0) {
            throw new DomainException('Enter the candle weight in grams, for example 75 or 37.5.');
        }
        $this->repository->setCandleWeight($productId, $value);
    }

    /**
     * Saves the owner's pricing settings after range checks. Settings change suggestions only:
     * no selling price changes until the owner approves it.
     *
     * @param array<string, mixed> $input
     */
    public function saveSettings(array $input): void
    {
        // key => [min, max, label]; the minimum is inclusive, the maximum too.
        $rules = [
            'retail_multiplier' => [1.0, 10.0, 'Retail multiplier'],
            'candle_minimum_markup_percent' => [0.0, 200.0, 'Minimum markup (%)'],
            'quantity_price_exponent' => [0.01, 1.0, 'Quantity exponent'],
            'retail_rounding_tnd' => [0.0, 10.0, 'Retail rounding (TND)'],
            'quantity_rounding_tnd' => [0.001, 1.0, 'Quantity rounding (TND)'],
            'packaging_per_candle_tnd' => [0.0, 100.0, 'Packaging per candle (TND)'],
            'density_g_per_ml' => [0.1, 5.0, 'Fragrance density (g/ml)'],
        ];
        $values = [];
        $errors = [];
        foreach ($rules as $key => [$min, $max, $label]) {
            $raw = trim((string) ($input[$key] ?? ''));
            if (!preg_match('/^\d{1,4}(\.\d{1,4})?$/', $raw) || (float) $raw < $min || (float) $raw > $max) {
                $errors[] = "$label must be a number from $min to $max.";
                continue;
            }
            $values[$key] = $raw;
        }
        $confirmed = (string) ($input['fragrance_density_confirmed'] ?? '0');
        if ($confirmed !== '0' && $confirmed !== '1') {
            $errors[] = 'Fragrance density confirmed must be yes or no.';
        }
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }
        $values['fragrance_density_confirmed'] = $confirmed;

        $this->transactions->run(function () use ($values): void {
            foreach ($values as $key => $value) {
                if (!$this->repository->saveSetting($key, $value)) {
                    throw new DomainException('The pricing setting ' . $key . ' is missing.');
                }
            }
        });
    }

    /** Candle ids with their current price and suggestion, for the comparison table. */
    public function comparison(int $quantity = 1): array
    {
        $rows = [];
        foreach ($this->repository->candleProductIds() as $id) {
            $rows[] = $this->panel($id, $quantity);
        }

        return $rows;
    }

    /** The settings in the shape CandlePricing expects (basis points, millimes, plain numbers). */
    private function calculationSettings(): array
    {
        $raw = $this->settings();

        return [
            'retail_multiplier_bp' => (int) round($raw['retail_multiplier'] * 10000),
            'minimum_markup_bp' => (int) round($raw['candle_minimum_markup_percent'] * 100),
            'quantity_exponent' => $raw['quantity_price_exponent'],
            'retail_step_millis' => (int) round($raw['retail_rounding_tnd'] * 1000),
            'quantity_step_millis' => (int) round($raw['quantity_rounding_tnd'] * 1000),
            'packaging_millis' => (int) round($raw['packaging_per_candle_tnd'] * 1000),
            'density_g_per_ml' => $raw['density_g_per_ml'],
            'fragrance_density_confirmed' => $raw['fragrance_density_confirmed'] >= 1,
        ];
    }

    /** @return array<string, float> */
    public function settings(): array
    {
        $raw = $this->repository->settings();
        foreach (['retail_multiplier', 'candle_minimum_markup_percent', 'quantity_price_exponent', 'retail_rounding_tnd', 'quantity_rounding_tnd', 'packaging_per_candle_tnd', 'density_g_per_ml', 'fragrance_density_confirmed'] as $key) {
            if (!array_key_exists($key, $raw)) {
                throw new DomainException("The pricing setting $key is missing.");
            }
        }

        return $raw;
    }

    /** @param array<string, mixed> $product */
    private function write(array $product, int $newMillis, string $source, ?int $administratorId): void
    {
        $previous = $product['selling_price'] === null ? null : (string) $product['selling_price'];
        $newPrice = PricingService::formatMoney($newMillis);
        $id = (int) $product['id'];
        if ($previous !== null && Money::parseMillis($previous) === $newMillis && $product['selling_price_source'] === $source) {
            return;
        }
        $this->repository->setSellingPrice($id, $newPrice, $source);
        $this->repository->recordPriceChange($id, $previous, $product['selling_price_source'], $newPrice, $source, $administratorId);
    }

    private function issueLabel(?string $issue): string
    {
        return match ($issue) {
            'no_weight' => 'the candle weight is not set',
            'no_recipe' => 'it has no recipe',
            'no_unit_cost' => 'a material has no unit cost',
            default => 'the calculation is incomplete',
        };
    }
}
