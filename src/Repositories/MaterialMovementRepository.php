<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

// WHAT: read-only listing over material_stock_movements.
// WHY:  kept separate from MaterialStockRepository, which is documented as
//       "the only place in the app allowed to change materials.stock_quantity"
//       — these history queries never write, so they live in their own class
//       instead of expanding that one's responsibility.
final class MaterialMovementRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }


    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM material_stock_movements AS movement {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(array $criteria, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT movement.id, movement.movement_type, movement.quantity_change,
                    movement.unit_cost_snapshot, movement.stock_before, movement.stock_after,
                    movement.movement_note, movement.created_at,
                    material.id AS material_id, material.sku AS material_sku, material.name AS material_name,
                    material.unit AS material_unit,
                    orders.order_number,
                    CASE WHEN administrators.id IS NULL THEN NULL
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name
             FROM material_stock_movements AS movement
             INNER JOIN materials AS material ON material.id = movement.material_id
             LEFT JOIN orders ON orders.id = movement.order_id
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = movement.performed_by_administrator_id
             {$where}
             ORDER BY movement.created_at DESC, movement.id DESC
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    private function listWhere(array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['material'] ?? null) !== null) {
            $conditions[] = 'movement.material_id = :material_id';
            $parameters['material_id'] = (string) $criteria['material'];
        }
        if (($criteria['type'] ?? null) !== null) {
            $conditions[] = 'movement.movement_type = :movement_type';
            $parameters['movement_type'] = (string) $criteria['type'];
        }
        if (($criteria['order'] ?? null) !== null) {
            $conditions[] = 'movement.order_id = :order_id';
            $parameters['order_id'] = (string) $criteria['order'];
        }
        if (($criteria['administrator'] ?? null) !== null) {
            $conditions[] = 'movement.performed_by_administrator_id = :administrator_id';
            $parameters['administrator_id'] = (string) $criteria['administrator'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'DATE(movement.created_at) >= :date_from';
            $parameters['date_from'] = (string) $criteria['date_from'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'DATE(movement.created_at) <= :date_to';
            $parameters['date_to'] = (string) $criteria['date_to'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }
}
