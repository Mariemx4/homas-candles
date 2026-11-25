<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\PricingRepository;
use Closure;
use DomainException;

// WHAT: implements docs/database-requirements.md "BASE-PRICE RULES":
//         Candles          selling price = actual cost × 2
//         Candle sets      (non-accessory component costs × 2) + accessory costs
//         Accessories      original purchase cost, no markup
//         Bouquets         base price = component total cost × 2
//         Event favors     batch = unit cost × quantity × tier multiplier
//                          (10–19 × 1.75, 20–39 × 1.5, 40+ × 1.3; fewer than 10 refused)
//         Home diffusers   the equivalent candle's price, else candle-equivalent cost × 2;
//                          profit still uses the diffuser's own actual recipe cost
// WHY:  prices used to be typed by hand and silently drifted from costs. With
//       one service owning the rules, a material-cost change reprices every
//       affected product in the same transaction, and nowhere else in the
//       codebase decides what a product costs the customer.
//
//       Historical prices are never touched: orders keep their own frozen
//       order_items snapshots (migrations 005/011/015). Only the current
//       catalogue price moves, which is what the requirements ask for
//       ("Existing order prices never change silently").
final class PricingService
{
    /** The quantity-price ranges configured for one product. */
    public function quantityPrices(int $id): array
    {
        return $this->pricing->quantityPrices($id);
    }
    /** All promotion codes, for the pricing settings page. */
    public function promotions(): array
    {
        return $this->pricing->promotions();
    }

