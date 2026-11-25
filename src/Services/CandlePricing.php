<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Automatic prices for individual candles, from the finished candle weight and its recipe.
 *
 *   Material grams   = recipe grams x (candle weight / recipe total grams)
 *   Materials cost   = sum(grams x unit cost per gram), fragrance converted with its density
 *   Known unit cost  = materials cost + packaging per candle
 *   Retail price     = RoundUp(known unit cost x retail multiplier, retail step)
 *   Minimum price    = RoundUp(known unit cost x (1 + minimum markup), 0.001)
 *   Unit price (n)   = RoundUp(minimum + (retail - minimum) / n^exponent, quantity step), never below minimum
 *
 * Exact integer arithmetic: weights in milligrams, unit costs in nano-millimes per milligram
 * (0.040 TND/g = 40 000), money in millimes (1 TND = 1000). Nothing here reads or writes the
 * database and nothing here changes a selling price; the caller decides what to do with the result.
 */
final class CandlePricing
{
    /** Denominator that turns "milligrams x nano-millimes per milligram" into millimes. */
    private const NANO_PER_MILLIME = 1_000_000;

    /**
     * @param int $weightMilligrams finished candle weight in mg (75 g = 75000)
     * @param list<array{grams_milli: int, cost_nano_per_milligram: int, fragrance: bool}> $recipe
     *        grams_milli: the recipe's grams in mg; cost_nano_per_milligram: the material's cost per mg
     *        in nano-millimes (0.040 TND/g = 40 000 nano-millimes per mg); fragrance: converted by density
     * @param array{retail_multiplier_bp: int, minimum_markup_bp: int, quantity_exponent: float,
     *        retail_step_millis: int, quantity_step_millis: int, packaging_millis: int,
     *        density_g_per_ml: float, fragrance_density_confirmed: bool} $settings
     * @return array<string, mixed>
     */
    public static function price(int $weightMilligrams, array $recipe, int $quantity, array $settings): array
    {
        if ($weightMilligrams <= 0) {
            throw new DomainException('The candle weight must be a positive number of grams.');
        }
        if ($quantity <= 0) {
            throw new DomainException('The quantity must be a positive whole number.');
        }
        if ($recipe === []) {
            throw new DomainException('The recipe has no materials.');
        }
        if ($settings['quantity_exponent'] <= 0 || $settings['density_g_per_ml'] <= 0) {
            throw new DomainException('The quantity exponent and fragrance density must be positive.');
        }

        $recipeTotal = 0;
        foreach ($recipe as $line) {
            if ($line['grams_milli'] <= 0 || $line['cost_nano_per_milligram'] < 0) {
                throw new DomainException('Each recipe line needs a positive quantity and a non-negative cost.');
            }
            $recipeTotal += $line['grams_milli'];
        }

        // Scale the recipe to the requested weight. Each line is rounded to the milligram.
        $materials = [];
        $materialNano = 0;
        foreach ($recipe as $line) {
            $grams = intdiv($line['grams_milli'] * $weightMilligrams + intdiv($recipeTotal, 2), $recipeTotal);
            // Fragrance is bought in millilitres but weighed in grams: ml = g / density.
            $effectiveMilligrams = $line['fragrance'] ? (int) round($grams / $settings['density_g_per_ml']) : $grams;
            $lineNano = $effectiveMilligrams * $line['cost_nano_per_milligram'];
            $materials[] = ['milligrams' => $grams, 'cost_nano' => $lineNano];
            $materialNano += $lineNano;
        }
        $materialsMillis = intdiv($materialNano + intdiv(self::NANO_PER_MILLIME, 2), self::NANO_PER_MILLIME);

        $known = $materialsMillis + $settings['packaging_millis'];

        $retailRaw = intdiv($known * $settings['retail_multiplier_bp'] + 4999, 10000);
        $retail = self::roundUp($retailRaw, $settings['retail_step_millis']);

        // Minimum: 40% markup on cost. Exact in millimes; rounded up to the millime.
        $minimum = intdiv($known * (10000 + $settings['minimum_markup_bp']) + 9999, 10000);
        if ($retail < $minimum) {
            $retail = self::roundUp($minimum, $settings['retail_step_millis']);
        }

        $unit = self::unitPrice($quantity, $minimum, $retail, $settings);

        return [
            'weight_milligrams' => $weightMilligrams,
            'materials' => $materials,
            'materials_millis' => $materialsMillis,
            'packaging_millis' => $settings['packaging_millis'],
            'known_unit_cost_millis' => $known,
            'retail_millis' => $retail,
            'minimum_millis' => $minimum,
            'quantity' => $quantity,
            'unit_price_millis' => $unit,
            'order_total_millis' => $unit * $quantity,
            'discount_vs_retail_percent' => $retail > 0 ? round(($retail - $unit) * 100 / $retail, 2) : 0.0,
            // Labour and overhead are not configured yet, so every cost is provisional.
            'costing' => 'provisional_materials_and_packaging',
            'fragrance_density_provisional' => !$settings['fragrance_density_confirmed'],
        ];
    }

    /**
     * Unit price for a quantity: retail at 1, falling smoothly towards the minimum, never below it.
     * Rounded up so a rounding step cannot break the minimum markup.
     */
    public static function unitPrice(int $quantity, int $minimumMillis, int $retailMillis, array $settings): int
    {
        if ($quantity === 1) {
            return $retailMillis;
        }
        $spread = $retailMillis - $minimumMillis;
        $raw = $minimumMillis + $spread / ($quantity ** $settings['quantity_exponent']);
        // Subtract a tiny epsilon so float noise cannot push an exact step up by one.
        $unit = self::roundUp((int) ceil($raw - 1e-6), $settings['quantity_step_millis']);

        return max($unit, $minimumMillis);
    }

    /** Smallest multiple of the step that is at least the value; a step of 1 means no rounding. */
    public static function roundUp(int $millis, int $stepMillis): int
    {
        if ($stepMillis <= 1) {
            return $millis;
        }

        return intdiv($millis + $stepMillis - 1, $stepMillis) * $stepMillis;
    }
}
