<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MaterialRepository
{
    // Shared by the inventory filter and dashboard; aliases are fixed by callers.
    public const LOW_STOCK_CONDITION = 'material.is_active = 1 AND material.tracks_stock = 1
        AND material.minimum_stock_level IS NOT NULL
        AND material.stock_quantity <= material.minimum_stock_level';


    public function __construct(private readonly PDO $connection)
    {
    }


    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM materials AS material {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(array $criteria, string $sort, string $direction, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
        $sortColumn = match ($sort) {
            'stock_quantity' => 'material.stock_quantity',
            'unit_cost' => 'material.unit_cost',
            default => 'material.name',
        };
        $sortDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        // Untracked materials (NULL stock_quantity) always sort after tracked ones when sorting by stock.
        $nullOrder = $sortColumn === 'material.stock_quantity' ? 'material.stock_quantity IS NULL, ' : '';
        $statement = $this->connection->prepare(
            "SELECT material.id, material.sku, material.name, material.unit, material.stock_quantity,
                    material.unit_cost, material.tracks_stock, material.is_active, material.minimum_stock_level,
                    COALESCE(type_text.name, type.slug) AS type_name
             FROM materials AS material
             INNER JOIN material_types AS type ON type.id = material.material_type_id
             LEFT JOIN material_type_translations AS type_text
               ON type_text.material_type_id = type.id AND type_text.language_code = 'en'
             {$where}
             ORDER BY {$nullOrder}{$sortColumn} {$sortDirection}, material.id ASC
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


    public function find(int $materialId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT material.*, COALESCE(type_text.name, type.slug) AS type_name
             FROM materials AS material
             INNER JOIN material_types AS type ON type.id = material.material_type_id
             LEFT JOIN material_type_translations AS type_text
               ON type_text.material_type_id = type.id AND type_text.language_code = 'en'
             WHERE material.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $materialId]);
        $material = $statement->fetch();

        return $material === false ? null : $material;
    }


    public function findActiveTypes(): array
    {
        $statement = $this->connection->query(
            "SELECT type.id, type.slug, type.is_recipe_input, COALESCE(type_text.name, type.slug) AS name
             FROM material_types AS type
             LEFT JOIN material_type_translations AS type_text
               ON type_text.material_type_id = type.id AND type_text.language_code = 'en'
             WHERE type.is_active = 1
             ORDER BY name ASC, type.id ASC"
        );

        return $statement->fetchAll();
    }


    public function skuExists(string $sku, ?int $excludeId): bool
    {
        $sql = 'SELECT 1 FROM materials WHERE sku = :sku';
        $parameters = ['sku' => $sku];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }


    public function lock(int $id): ?array
    {
        $q = $this->connection->prepare('SELECT * FROM materials WHERE id=:id FOR UPDATE');
        $q->execute(['id' => $id]);
        return $q->fetch() ?: null;
    }

    public function isReferenced(int $materialId): bool
    {
        return (new AdminLifecycleRepository($this->connection))->dependencies('materials', $materialId) !== [];
    }


    public function insert(array $core): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO materials (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active, minimum_stock_level)
             VALUES (:material_type_id, :sku, :name, :unit, :stock_quantity, :unit_cost, :tracks_stock, :is_active, :minimum_stock_level)'
        );
        $statement->execute($core);

        return (int) $this->connection->lastInsertId();
    }


    public function updateEditableFields(int $materialId, array $core): void
    {
        $statement = $this->connection->prepare(
            'UPDATE materials SET name = :name, unit_cost = :unit_cost, is_active = :is_active,
                 minimum_stock_level = :minimum_stock_level WHERE id = :id'
        );
        $statement->execute($core + ['id' => $materialId]);
    }


    public function updateAllFields(int $materialId, array $core): void
    {
        $statement = $this->connection->prepare(
            'UPDATE materials
             SET material_type_id = :material_type_id, sku = :sku, name = :name, unit = :unit,
                 unit_cost = :unit_cost, tracks_stock = :tracks_stock, is_active = :is_active,
                 minimum_stock_level = :minimum_stock_level,
                 stock_quantity = CASE WHEN CAST(:stock_quantity AS DECIMAL(12,3)) IS NULL THEN NULL ELSE COALESCE(stock_quantity, 0.000) END
             WHERE id = :id'
        );
        $statement->execute($core + ['id' => $materialId]);
    }


    private function listWhere(array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['stock_status'] ?? null) === 'low') {
            $conditions[] = self::LOW_STOCK_CONDITION;
        } elseif (($criteria['stock_status'] ?? null) === 'unconfigured') {
            $conditions[] = 'material.is_active = 1 AND material.tracks_stock = 1 AND material.minimum_stock_level IS NULL';
        }

        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(LOWER(material.sku) COLLATE \"C\" LIKE LOWER(:search_sku) ESCAPE '='
                OR LOWER(material.name) COLLATE \"C\" LIKE LOWER(:search_name) ESCAPE '=')";
            $parameters['search_sku'] = $escaped;
            $parameters['search_name'] = $escaped;
        }
        if (($criteria['type'] ?? null) !== null) {
            $conditions[] = 'material.material_type_id = :material_type_id';
            $parameters['material_type_id'] = (string) $criteria['type'];
        }
        if (($criteria['active'] ?? null) !== null) {
            $conditions[] = 'material.is_active = :is_active';
            $parameters['is_active'] = (string) $criteria['active'] === '1' ? '1' : '0';
        }
        if (($criteria['tracks_stock'] ?? null) !== null) {
            $conditions[] = 'material.tracks_stock = :tracks_stock';
            $parameters['tracks_stock'] = (string) $criteria['tracks_stock'] === '1' ? '1' : '0';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }


}
