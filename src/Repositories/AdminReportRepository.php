<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;
use PDO;

final class AdminReportRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }


    public function orderSummary(string $start, string $end): array
    {
        // Financial reports include retained orders in Trash; active_orders is for operational lists.
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) AS order_count,
                    SUM(CASE WHEN order_status IN ('PICKED_UP', 'DELIVERED') THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(SUM(CASE WHEN order_status IN ('PICKED_UP', 'DELIVERED') THEN total_amount ELSE 0 END), 0.000) AS completed_value,
                    COALESCE((SELECT SUM(items.quantity * items.unit_cost_snapshot)
                              FROM order_items AS items
                              INNER JOIN orders AS cost_orders ON cost_orders.id = items.order_id
                              WHERE cost_orders.created_at >= :cost_start AND cost_orders.created_at < :cost_end
                                AND cost_orders.order_status IN ('PICKED_UP', 'DELIVERED')
                                AND items.unit_cost_snapshot IS NOT NULL), 0.000) AS known_cost,
                    (SELECT COUNT(*)
                     FROM order_items AS items
                     INNER JOIN orders AS missing_orders ON missing_orders.id = items.order_id
                     WHERE missing_orders.created_at >= :missing_start AND missing_orders.created_at < :missing_end
                       AND missing_orders.order_status IN ('PICKED_UP', 'DELIVERED')
                       AND items.unit_cost_snapshot IS NULL) AS missing_cost_items
             FROM orders
             WHERE created_at >= :order_start AND created_at < :order_end"
        );
        $statement->execute([
            'cost_start' => $start,
            'cost_end' => $end,
            'missing_start' => $start,
            'missing_end' => $end,
            'order_start' => $start,
            'order_end' => $end,
        ]);

        return $statement->fetch() ?: [];
    }


    public function paymentSummary(string $start, string $end): array
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) AS verified_count, COALESCE(SUM(amount), 0.000) AS verified_amount
             FROM payments
             WHERE payment_status = 'VERIFIED'
               AND verified_at >= :range_start AND verified_at < :range_end"
        );
        $statement->execute(['range_start' => $start, 'range_end' => $end]);

        return $statement->fetch() ?: [];
    }


    public function requestSummary(string $start, string $end): array
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) AS request_count,
                    SUM(CASE WHEN request_status = 'CONVERTED' AND converted_order_id IS NOT NULL THEN 1 ELSE 0 END) AS converted_count
             FROM custom_product_requests
             WHERE created_at >= :range_start AND created_at < :range_end"
        );
        $statement->execute(['range_start' => $start, 'range_end' => $end]);

        return $statement->fetch() ?: [];
    }


    public function materialSummary(string $start, string $end): array
    {
        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(CASE WHEN movement_type = 'ORDER_DEDUCTION' THEN ABS(quantity_change) ELSE 0 END), 0.000) AS used_quantity,
                    COALESCE(SUM(CASE WHEN movement_type = 'ORDER_DEDUCTION' THEN ABS(quantity_change) * unit_cost_snapshot ELSE 0 END), 0.000) AS used_value,
                    COALESCE(SUM(CASE WHEN movement_type = 'DAMAGED_OR_WASTED' THEN ABS(quantity_change) ELSE 0 END), 0.000) AS waste_quantity,
                    COALESCE(SUM(CASE WHEN movement_type = 'DAMAGED_OR_WASTED' THEN ABS(quantity_change) * unit_cost_snapshot ELSE 0 END), 0.000) AS waste_value
             FROM material_stock_movements
             WHERE created_at >= :range_start AND created_at < :range_end"
        );
        $statement->execute(['range_start' => $start, 'range_end' => $end]);

        return $statement->fetch() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topItems(string $dimension, string $start, string $end, int $limit = 5): array
    {
        $expressions = [
            'products' => ["items.product_name_snapshot", "items.product_name_snapshot IS NOT NULL AND items.product_name_snapshot <> ''"],
            'scents' => ["items.scent_name_snapshot", "items.scent_name_snapshot IS NOT NULL AND items.scent_name_snapshot <> ''"],
            'colors' => ["items.color_name_snapshot", "items.color_name_snapshot IS NOT NULL AND items.color_name_snapshot <> ''"],
        ];
        if (!isset($expressions[$dimension])) {
            throw new InvalidArgumentException('Unsupported report dimension.');
        }
        [$nameExpression, $presenceCondition] = $expressions[$dimension];
        $statement = $this->connection->prepare(
            "SELECT {$nameExpression} AS item_name, SUM(items.quantity) AS quantity,
                    COALESCE(SUM(items.line_total_snapshot), 0.000) AS value_amount
             FROM order_items AS items
             INNER JOIN orders ON orders.id = items.order_id
             WHERE orders.created_at >= :range_start AND orders.created_at < :range_end
               AND orders.order_status IN ('PICKED_UP', 'DELIVERED')
               AND {$presenceCondition}
             GROUP BY {$nameExpression}
             ORDER BY quantity DESC, value_amount DESC, item_name ASC
             LIMIT :result_limit"
        );
        $statement->bindValue('range_start', $start);
        $statement->bindValue('range_end', $end);
        $statement->bindValue('result_limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    public function countDetails(string $section, string $start, string $end): int
    {
        $definitions = $this->detailDefinitions();
        if (!isset($definitions[$section])) {
            throw new InvalidArgumentException('Unsupported report section.');
        }
        $definition = $definitions[$section];
        if ($section === 'orders') {
            $statement = $this->connection->prepare(
                'SELECT COUNT(*) FROM orders
                 WHERE created_at >= :range_start AND created_at < :range_end'
            );
            $statement->execute(['range_start' => $start, 'range_end' => $end]);

            return (int) $statement->fetchColumn();
        }
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM {$definition['from']} WHERE {$definition['where']}"
        );
        $statement->execute(['range_start' => $start, 'range_end' => $end]);

        return (int) $statement->fetchColumn();
    }


    public function findDetails(string $section, string $start, string $end, int $offset, int $limit): array
    {
        $definitions = $this->detailDefinitions();
        if (!isset($definitions[$section])) {
            throw new InvalidArgumentException('Unsupported report section.');
        }
        $definition = $definitions[$section];
        $statement = $this->connection->prepare(
            "SELECT {$definition['select']} FROM {$definition['from']}
             WHERE {$definition['where']} ORDER BY {$definition['order']}
             LIMIT :result_limit OFFSET :result_offset"
        );
        $statement->bindValue('range_start', $start);
        $statement->bindValue('range_end', $end);
        $statement->bindValue('result_limit', max(1, min($limit, 5000)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    private function detailDefinitions(): array
    {
        return [
            'orders' => [
                'select' => "orders.id, orders.order_number, orders.order_status, orders.order_source,
                    orders.created_at, orders.deleted_at, orders.subtotal, orders.delivery_fee, orders.total_amount,
                    COALESCE(SUM(CASE WHEN items.unit_cost_snapshot IS NOT NULL THEN items.quantity * items.unit_cost_snapshot ELSE 0 END), 0.000) AS known_cost,
                    SUM(CASE WHEN items.id IS NOT NULL AND items.unit_cost_snapshot IS NULL THEN 1 ELSE 0 END) AS missing_cost_items",
                'from' => 'orders LEFT JOIN order_items AS items ON items.order_id = orders.id',
                'where' => 'orders.created_at >= :range_start AND orders.created_at < :range_end GROUP BY orders.id',
                'order' => 'orders.created_at DESC, orders.id DESC',
            ],
            'payments' => [
                'select' => 'payments.id, orders.id AS order_id, orders.order_number, orders.deleted_at AS order_trashed_at, payments.payment_method,
                    payments.payment_type, payments.amount, payments.currency, payments.verified_at',
                'from' => 'payments INNER JOIN orders ON orders.id = payments.order_id',
                'where' => "payments.payment_status = 'VERIFIED' AND payments.verified_at >= :range_start AND payments.verified_at < :range_end",
                'order' => 'payments.verified_at DESC, payments.id DESC',
            ],
            'requests' => [
                'select' => 'requests.id, requests.request_number, requests.request_status,
                    requests.created_at, requests.converted_at, requests.converted_order_id',
                'from' => 'custom_product_requests AS requests',
                'where' => 'requests.created_at >= :range_start AND requests.created_at < :range_end',
                'order' => 'requests.created_at DESC, requests.id DESC',
            ],
            'materials' => [
                'select' => 'movements.id, materials.id AS material_id, materials.sku, materials.name,
                    materials.unit, movements.movement_type, ABS(movements.quantity_change) AS quantity,
                    movements.unit_cost_snapshot,
                    ABS(movements.quantity_change) * movements.unit_cost_snapshot AS value_amount,
                    movements.created_at',
                'from' => 'material_stock_movements AS movements INNER JOIN materials ON materials.id = movements.material_id',
                'where' => "movements.movement_type IN ('ORDER_DEDUCTION', 'DAMAGED_OR_WASTED')
                    AND movements.created_at >= :range_start AND movements.created_at < :range_end",
                'order' => 'movements.created_at DESC, movements.id DESC',
            ],
        ];
    }
}
