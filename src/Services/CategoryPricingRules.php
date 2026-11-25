<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The pricing rule each category follows, shown next to its product list in the admin.
 * The wording describes what PricingService does for that category; it changes no prices.
 */
final class CategoryPricingRules
{
    /** @return array{title: string, body: string}|null null for a category without a rule */
    public static function forSlug(string $slug): ?array
    {
        return match ($slug) {
            'candles' => [
                'title' => 'Candle: known cost x 2.10',
                'body' => 'Retail = known cost (materials and 1 TND packaging) x 2.10, rounded up to 0.50 TND. The lowest allowed price is known cost x 1.40. Quantity prices fall smoothly towards that minimum.',
            ],
            'candle-sets' => [
                'title' => 'Candle set: components x 2',
                'body' => 'Price from the candles inside the set (cost x 2 each), plus accessories at cost. Enter the price yourself when the contents are not yet known.',
            ],
            'bouquets' => [
                'title' => 'Bouquet: base cost x 2',
                'body' => 'Base price is the actual cost x 2. The final price is agreed with the customer, so treat this as a starting point.',
            ],
            'event-favors' => [
                'title' => 'Event favour: quantity tiers',
                'body' => 'Unit cost x a tier multiplier that depends on the quantity: 175% for small batches, then 150% and 130% for larger ones.',
            ],
            'home-diffusers' => [
                'title' => 'Diffuser: same as its candle',
                'body' => 'The diffuser takes the price of the candle it is linked to.',
            ],
            'accessories' => [
                'title' => 'Accessory: purchase cost',
                'body' => 'Sold at the purchase cost, with no markup.',
            ],
            default => null,
        };
    }
}
