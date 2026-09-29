<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Material Stock Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use App\Database\TransactionManager;
use DomainException;
use PDO;
use RuntimeException;

// WHAT: the only place in the app allowed to change materials.stock_quantity.
// WHY:  every stock change also needs a row in material_stock_movements
//       (the audit ledger from migration 010) — going through this class
//       instead of a raw UPDATE guarantees the two never drift apart.
final class MaterialStockRepository
{
    private const INBOUND_TYPES = ['STOCK_IN', 'ORDER_RESTOCK', 'ADJUSTMENT_IN'];
    private const OUTBOUND_TYPES = ['ORDER_DEDUCTION', 'ADJUSTMENT_OUT', 'DAMAGED_OR_WASTED'];
    private const ORDER_TYPES = ['ORDER_DEDUCTION', 'ORDER_RESTOCK'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * Updates the material balance and writes its immutable ledger row atomically.
     */
    // WHAT: applies one stock change (e.g. an order deducting wax) and logs it.
    // WHY:  the UPDATE's WHERE clause (below) re-checks the stock won't go
    //       negative at the database level, not just in the PHP validation —
    //       that's what keeps this safe even if two orders hit the same
    //       material's stock at the same moment.
    public function recordMovement(
        int $materialId,
        string $movementType,
        string $quantityChange,
        ?int $orderId = null,
        ?int $administratorId = null,
        ?string $note = null
    ): int {
        $this->validateMovement($movementType, $quantityChange, $orderId, $note);

        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        return $this->transactions->run(function () use (
            $materialId,
            $movementType,
            $quantityChange,
            $orderId,
            $administratorId,
            $note
        ): int {
            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $materialStatement = $this->connection->prepare(
                'SELECT stock_quantity, unit_cost, tracks_stock
                 FROM materials WHERE id = :material_id FOR UPDATE'
            );
            $materialStatement->execute(['material_id' => $materialId]);
            $material = $materialStatement->fetch();

            if ($material === false) {
                throw new RuntimeException('Material not found.');
            }
            if ((int) $material['tracks_stock'] !== 1 || $material['stock_quantity'] === null) {
                throw new DomainException('This material does not track stock.');
            }

            // WHAT: apply the change only if stock won't drop below zero.
            // WHY:  checking `stock_quantity + :minimum_change >= 0` inside
            //       the UPDATE itself (not as a separate SELECT-then-check)
            //       is what makes this safe under concurrent requests — see
            //       rowCount() check right below.
            $updateStatement = $this->connection->prepare(
                'UPDATE materials
                 SET stock_quantity = stock_quantity + :quantity_change
                 WHERE id = :material_id
                   AND stock_quantity + :minimum_change >= 0'
            );
            $updateStatement->execute([
                'quantity_change' => $quantityChange,
                'material_id' => $materialId,
                'minimum_change' => $quantityChange,
            ]);

            // WHAT: if the UPDATE matched zero rows, the guard clause above failed.
            if ($updateStatement->rowCount() !== 1) {
                throw new DomainException('The stock movement would make inventory negative.');
            }

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $afterStatement = $this->connection->prepare(
                'SELECT stock_quantity FROM materials WHERE id = :material_id'
            );
            $afterStatement->execute(['material_id' => $materialId]);
            $stockAfter = $afterStatement->fetchColumn();

            // WHAT: write the audit row with before/after values, matching
            //       the balance we just updated.
            $movementStatement = $this->connection->prepare(
                'INSERT INTO material_stock_movements
                    (material_id, order_id, movement_type, quantity_change,
                     unit_cost_snapshot, stock_before, stock_after,
                     performed_by_administrator_id, movement_note)
                 VALUES
                    (:material_id, :order_id, :movement_type, :quantity_change,
                     :unit_cost_snapshot, :stock_before, :stock_after,
                     :administrator_id, :movement_note)'
            );
            $movementStatement->execute([
                'material_id' => $materialId,
                'order_id' => $orderId,
                'movement_type' => $movementType,
                'quantity_change' => $quantityChange,
                'unit_cost_snapshot' => $material['unit_cost'],
                'stock_before' => $material['stock_quantity'],
                'stock_after' => $stockAfter,
                'administrator_id' => $administratorId,
                'movement_note' => $note,
            ]);

            return (int) $this->connection->lastInsertId();
        });
    }

    // WHAT: mirrors, in PHP, the same rules migration 010's CHECK
    //       constraints enforce in the database (valid type, correct sign,
    //       order_id required/forbidden per type, note required for
    //       adjustments/waste).
    // WHY:  failing here with a clear DomainException is a much better error
    //       than letting MySQL reject the INSERT with a raw constraint
    //       violation — but the database still enforces it too as a backstop.
    private function validateMovement(
        string $movementType,
        string $quantityChange,
        ?int $orderId,
        ?string $note
    ): void {
        $allTypes = array_merge(self::INBOUND_TYPES, self::OUTBOUND_TYPES);
        if (!in_array($movementType, $allTypes, true)) {
            throw new DomainException('Unknown stock movement type.');
        }
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^-?\d+(?:\.\d{1,3})?$/', $quantityChange) !== 1
            || (float) $quantityChange === 0.0
        ) {
            throw new DomainException('Stock quantity change must be a non-zero decimal with at most three places.');
        }
        if (in_array($movementType, self::INBOUND_TYPES, true) && (float) $quantityChange < 0
            || in_array($movementType, self::OUTBOUND_TYPES, true) && (float) $quantityChange > 0
        ) {
            throw new DomainException('Stock movement quantity has the wrong direction.');
        }
        if (in_array($movementType, self::ORDER_TYPES, true) !== ($orderId !== null)) {
            throw new DomainException('Order stock movements require an order; other movements must not have one.');
        }
        if (in_array($movementType, ['ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'DAMAGED_OR_WASTED'], true)
            && ($note === null || trim($note) === '')
        ) {
            throw new DomainException('Manual adjustments, damage, and waste require a note.');
        }
    }
}
