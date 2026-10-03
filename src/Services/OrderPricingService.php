<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\PricingRepository;
use DomainException;

/** Sale pricing, shared by previews and persistence; all money is integer millimes. */
final class OrderPricingService
{
    public function __construct(private readonly PricingRepository $pricing) {}

    public static function unitPrice(array $product, int $quantity): array
    {
        if ($quantity < max(1, (int) $product['minimum_quantity'])) {
            throw new DomainException('The quantity is below this product\'s minimum.');
        }
        $base = Money::parseMillis((string) ($product['selling_price'] ?? ''));
        if ($base === null || $base <= 0) throw new DomainException('Configure this product\'s selling price in Catalogue before adding it to an order.');
        $effective = $base;
        $label = null;
        // Preserve the documented event-favor model; configurable tiers are for other products.
        if (($product['price_rule'] ?? '') === 'EVENT_FAVOR_TIER') {
            $effective = intdiv((Money::parseMillis((string) $product['actual_cost']) ?? 0)
                * PricingService::eventFavorMultiplierPercent($quantity) + 50, 100);
            $label = 'Event favor cost multiplier: ' . PricingService::eventFavorMultiplierPercent($quantity) . '%';
        } else {
            foreach ($product['quantity_prices'] ?? [] as $tier) {
                if ((int) $tier['is_active'] === 1 && $quantity >= (int) $tier['minimum_quantity']
                    && ($tier['maximum_quantity'] === null || $quantity <= (int) $tier['maximum_quantity'])) {
                    $effective = Money::parseMillis((string) $tier['unit_price']);
                    $label = $tier['minimum_quantity'] . '-' . ($tier['maximum_quantity'] ?? 'up');
                    break;
                }
            }
        }
        return ['base_millis' => $base, 'quantity_millis' => $effective, 'tier' => $label,
            'line_millis' => $effective * $quantity, 'savings_millis' => max(0, ($base - $effective) * $quantity)];
    }

    public function quote(int $subtotal, int $fee, array $input, bool $lock = false): array
    {
        $code = strtoupper(trim(is_string($input['promo_code'] ?? null) ? $input['promo_code'] : ''));
        $promo = $code === '' ? null : $this->pricing->findPromotion($code, $lock);
        return self::calculate($subtotal, $fee, $input, $promo);
    }

    public static function calculate(int $subtotal, int $fee, array $input = [], ?array $promo = null, ?string $today = null): array
    {
        if ($subtotal < 0 || $fee < 0 || $subtotal + $fee > 999999999999) throw new DomainException('The order total exceeds the supported monetary limit.');
        $code = strtoupper(trim(is_string($input['promo_code'] ?? null) ? $input['promo_code'] : ''));
        $promoAmount = 0;
        if ($code !== '') {
            $today ??= date('Y-m-d');
            if ($promo === null || (int) $promo['is_active'] !== 1) throw new DomainException('This promotion code is invalid or no longer active.');
            if ($promo['valid_until'] !== null && $promo['valid_until'] < $today) throw new DomainException('This promotion code has expired.');
            if ($promo['valid_from'] !== null && $promo['valid_from'] > $today) throw new DomainException('This promotion code is not active yet.');
            if ($subtotal < Money::parseMillis((string) $promo['minimum_subtotal'])) throw new DomainException('This order does not meet the promotion minimum amount.');
            $promoAmount = self::discount($subtotal, $promo['discount_type'], (string) $promo['discount_value']);
        }
        $type = is_string($input['manual_discount_type'] ?? null) ? $input['manual_discount_type'] : '';
        if (!in_array($type, ['', 'NONE', 'PERCENTAGE', 'FIXED_TND'], true)) throw new DomainException('Choose a valid additional discount type.');
        $reason = trim(is_string($input['manual_discount_reason'] ?? null) ? $input['manual_discount_reason'] : '');
        if (strlen($reason) > 500) throw new DomainException('Keep the discount reason to 500 characters.');
        $value = is_string($input['manual_discount_value'] ?? null) ? trim($input['manual_discount_value']) : '';
        $manual = in_array($type, ['PERCENTAGE', 'FIXED_TND'], true) ? self::discount($subtotal - $promoAmount, $type, $value) : 0;
        $total = self::total($subtotal, $promoAmount, $manual, $fee);
        return ['subtotal_millis' => $subtotal, 'promo_millis' => $promoAmount, 'manual_millis' => $manual,
            'delivery_millis' => $fee, 'total_millis' => $total, 'deposit_millis' => self::deposit($total),
            'promo_code_snapshot' => $code === '' ? null : $code,
            'promo_type_snapshot' => $code === '' ? null : $promo['discount_type'],
            'promo_value_snapshot' => $code === '' ? null : $promo['discount_value'],
            'manual_discount_type' => $manual === 0 ? null : $type,
            'manual_discount_value' => $manual === 0 ? null : Money::formatMillis(Money::parseMillis($value)),
            'manual_discount_reason' => $manual === 0 || $reason === '' ? null : $reason];
    }

    public static function total(int $subtotal, int $promo, int $manual, int $fee): int
    {
        if (min($subtotal, $promo, $manual, $fee) < 0 || $promo + $manual > $subtotal || $subtotal + $fee > 999999999999) throw new DomainException('The order total exceeds the supported monetary limit.');
        return $subtotal - $promo - $manual + $fee;
    }

    public static function deposit(int $total, int $threshold = 100000, int $percent = 40): int { return $total >= $threshold ? intdiv($total * $percent + 50, 100) : 0; }

    public static function snapshot(array $quote, int $savings = 0): array
    {
        $values = array_intersect_key($quote, array_flip(['promo_code_snapshot', 'promo_type_snapshot', 'promo_value_snapshot',
            'manual_discount_type', 'manual_discount_value', 'manual_discount_reason']));
        return $values + ['promo_discount_amount' => Money::formatMillis($quote['promo_millis']),
            'manual_discount_amount' => Money::formatMillis($quote['manual_millis']), 'quantity_savings_snapshot' => Money::formatMillis($savings)];
    }

    private static function discount(int $amount, string $type, string $value): int
    {
        $millis = Money::parseMillis($value);
        if ($millis === null || $millis < 0) throw new DomainException('Enter a non-negative discount with up to three decimals.');
        if ($type === 'PERCENTAGE') {
            if ($millis > 100000) throw new DomainException('A percentage discount cannot exceed 100%.');
            $discount = intdiv($amount * $millis + 50000, 100000);
        } elseif ($type === 'FIXED_TND') { $discount = $millis; }
        else { throw new DomainException('Choose a valid discount type.'); }
        if ($discount > $amount) throw new DomainException('The discount cannot exceed the applicable merchandise total.');
        return $discount;
    }
}
