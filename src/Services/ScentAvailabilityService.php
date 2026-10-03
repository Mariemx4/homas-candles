<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Scent Availability Service; it decides whether a scent can be sold from the stock of its fragrance material.
 *
 * DATA FLOW: ProductService asks which of a product's scents are available for
 * the product page and the cart; CheckoutService and ManualOrderService ask it
 * to revalidate the chosen scents, with the material rows locked, before an
 * order is saved. Reads go through ScentStockRepository; stock itself is only
 * deducted at PREPARED (OrderStatusService), where the chosen scent's material
 * replaces the recipe's fragrance line.
 *
 * AVAILABILITY RULE (docs/database-requirements.md: "Scent availability is
 * managed according to fragrance-material stock")
 *   needed    = fragrance per unit of the product (see ScentStockRepository)
 *               × quantity (the product's minimum quantity on the product page)
 *   available = fragrance-material stock − fragrance already promised to open,
 *               not-yet-prepared orders
 *   A scent is available when needed ≤ available. A scent whose material is
 *   inactive is never available. A scent with no linked material, or one whose
 *   material does not track stock, cannot run out and stays available.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\ScentStockRepository;
use DomainException;

final class ScentAvailabilityService
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly ScentStockRepository $stock,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * FUNCTION PURPOSE: Report which of a product's scents can currently be ordered.
     *
     * WHY: a scent is shown as available only if the smallest orderable
     *      quantity of this product can still be made in it.
     *
     * @param list<int> $scentIds
     * @return array<int, bool> Scent ID => available.
     */
    public function availability(int $productId, int $minimumQuantity, array $scentIds): array
    {
        $scents = $this->stock->scentMaterials($scentIds);
        $needed = ($this->stock->fragrancePerUnit([$productId])[$productId] ?? 0) * max(1, $minimumQuantity);
        $committed = $this->committedMillis($this->trackedMaterials($scents), null);

        $availability = [];
        foreach ($scents as $scentId => $scent) {
            $availability[$scentId] = $this->shortfall($scent, $needed, $committed) === null;
        }

        return $availability;
    }

    /**
     * FUNCTION PURPOSE: Revalidate the chosen scents of an order being accepted.
     *
     * WHAT: adds up, per fragrance material, what every line needs, then
     *       compares it with stock minus other open orders, with the material
     *       rows locked until the caller's transaction ends.
     * WHY:  the product page may be minutes old, and two lines (or two
     *       customers) can draw on the same fragrance oil.
     *
     * @param list<array{product_id: int, scent_id: ?int, quantity: int}> $lines
     * @throws DomainException naming each scent that cannot be supplied.
     */
    public function assertAvailable(array $lines, ?int $excludeOrderId = null): void
    {
        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['scent_id'] !== null && $line['quantity'] > 0));
        if ($lines === []) {
            return;
        }

        // TRANSACTION IDIOM: Reuses the caller's transaction, so the locks last until its commit.
        $this->transactions->run(function () use ($lines, $excludeOrderId): void {
            $perUnit = $this->stock->fragrancePerUnit(array_column($lines, 'product_id'));
            $scents = $this->stock->scentMaterials(array_column($lines, 'scent_id'), true);

            // One material can back several lines; each scent is judged on the
            // total its material must supply for this whole order.
            $neededByScent = [];
            $neededByMaterial = [];
            foreach ($lines as $line) {
                $needed = ($perUnit[$line['product_id']] ?? 0) * $line['quantity'];
                $neededByScent[$line['scent_id']] = ($neededByScent[$line['scent_id']] ?? 0) + $needed;
                $materialId = $scents[$line['scent_id']]['material_id'] ?? null;
                if ($materialId !== null) {
                    $neededByMaterial[$materialId] = ($neededByMaterial[$materialId] ?? 0) + $needed;
                }
            }
            $committed = $this->committedMillis($this->trackedMaterials($scents), $excludeOrderId);

            $problems = [];
            foreach ($neededByScent as $scentId => $needed) {
                $scent = $scents[$scentId] ?? null;
                if ($scent === null) {
                    throw new DomainException('The chosen scent is no longer available.');
                }
                $materialTotal = $scent['material_id'] === null ? $needed : $neededByMaterial[$scent['material_id']];
                $shortfall = $this->shortfall($scent, $materialTotal, $committed);
                if ($shortfall !== null) {
                    $problems[$scentId] = $scent['scent_name'] . ' (' . $shortfall . ')';
                }
            }
            if ($problems !== []) {
                throw new DomainException('Not enough fragrance for the chosen scent: ' . implode(', ', $problems) . '.');
            }
        });
    }

    /**
     * FUNCTION PURPOSE: Explain why a scent cannot supply the needed fragrance, or return null when it can.
     *
     * @param array{material_id: ?int, scent_active: bool, material_active: bool, tracks_stock: bool, stock_millis: ?int, unit: ?string} $scent
     * @param array<int, int> $committed Material ID => thousandths promised to other open orders.
     */
    private function shortfall(array $scent, int $neededMillis, array $committed): ?string
    {
        if (!$scent['scent_active']) {
            return 'the scent is no longer in use';
        }
        if ($scent['material_id'] === null) {
            return null;
        }
        if (!$scent['material_active']) {
            return 'its fragrance material is no longer in use';
        }
        if ($scent['tracks_stock'] && $neededMillis <= 0) {
            return 'its required fragrance quantity is not configured';
        }
        if (!$scent['tracks_stock'] || $scent['stock_millis'] === null) {
            return null;
        }
        $available = max(0, $scent['stock_millis'] - ($committed[$scent['material_id']] ?? 0));
        if ($neededMillis <= $available) {
            return null;
        }

        return self::format($neededMillis) . ' ' . $scent['unit'] . ' needed, ' . self::format($available) . ' available';
    }

    /**
     * FUNCTION PURPOSE: Sum, per material, the fragrance promised to open orders, in thousandths.
     *
     * @param list<int> $materialIds
     * @return array<int, int>
     */
    private function committedMillis(array $materialIds, ?int $excludeOrderId): array
    {
        $lines = $this->stock->committedLines($materialIds, $excludeOrderId);
        $perUnit = $this->stock->fragrancePerUnit(array_column($lines, 'product_id'));
        $committed = $this->stock->committedSnapshotQuantities($materialIds, $excludeOrderId);
        foreach ($lines as $line) {
            $committed[$line['material_id']] = ($committed[$line['material_id']] ?? 0) + ($perUnit[$line['product_id']] ?? 0) * $line['quantity'];
        }

        return $committed;
    }

    /**
     * FUNCTION PURPOSE: Keep only the materials whose stock can run out.
     *
     * @param array<int, array<string, mixed>> $scents
     * @return list<int>
     */
    private function trackedMaterials(array $scents): array
    {
        $ids = [];
        foreach ($scents as $scent) {
            if ($scent['material_id'] !== null && $scent['tracks_stock'] && $scent['material_active']) {
                $ids[$scent['material_id']] = $scent['material_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * FUNCTION PURPOSE: Format thousandths as a three-decimal quantity.
     */
    private static function format(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
