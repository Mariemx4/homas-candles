<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Accessory Stock Service; the one place that checks and moves stock of ready-made (stock-tracked) products such as accessories.
 *
 * DATA FLOW: CheckoutService and ManualOrderService ask it to confirm
 * availability before an order is saved; OrderStatusService asks it to take
 * accessories from stock at PREPARED; OrderTrashController asks it to put them
 * back on cancellation (and take them again on restore); AdminProductService
 * asks it to record manual stock edits. Every change goes through
 * AccessoryStockRepository inside the caller's transaction.
 *
 * STOCK POLICY
 *   Counted     : products with a stock_quantity (accessories), ordered on
 *                 their own or as components of a set (component quantity ×
 *                 set quantity).
 *   Availability: shelf stock − units committed by other open orders
 *                 (REQUESTED/CONFIRMED/PREPARING, not in Trash, not yet
 *                 holding stock). Checked with the product rows locked, so two
 *                 concurrent orders cannot both take the last unit.
 *   Deduction   : when the order becomes PREPARED — the same moment materials
 *                 are deducted — exactly once; the shelf must still hold the
 *                 units or the whole status change rolls back.
 *   Corrections : moving a prepared order back to an earlier status keeps its
 *                 accessories deducted (they are still packed with the order),
 *                 just as material use is never undone by a correction.
 *   Cancellation: moving an order to Trash is the cancellation. If it holds
 *                 accessories and has not been handed over (not PICKED_UP or
 *                 DELIVERED), they are restored to stock (ORDER_RESTORATION).
 *                 A completed order moved to Trash restores nothing — its
 *                 goods have left the shop.
 *   Restoration : bringing a cancelled order back from Trash takes its
 *                 accessories again if it is prepared, or re-checks
 *                 availability if it is not yet prepared; if the stock is no
 *                 longer there, the restore is refused.
 */

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

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AccessoryStockRepository $stock,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * FUNCTION PURPOSE: Refuse an order whose accessories are not available.
     *
     * @param array<int, int> $lines Ordered product ID => quantity (sets are expanded here).
     * @param int|null $excludeOrderId An existing order whose own commitment must not count against it.
     * @throws DomainException naming each accessory that is short.
     */
    public function assertAvailable(array $lines, ?int $excludeOrderId = null): void
    {
        // TRANSACTION IDIOM: Reuses the caller's transaction, so the locks last until its commit.
        $this->transactions->run(function () use ($lines, $excludeOrderId): void {
            $this->assertNeedsAvailable($this->stock->expandLines($lines), $excludeOrderId);
        });
    }

    /**
     * FUNCTION PURPOSE: Take an order's accessories from stock when it becomes PREPARED.
     *
     * WHAT: deducts only what the order does not already hold, so a repeated
     *       call (or a re-preparation after a correction) changes nothing.
     *
     * @throws DomainException when the shelf no longer holds enough units.
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
                $this->stock->applyMovement($productId, 'ORDER_DEDUCTION', -$missing, $orderId, $administratorId,
                    'Accessories taken from stock when the order became prepared.');
            }
            if ($short !== []) {
                // Throwing rolls back the deductions above and the status change.
                throw new DomainException('Not enough accessory stock to prepare this order: ' . implode(', ', $short) . '.');
            }
        });
    }

    /**
     * FUNCTION PURPOSE: Put a cancelled order's accessories back into stock.
     *
     * @param string $orderStatus The status at cancellation; handed-over orders restore nothing.
     * @return int Units restored (0 when nothing was held or the goods were handed over).
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
                $this->stock->applyMovement($productId, 'ORDER_RESTORATION', $quantity, $orderId, $administratorId,
                    'Accessories returned to stock when the order was cancelled.');
                $restored += $quantity;
            }

            return $restored;
        });
    }

    /**
     * FUNCTION PURPOSE: Re-apply the stock effect of an order brought back from Trash.
     *
     * WHAT: a prepared order whose accessories were restored takes them again;
     *       an order not yet prepared must still fit within available stock.
     *
     * @throws DomainException when the accessories are no longer available.
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
     * FUNCTION PURPOSE: Record the ledger row for a stock value saved from the product form.
     *
     * @param int|null $before Stock before the save (null when the product did not track stock).
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

    /**
     * FUNCTION PURPOSE: Expose a product's recent stock movements for its admin page.
     *
     * @return list<array<string, mixed>>
     */
    public function recentMovements(int $productId): array
    {
        return $this->stock->recentMovements($productId);
    }

    /**
     * FUNCTION PURPOSE: Compare needed units with shelf stock minus other orders' commitments.
     *
     * @param array<int, int> $needed Stock-tracked product ID => units.
     */
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
