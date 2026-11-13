<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\ScentStockRepository;
use DomainException;

final class ScentAvailabilityService
{
    public function __construct(
        private readonly ScentStockRepository $stock,
        private readonly TransactionManager $transactions
    ) {
    }


    /**
     * Which scents can still be sold for this product, for the product page.
     *
     * A scent is available when it is active and its fragrance material (if it
     * tracks stock) has enough left, after other open orders, for the product's
     * minimum quantity.
     *
     * @param list<int> $scentIds
     * @return array<int, bool> scent ID => available
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
     * Refuses an order whose chosen scents cannot be made.
     *
     * Each line is ['product_id', 'scent_id', 'quantity']. Lines that share one
     * fragrance material are added together before comparing with its stock
     * (stock minus other open orders). Lines without a scent are ignored.
     *
     * @param list<array{product_id: int, scent_id: ?int, quantity: int}> $lines
     * @param int|null $excludeOrderId the order being edited, so its own use does not count against it
     * @throws DomainException naming every scent that is short
     */
    public function assertAvailable(array $lines, ?int $excludeOrderId = null): void
    {
        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['scent_id'] !== null && $line['quantity'] > 0));
        if ($lines === []) {
            return;
        }

        // Reuses the caller's transaction, so the locks last until its commit.
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


    private static function format(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