    /**
     * Adds a lower unit price for a quantity range of one product.
     *
     * The price may not exceed the catalogue price and ranges may not overlap.
     * Event favors are refused: they use fixed cost multipliers instead.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function addQuantityPrice(int $productId, array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $minimum = filter_var($text('minimum_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $maximum = $text('maximum_quantity') === '' ? null : filter_var($text('maximum_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $price = Money::parseMillis($text('unit_price'));
        if ($minimum === false || $maximum === false || ($maximum !== null && $maximum < $minimum) || $price === null || $price <= 0) {
            return ['success' => false, 'errors' => ['quantity_prices' => 'Enter a valid quantity range and a positive TND unit price.']];
        }
        return $this->transactions->run(function () use ($productId, $minimum, $maximum, $price, $input): array {
            $product = $this->pricing->lockProduct($productId);
            if ($product === null || $product['price_rule'] === 'EVENT_FAVOR_TIER') {
                return ['success' => false, 'errors' => ['quantity_prices' => 'Event favors use their established cost multipliers; other products can use configured quantity prices.']];
            }
            $base = Money::parseMillis((string) $product['selling_price']);
            if ($base === null || $price > $base) {
                return ['success' => false, 'errors' => ['quantity_prices' => 'A quantity price must not exceed the current catalogue price.']];
            }
            foreach ($this->pricing->quantityPrices($productId) as $tier) {
                if ((int) $tier['is_active'] === 1 && $minimum <= (int) ($tier['maximum_quantity'] ?? 1000000)
                    && (int) $tier['minimum_quantity'] <= ($maximum ?? 1000000)) {
                    return ['success' => false, 'errors' => ['quantity_prices' => 'Quantity price ranges must not overlap.']];
                }
            }
            $this->pricing->insertQuantityPrice($productId, ['minimum' => $minimum, 'maximum' => $maximum,
                'price' => Money::formatMillis($price), 'active' => 1]);
            return ['success' => true, 'errors' => []];
        });
    }

    /**
     * Removes one quantity-price range from a product.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function removeQuantityPrice(int $product, int $tier): array
    {
        return $this->transactions->run(function () use ($product, $tier): array {
            $this->pricing->lockProduct($product);
            $this->pricing->removeQuantityPrice($product, $tier);
            return ['success' => true, 'errors' => []];
        });
    }

    /**
     * Creates or updates a promotion code (percentage up to 100 % or a fixed TND amount,
     * optional dates and minimum order).
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function savePromotion(array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $code = strtoupper($text('code'));
        $type = $text('discount_type');
        $value = Money::parseMillis($text('discount_value'));
        $minimum = Money::parseMillis($text('minimum_subtotal') ?: '0');
        $starts = $text('valid_from');
        $ends = $text('valid_until');
        $dateValid = static fn (string $date): bool => $date === '' || (($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code) !== 1 || !in_array($type, ['PERCENTAGE','FIXED_TND'], true)
            || $value === null || $value <= 0 || ($type === 'PERCENTAGE' && $value > 100000) || $minimum === null
            || !$dateValid($starts) || !$dateValid($ends) || ($starts !== '' && $ends !== '' && $starts > $ends)
            || !in_array($text('is_active'), ['0','1'], true)) {
            return ['success' => false, 'errors' => ['promotion' => 'Enter a valid code, discount, active state, minimum amount, and date range.']];
        }
        $this->transactions->run(fn () => $this->pricing->savePromotion(['code' => $code, 'type' => $type,
            'value' => Money::formatMillis($value), 'active' => (int) $text('is_active'), 'starts' => $starts ?: null,
            'ends' => $ends ?: null, 'minimum' => Money::formatMillis($minimum)]));
        return ['success' => true, 'errors' => []];
    }
    public const RULES = [
        'CANDLE_COST_X2', 'SET_COMPONENTS', 'ACCESSORY_PURCHASE_COST', 'BOUQUET_BASE_COST_X2',
        'EVENT_FAVOR_TIER', 'DIFFUSER_REFERENCE_CANDLE', 'DIFFUSER_CANDLE_EQUIVALENT',
    ];

    public const RULE_LABELS = [
        'CANDLE_COST_X2' => 'Candle: actual cost × 2',
        'SET_COMPONENTS' => 'Candle set: component costs × 2, accessories at cost',
        'ACCESSORY_PURCHASE_COST' => 'Accessory: purchase cost, no markup',
        'BOUQUET_BASE_COST_X2' => 'Bouquet base price: actual cost × 2',
        'EVENT_FAVOR_TIER' => 'Event favor: unit cost × quantity tier',
        'DIFFUSER_REFERENCE_CANDLE' => 'Diffuser: same price as its equivalent candle',
        'DIFFUSER_CANDLE_EQUIVALENT' => 'Diffuser: candle-equivalent cost × 2',
    ];

    public const EVENT_FAVOR_MINIMUM = 10;

    // Tier multipliers in percent, checked from the largest batch down.
    private const EVENT_FAVOR_TIERS = [
        ['minimum' => 40, 'percent' => 130, 'label' => '40 or more'],
        ['minimum' => 20, 'percent' => 150, 'label' => '20–39'],
        ['minimum' => 10, 'percent' => 175, 'label' => '10–19'],
    ];

    private readonly Closure $clock;


    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly TransactionManager $transactions,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null
            ? static fn (): string => date('Y-m-d H:i:s')
            : Closure::fromCallable($clock);
    }


    /**
     * Recalculates every catalogue price and cost from the pricing rules and saves the changes.
     *
     * Runs in the caller's transaction. A product keeps its price_calculated_at
     * while its price and rule are unchanged; products that cannot be priced are
     * saved with their pricing issue. Order snapshots are never touched.
     *
     * @return array{updated: int, unpriced: array<int, string>} product ID => reason for each unpriced product
     */
    public function recalculateAll(): array
    {
        return $this->transactions->run(function (): array {
            $products = $this->pricing->lockProducts();
            $results = $this->calculate($products, $this->pricing->recipeLines(), $this->pricing->setComponents());
            $now = ($this->clock)();
            $updated = 0;
            $unpriced = [];

            foreach ($products as $product) {
                $id = (int) $product['id'];
                $result = $results[$id];
                $price = $result['selling_price_millis'] === null ? null : self::formatMoney($result['selling_price_millis']);
                $cost = $result['actual_cost_millis'] === null ? null : self::formatMoney($result['actual_cost_millis']);
                $isCandle = ($product['category_slug'] ?? null) === CatalogueCategory::CANDLES;
                if ($price === null && !$isCandle) {
                    $unpriced[$id] = (string) $result['issue'];
                }

                if ($isCandle) {
                    // Individual candles: the suggestion comes from CandlePriceService and the owner's
                    // selling price is never written here, so a manual price is never overwritten.
                    $next = [
                        'selling_price' => null,
                        'actual_cost' => $cost,
                        'price_rule' => null,
                        'price_calculated_at' => null,
                        'pricing_issue' => $result['issue'],
                    ];
                    $unchanged = self::sameMoney($product['actual_cost'], $cost)
                        && $product['price_rule'] === null
                        && $product['price_calculated_at'] === null
                        && $product['pricing_issue'] === $next['pricing_issue'];
                    if ($unchanged) {
                        continue;
                    }
                } else {
                    // Other products keep their documented rules (bouquets, sets and favors are unchanged).
                    $unchanged = $price !== null
                        && self::sameMoney($product['selling_price'], $price)
                        && $product['price_rule'] === $result['rule']
                        && $product['price_calculated_at'] !== null;
                    $next = [
                        'selling_price' => $price,
                        'actual_cost' => $cost,
                        'price_rule' => $result['rule'],
                        'price_calculated_at' => $price === null ? null : ($unchanged ? (string) $product['price_calculated_at'] : $now),
                        'pricing_issue' => $result['issue'],
                    ];
                    if (($price === null || self::sameMoney($product['selling_price'], $price))
                        && self::sameMoney($product['actual_cost'], $cost)
                        && $product['price_rule'] === $next['price_rule']
                        && ($product['price_calculated_at'] === null ? null : (string) $product['price_calculated_at']) === $next['price_calculated_at']
                        && $product['pricing_issue'] === $next['pricing_issue']
                    ) {
                        continue;
                    }
                }
                $this->pricing->updatePricing($id, $next);
                $updated++;
            }

            return ['updated' => $updated, 'unpriced' => $unpriced];
        });
    }


