<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Money;
use PDO;

final class OrderStatusRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockOrder(int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, order_number, order_status, delivery_method,
                    total_amount, required_deposit_amount, price_locked_at
             FROM active_orders AS orders
             WHERE id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }

    public function verifiedPaymentTotalForUpdate(int $orderId): string
    {
        $statement = $this->connection->prepare(
            'SELECT payment_status, amount
             FROM payments
             WHERE order_id = :order_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $totalMillis = 0;
        foreach ($statement->fetchAll() as $payment) {
            if ((string) $payment['payment_status'] === 'VERIFIED') {
                $totalMillis += Money::storedMillis((string) $payment['amount']);
            }
        }

        return Money::formatMillis($totalMillis);
    }


    public function hasStockDeduction(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1
             FROM material_stock_movements
             WHERE order_id = :order_id AND movement_type = 'ORDER_DEDUCTION'
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }


    public function hasReachedStatus(int $orderId, string $status): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM order_status_history WHERE order_id = :order_id AND new_status = :status LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId, 'status' => $status]);

        return $statement->fetchColumn() !== false;
    }

    public function hasStartedProduction(int $orderId): bool
    {
        $query = $this->connection->prepare("SELECT 1 FROM order_status_history
            WHERE order_id=:id AND new_status IN ('PREPARING','PREPARED','WAITING_FOR_PICKUP','IN_DELIVERY','PICKED_UP','DELIVERED') LIMIT 1");
        $query->execute(['id' => $orderId]);
        return $query->fetchColumn() !== false;
    }


    public function findMaterialRequirements(int $orderId): array
    {
        $statement = $this->connection->prepare("SELECT recipe.material_id,
            CAST(SUM(recipe.quantity_required * items.quantity) AS DECIMAL(12,3)) AS required_quantity
            FROM order_items items JOIN order_item_materials recipe ON recipe.order_item_id = items.id
            JOIN materials ON materials.id = recipe.material_id
            JOIN material_types ON material_types.id = materials.material_type_id
            WHERE items.order_id = :id AND materials.tracks_stock = 1 AND material_types.is_consumable = 1
            GROUP BY recipe.material_id ORDER BY recipe.material_id");
        $statement->execute(['id' => $orderId]);
        return $statement->fetchAll();
    }


    public function updateStatus(int $orderId, string $currentStatus, string $newStatus): void
    {
        $statement = $this->connection->prepare(
            'UPDATE orders
             SET order_status = :new_status,
                 price_locked_at = CASE
                     WHEN :lock_price = 1 AND price_locked_at IS NULL THEN CURRENT_TIMESTAMP
                     ELSE price_locked_at
                 END
             WHERE id = :order_id AND order_status = :current_status'
        );
        $statement->execute([
            'new_status' => $newStatus,
            'lock_price' => $newStatus === 'PREPARED' ? 1 : 0,
            'order_id' => $orderId,
            'current_status' => $currentStatus,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('The order changed before this transition could be saved. Please review it again.');
        }
    }


    public function addHistory(
        int $orderId,
        string $previousStatus,
        string $newStatus,
        ?int $administratorId,
        ?string $note
    ): void {
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status,
                 changed_by_administrator_id, change_source, change_note)
             VALUES
                (:order_id, :previous_status, :new_status,
                 :administrator_id, 'ADMINISTRATOR', :change_note)"
        );
        $statement->execute([
            'order_id' => $orderId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'administrator_id' => $administratorId,
            'change_note' => $note,
        ]);
    }



}
