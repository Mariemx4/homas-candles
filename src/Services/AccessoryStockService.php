<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use DomainException;

final class AccessoryStockService
{
    // Statuses in which the order's goods have left the shop.
    private const HANDED_OVER = ['PICKED_UP', 'DELIVERED'];

    // Statuses in which the accessories are physically packed with the order.
    private const HOLDING = ['PREPARED', 'WAITING_FOR_PICKUP', 'IN_DELIVERY', 'PICKED_UP', 'DELIVERED'];


    public function __construct(
        private readonly AccessoryStockRepository $stock,
        private readonly TransactionManager $transactions
    ) {
    }


    /**
     * Refuses an order whose accessories are not available.
     *
     * Available = shelf stock minus what other open orders have committed.
     * $lines maps product ID => quantity; set products are expanded into their
     * accessory components. Locks the products until the caller's transaction ends.
     *
     * @param array<int, int> $lines
     * @param int|null $excludeOrderId the order being edited, so its own quantities do not count against it
     * @throws DomainException naming every product that is short
     */
    public function assertAvailable(array $lines, ?int $excludeOrderId = null): void
    {
        // Reuses the caller's transaction, so the locks last until its commit.
        $this->transactions->run(function () use ($lines, $excludeOrderId): void {
            $this->assertNeedsAvailable($this->stock->expandLines($lines), $excludeOrderId);
        });
    }


    /**
     * Takes the order's accessories off the shelf (called when it becomes PREPARED).
     *
     * Only the part not already held by the order is deducted, so calling it twice
     * never deducts twice. A shortage throws and rolls back the whole status change.
     *
     * @throws DomainException when any accessory is short
     */
    public function deductForOrder(int $orderId, ?int $administratorId): void
    {
        $this->transactions->run(function () use ($orderId, $administratorId): void {
            $needed = $this->stock->orderRequirements($orderId);
            $products = $this->stock->lockProducts(array_keys($needed));
            $held = $this->stock->heldByOrder($orderId);
            $short = [];
            foreach ($needed as $productId => $quantity) {
                $missing = $quantity - ($held[$productId] ?? 0);
                if ($missing <= 0 || !isset($products[$productId])) {
                    continue;
                }
                if ($products[$productId]['stock'] < $missing) {
                    $short[] = $products[$productId]['name'] . ' (' . $missing . ' needed, ' . $products[$productId]['stock'] . ' in stock)';
                    continue;
                }
                $this->stock->applyMovement(
                    $productId,
                    'ORDER_DEDUCTION',
                    -$missing,
                    $orderId,
                    $administratorId,
                    'Accessories taken from stock when the order became prepared.'
                );
            }
            if ($short !== []) {
                // Throwing rolls back the deductions above and the status change.
                throw new DomainException('Not enough accessory stock to prepare this order: ' . implode(', ', $short) . '.');
            }
        });
    }


    /**
     * Returns the accessories an order holds to the shelf when it is moved to Trash.
     *
     * Nothing comes back once the order was handed over (PICKED_UP or DELIVERED):
     * those goods have left the shop.
     *
     * @return int the number of units returned
     */
    public function restoreForOrder(int $orderId, string $orderStatus, ?int $administratorId): int
    {
        if (in_array($orderStatus, self::HANDED_OVER, true)) {
            return 0;
        }

        return $this->transactions->run(function () use ($orderId, $administratorId): int {
            $held = $this->stock->heldByOrder($orderId);
            $this->stock->lockProducts(array_keys($held));
            $restored = 0;
            foreach ($held as $productId => $quantity) {
                $this->stock->applyMovement(
                    $productId,
                    'ORDER_RESTORATION',
                    $quantity,
                    $orderId,
                    $administratorId,
                    'Accessories returned to stock when the order was cancelled.'
                );
                $restored += $quantity;
            }

            return $restored;
        });
    }


    /**
     * Re-applies stock rules when an order is restored from Trash.
     *
     * An order that had already been prepared takes its accessories again, but only
     * if they were actually returned when it was trashed. Any other order is just
     * checked for availability, as at creation.
     *
     * @throws DomainException when the needed accessories are no longer available
     */
    public function reinstateOrder(int $orderId, string $orderStatus, ?int $administratorId): void
    {
        $this->transactions->run(function () use ($orderId, $orderStatus, $administratorId): void {
            if (in_array($orderStatus, self::HOLDING, true)) {
                // Only an order whose stock was actually returned takes it again;
                // one trashed after hand-over never gave anything back.
                if ($this->stock->hasRestoration($orderId)) {
                    $this->deductForOrder($orderId, $administratorId);
                }

                return;
            }
            $held = $this->stock->heldByOrder($orderId);
            $needed = [];
            foreach ($this->stock->orderRequirements($orderId) as $productId => $quantity) {
                if ($quantity > ($held[$productId] ?? 0)) {
                    $needed[$productId] = $quantity - ($held[$productId] ?? 0);
                }
            }
            $this->assertNeedsAvailable($needed, $orderId);
        });
    }


    /**
     * Records a stock number typed on the product form in the stock ledger.
     *
     * The first value is an OPENING entry, later changes are ADJUSTMENT entries;
     * an empty or unchanged field records nothing.
     */
    public function recordFormChange(int $productId, ?int $before, ?int $after): void
    {
        if ($after === null || $before === $after) {
            return;
        }
        $this->stock->recordFormChange(
            $productId,
            $before === null ? 'OPENING' : 'ADJUSTMENT',
            $before ?? 0,
            $after,
            $before === null ? 'Opening stock entered on the product form.' : 'Stock corrected on the product form.'
        );
    }


    /** The latest stock-ledger entries for one accessory, newest first, for the product page. */
    public function recentMovements(int $productId): array
    {
        return $this->stock->recentMovements($productId);
    }


    private function assertNeedsAvailable(array $needed, ?int $excludeOrderId): void
    {
        if ($needed === []) {
            return;
        }
        $products = $this->stock->lockProducts(array_keys($needed));
        $committed = $this->stock->committedQuantities(array_keys($needed), $excludeOrderId);
        $short = [];
        foreach ($needed as $productId => $quantity) {
            if (!isset($products[$productId])) {
                continue;
            }
            $available = max(0, $products[$productId]['stock'] - ($committed[$productId] ?? 0));
            if ($quantity > $available) {
                $short[] = $products[$productId]['name'] . ' (' . $quantity . ' requested, ' . $available . ' available)';
            }
        }
        if ($short !== []) {
            throw new DomainException('Not enough accessory stock: ' . implode(', ', $short) . '.');
        }
    }
}