    /**
     * Runs a change (e.g. a material cost edit) and reprices every product in the same transaction.
     *
     * @param callable(): mixed $change
     * @return mixed the change's own result
     */
    public function repriceAfter(callable $change): mixed
    {
        return $this->transactions->run(function () use ($change): mixed {
            $result = $change();
            $this->recalculateAll();

            return $result;
        });
    }


    /**
     * Read-only price breakdown for one product, for its admin page (no rows are locked).
     *
     * @return array<string, mixed>|null selling/cost millis, rule and its label, explanation, event-favor tiers; null for an unknown product
     */
    public function quote(int $productId): ?array
    {
        // Read-only: no row locks, so opening an admin page never blocks a save.
        $products = $this->pricing->lockProducts(false);
        $results = $this->calculate(
            $products,
            $this->pricing->recipeLines(false),
            $this->pricing->setComponents(false)
        );
        if (!isset($results[$productId])) {
            return null;
        }
        $quote = $results[$productId];
        // Individual candles are priced from their weight and approved by the owner (CandlePriceService),
        // so the old cost rule is not offered as their price here.
        $category = null;
        foreach ($products as $row) {
            if ((int) $row['id'] === $productId) {
                $category = $row['category_slug'] ?? null;
            }
        }
        if ($category === CatalogueCategory::CANDLES) {
            $quote['selling_price_millis'] = null;
            $quote['rule'] = null;
            $quote['issue'] = 'Individual candles are priced from their weight; approve or set the price in the Price section.';
            $quote['explanation'] = null;
        }
        $quote['rule_label'] = $quote['rule'] === null ? null : self::RULE_LABELS[$quote['rule']];
        $quote['event_favor_tiers'] = $quote['rule'] === 'EVENT_FAVOR_TIER' && $quote['actual_cost_millis'] !== null
            ? self::eventFavorTiers($quote['actual_cost_millis'])
            : [];

        return $quote;
    }


    /**
     * Applies the pricing rules (see the class comment) to the given rows without saving.
     *
     * Candles are priced before diffusers so a diffuser can reuse its reference
     * candle's price. Recipe costs are summed exactly and rounded half up once.
     *
     * @return array<int, array{selling_price_millis: ?int, actual_cost_millis: ?int, rule: ?string, issue: ?string, explanation: mixed}> keyed by product ID
     */
    public function calculate(array $products, array $recipeLines, array $components): array
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product['id']] = $product;
        }

        // WHAT: recipe cost per product and recipe type.
        // WHY:  quantity (3 decimals) × unit cost (3 decimals) is exact in
        //       millionths; the sum is rounded half-up to millis once, which is
        //       what ROUND(SUM(quantity × unit_cost), 3) does in the recipe screen.
        $micro = [];
        foreach ($recipeLines as $line) {
            $key = (int) $line['product_id'];
            $type = (string) $line['recipe_type'];
            $micro[$key][$type] = ($micro[$key][$type] ?? 0)
                + self::toMillis($line['quantity_required']) * self::toMillis($line['unit_cost']);
        }
        $recipeCost = static fn (int $id, string $type): ?int => isset($micro[$id][$type])
            ? intdiv($micro[$id][$type] + 500, 1000)
            : null;

        $componentsBySet = [];
        foreach ($components as $row) {
            $componentsBySet[(int) $row['set_product_id']][] = [
                'id' => (int) $row['component_product_id'],
                'quantity' => max(1, (int) $row['quantity']),
            ];
        }

        // Actual cost, memoised; sets sum their components' costs. $visiting
        // stops a malformed set that (indirectly) contains itself.
        $costs = [];
        $visiting = [];
        $actualCost = function (int $id) use (&$actualCost, &$costs, &$visiting, $byId, $componentsBySet, $recipeCost): ?int {
            if (array_key_exists($id, $costs)) {
                return $costs[$id];
            }
            if (isset($visiting[$id]) || !isset($byId[$id])) {
                return null;
            }
            $visiting[$id] = true;
            $own = $recipeCost($id, 'ACTUAL');
            if (($byId[$id]['category_slug'] ?? null) === CatalogueCategory::SETS) {
                $total = $own ?? 0;
                foreach ($componentsBySet[$id] ?? [] as $component) {
                    $componentCost = $actualCost($component['id']);
                    if ($componentCost === null) {
                        $total = null;
                        break;
                    }
                    $total += $componentCost * $component['quantity'];
                }
                $own = ($componentsBySet[$id] ?? []) === [] ? null : $total;
            }
            unset($visiting[$id]);

            return $costs[$id] = $own;
        };

        $results = [];
        // Candles first, so a diffuser can reuse its reference candle's result.
        $order = array_keys($byId);
        usort($order, static fn (int $a, int $b): int => (($byId[$a]['category_slug'] ?? '') === CatalogueCategory::DIFFUSERS) <=> (($byId[$b]['category_slug'] ?? '') === CatalogueCategory::DIFFUSERS));
        foreach ($order as $id) {
            $results[$id] = $this->priceOne($id, $byId, $componentsBySet, $actualCost, $recipeCost, $results);
        }
        ksort($results);

        return $results;
    }


    /**
     * Price of a batch of event favors: unit cost × quantity × the tier multiplier, rounded half up.
     *
     * @throws DomainException below the minimum event-favor quantity
     */
    public static function eventFavorBatchMillis(int $unitCostMillis, int $quantity): int
    {
        $percent = self::eventFavorMultiplierPercent($quantity);

        // Half-up rounding to the millime after applying the tier multiplier.
        return intdiv($unitCostMillis * $quantity * $percent + 50, 100);
    }


    /**
     * The event-favor multiplier for a quantity, as a percentage (175, 150 or 130).
     *
     * @throws DomainException below the minimum event-favor quantity
     */
    public static function eventFavorMultiplierPercent(int $quantity): int
    {
        foreach (self::EVENT_FAVOR_TIERS as $tier) {
            if ($quantity >= $tier['minimum']) {
                return $tier['percent'];
            }
        }
        throw new DomainException('Event favors can be ordered from ' . self::EVENT_FAVOR_MINIMUM . ' pieces.');
    }


    /**
     * The unit price at each event-favor tier, for display next to a product's cost.
     *
     * @return list<array{label: string, multiplier: string, unit_price_millis: int}>
     */
    public static function eventFavorTiers(int $unitCostMillis): array
    {
        $tiers = [];
        foreach (array_reverse(self::EVENT_FAVOR_TIERS) as $tier) {
            $tiers[] = [
                'label' => $tier['label'],
                'multiplier' => rtrim(rtrim(number_format($tier['percent'] / 100, 2, '.', ''), '0'), '.'),
                'unit_price_millis' => intdiv($unitCostMillis * $tier['percent'] + 50, 100),
            ];
        }

        return $tiers;
    }


    /** Millis as a three-decimal TND string, e.g. 12500 => '12.500'. */
    public static function formatMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Apply the rule for one product's category.
     *
     * @param array<int, array<string, mixed>> $byId
     * @param array<int, list<array{id: int, quantity: int}>> $componentsBySet
     * @param array<int, array<string, mixed>> $results Products already priced in this run.
     * @return array{selling_price_millis: ?int, actual_cost_millis: ?int, rule: ?string, issue: ?string, explanation: ?string}
     */
    private function priceOne(int $id, array $byId, array $componentsBySet, Closure $actualCost, Closure $recipeCost, array $results): array
    {
        $category = $byId[$id]['category_slug'] ?? null;
        $cost = $actualCost($id);
        $money = static fn (int $millis): string => self::formatMoney($millis) . ' TND';
        $priced = static function (?int $price, string $rule, string $explanation) use ($cost): array {
            // A zero price would sell the piece for free; treat it as a missing cost input.
            if ($price === null || $price <= 0) {
                return self::unpriced($cost, 'The calculated price is zero; check the recipe quantities and material unit costs.');
            }

            return ['selling_price_millis' => $price, 'actual_cost_millis' => $cost, 'rule' => $rule, 'issue' => null, 'explanation' => $explanation];
        };
        $needsRecipe = 'Add an actual material recipe so the cost, and therefore the price, can be calculated.';

        switch ($category) {
            case CatalogueCategory::CANDLES:
                return $cost === null ? self::unpriced(null, $needsRecipe)
                    : $priced($cost * 2, 'CANDLE_COST_X2', 'Actual cost ' . $money($cost) . ' × 2');

            case CatalogueCategory::BOUQUETS:
                return $cost === null ? self::unpriced(null, $needsRecipe)
                    : $priced($cost * 2, 'BOUQUET_BASE_COST_X2', 'Base price: actual cost ' . $money($cost) . ' × 2; the final price is agreed with the customer');

            case CatalogueCategory::ACCESSORIES:
                return $cost === null
                    ? self::unpriced(null, 'Add the purchase cost as the actual recipe (for example 1 × the accessory material).')
                    : $priced($cost, 'ACCESSORY_PURCHASE_COST', 'Purchase cost ' . $money($cost) . ', no markup');

            case CatalogueCategory::EVENT_FAVORS:
                // The catalogue stores the per-favor price of the smallest tier;
                // batch prices come from eventFavorBatchMillis() with the real quantity.
                return $cost === null ? self::unpriced(null, $needsRecipe)
                    : $priced(
                        intdiv($cost * 175 + 50, 100),
                        'EVENT_FAVOR_TIER',
                        'Per favor from ' . self::EVENT_FAVOR_MINIMUM . ' pieces: unit cost ' . $money($cost) . ' × 1.75 (20–39 × 1.5, 40+ × 1.3)'
                    );

            case CatalogueCategory::SETS:
                $rows = $componentsBySet[$id] ?? [];
                if ($rows === []) {
                    return self::unpriced(null, 'Add the set components so the set price can be calculated.');
                }
                $markedUp = $recipeCost($id, 'ACTUAL') ?? 0; // the set's own packaging/labour
                $atCost = 0;
                foreach ($rows as $row) {
                    $componentCost = $actualCost($row['id']);
                    if ($componentCost === null) {
                        return self::unpriced(null, 'Component product #' . $row['id'] . ' has no actual cost yet; add its recipe first.');
                    }
                    if (($byId[$row['id']]['category_slug'] ?? null) === CatalogueCategory::ACCESSORIES) {
                        $atCost += $componentCost * $row['quantity'];
                    } else {
                        $markedUp += $componentCost * $row['quantity'];
                    }
                }

                return $priced(
                    $markedUp * 2 + $atCost,
                    'SET_COMPONENTS',
                    'Candles and production items ' . $money($markedUp) . ' × 2 + accessories at cost ' . $money($atCost)
                );

            case CatalogueCategory::DIFFUSERS:
                $referenceId = $byId[$id]['pricing_reference_product_id'] ?? null;
                if ($referenceId !== null) {
                    $reference = $results[(int) $referenceId] ?? null;
                    if ($reference === null || $reference['selling_price_millis'] === null) {
                        return self::unpriced($cost, 'The linked equivalent candle (#' . (int) $referenceId . ') has no calculated price yet.');
                    }

                    return $priced(
                        $reference['selling_price_millis'],
                        'DIFFUSER_REFERENCE_CANDLE',
                        'Same price as the equivalent candle (#' . (int) $referenceId . '); profit uses the diffuser\'s own cost'
                    );
                }
                $equivalent = $recipeCost($id, 'CANDLE_EQUIVALENT');
                if ($equivalent === null) {
                    return self::unpriced($cost, 'Link an equivalent candle or add a candle-equivalent recipe.');
                }

                return $priced(
                    $equivalent * 2,
                    'DIFFUSER_CANDLE_EQUIVALENT',
                    'Candle-equivalent cost ' . $money($equivalent) . ' × 2; profit uses the diffuser\'s own cost'
                );

            case null:
                return self::unpriced($cost, 'Choose a category so a pricing rule applies.');

            default:
                return self::unpriced($cost, 'No documented pricing rule exists for this category.');
        }
    }

    /**
     * @return array{selling_price_millis: null, actual_cost_millis: ?int, rule: null, issue: string, explanation: null}
     */
    private static function unpriced(?int $cost, string $issue): array
    {
        return ['selling_price_millis' => null, 'actual_cost_millis' => $cost, 'rule' => null, 'issue' => $issue, 'explanation' => null];
    }


    private static function toMillis(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
        }

        return (int) round(((float) $value) * 1000);
    }


    private static function sameMoney(mixed $stored, ?string $next): bool
    {
        if ($stored === null || $next === null) {
            return $stored === null && $next === null;
        }

        return self::toMillis($stored) === self::toMillis($next);
    }
}
