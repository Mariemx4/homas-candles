<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Taxonomy Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

// WHAT: shared CRUD for the four simple lookup tables behind the catalogue
//       (categories, product_types, colors, scents) — each is one row plus
//       a per-language translations table, with 0-1 extra scalar columns.
// WHY:  the four tables are structurally identical (id, slug, is_active,
//       translations), so one config-driven repository replaces four
//       near-duplicate ones. `$kind` is always one of the fixed keys below,
//       set by TaxonomyService — never raw user input — so interpolating
//       the resolved table/column names into SQL is safe, the same way
//       AdministratorAccountRepository::findForLogin() treats its own
//       internally-selected column name.
final class TaxonomyRepository
{
    public const KINDS = ['categories', 'product_types', 'colors', 'scents'];

    private const CONFIG = [
        'categories' => [
            'table' => 'categories', 'translations' => 'category_translations', 'fk' => 'category_id', 'extra' => [],
        ],
        'product_types' => [
            'table' => 'product_types', 'translations' => 'product_type_translations', 'fk' => 'product_type_id', 'extra' => [],
        ],
        'colors' => [
            'table' => 'colors', 'translations' => 'color_translations', 'fk' => 'color_id', 'extra' => ['hex_code'],
        ],
        'scents' => [
            'table' => 'scents', 'translations' => 'scent_translations', 'fk' => 'scent_id', 'extra' => ['fragrance_material_id'],
        ],
    ];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Count for list without loading complete records unnecessarily.
     *
     * @param array<string, string|null> $criteria
     */
    public function countForList(string $kind, array $criteria): int
    {
        $config = self::CONFIG[$kind];
        [$where, $parameters] = $this->listWhere($config, $criteria);
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM {$config['table']} AS entity {$where}"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch for list and return the matching data when it exists.
     *
     * @param array<string, string|null> $criteria
     * @return list<array<string, mixed>>
     */
    public function findForList(string $kind, array $criteria, int $offset, int $limit): array
    {
        $config = self::CONFIG[$kind];
        [$where, $parameters] = $this->listWhere($config, $criteria);
        $extra = $this->extraSelectList($config, 'entity');
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT entity.id, entity.slug, entity.is_active{$extra},
                    COALESCE(translation.name, 'Untranslated') AS name
             FROM {$config['table']} AS entity
             LEFT JOIN {$config['translations']} AS translation
               ON translation.{$config['fk']} = entity.id AND translation.language_code = 'en'
             {$where}
             ORDER BY name ASC, entity.id ASC
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

    /**
     * FUNCTION PURPOSE: Fetch the supplied value and return the matching data when it exists.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $kind, int $id): ?array
    {
        $config = self::CONFIG[$kind];
        $extra = $this->extraSelectList($config, 'entity');
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT entity.id, entity.slug, entity.is_active{$extra}
             FROM {$config['table']} AS entity
             WHERE entity.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $entity = $statement->fetch();
        if ($entity === false) {
            return null;
        }

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $translationStatement = $this->connection->prepare(
            "SELECT language_code, name FROM {$config['translations']} WHERE {$config['fk']} = :id"
        );
        $translationStatement->execute(['id' => $id]);
        $entity['translations'] = ['en' => '', 'fr' => '', 'ar' => ''];
        foreach ($translationStatement->fetchAll() as $row) {
            $entity['translations'][(string) $row['language_code']] = (string) $row['name'];
        }

        return $entity;
    }

    /**
     * FUNCTION PURPOSE: Check whether slug exists without loading a complete record.
     */
    public function slugExists(string $kind, string $slug, ?int $excludeId): bool
    {
        $config = self::CONFIG[$kind];
        $sql = "SELECT 1 FROM {$config['table']} WHERE slug = :slug";
        $parameters = ['slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Insert the supplied value as a new database record.
     *
     * @param array<string, mixed> $core
     */
    public function insert(string $kind, array $core): int
    {
        $config = self::CONFIG[$kind];
        $columns = array_merge(['slug', 'is_active'], $config['extra']);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "INSERT INTO {$config['table']} (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
        );
        $statement->execute(array_intersect_key($core, array_flip($columns)));

        return (int) $this->connection->lastInsertId();
    }

    /**
     * FUNCTION PURPOSE: Update the stored the supplied value values.
     *
     * @param array<string, mixed> $core
     */
    public function update(string $kind, int $id, array $core): void
    {
        $config = self::CONFIG[$kind];
        $columns = array_merge(['slug', 'is_active'], $config['extra']);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", $columns));
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "UPDATE {$config['table']} SET {$assignments} WHERE id = :id"
        );
        $statement->execute(array_intersect_key($core, array_flip($columns)) + ['id' => $id]);
    }

    /**
     * FUNCTION PURPOSE: Set the stored active value explicitly.
     */
    public function setActive(string $kind, int $id, bool $active): void
    {
        $config = self::CONFIG[$kind];
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "UPDATE {$config['table']} SET is_active = :is_active WHERE id = :id"
        );
        $statement->execute(['is_active' => $active ? 1 : 0, 'id' => $id]);
    }

    /**
     * FUNCTION PURPOSE: Insert translation or update the existing matching record.
     */
    public function upsertTranslation(string $kind, int $id, string $languageCode, ?string $name): void
    {
        $config = self::CONFIG[$kind];
        if ($name === null) {
            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $statement = $this->connection->prepare(
                "DELETE FROM {$config['translations']} WHERE {$config['fk']} = :id AND language_code = :language_code"
            );
            $statement->execute(['id' => $id, 'language_code' => $languageCode]);

            return;
        }

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "INSERT INTO {$config['translations']} ({$config['fk']}, language_code, name)
             VALUES (:id, :language_code, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)"
        );
        $statement->execute(['id' => $id, 'language_code' => $languageCode, 'name' => $name]);
    }

    public function fragranceMaterialLinkedToOtherScent(int $materialId, ?int $excludeScentId): bool
    {
        $sql = 'SELECT 1 FROM scents WHERE fragrance_material_id = :material_id';
        $parameters = ['material_id' => $materialId];
        if ($excludeScentId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeScentId;
        }
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Check whether active material exists without loading a complete record.
     */
    public function activeMaterialExists(int $materialId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT 1 FROM materials WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $materialId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Fetch active fragrance material options and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findActiveFragranceMaterialOptions(): array
    {
        $statement = $this->connection->query(
            "SELECT id, name FROM materials WHERE is_active = 1 ORDER BY name ASC, id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch active options and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed, slug: mixed}>
     */
    public function findActiveOptions(string $kind): array
    {
        $config = self::CONFIG[$kind];
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT entity.id, entity.slug,
                    COALESCE(translation.name, entity.slug) AS name
             FROM {$config['table']} AS entity
             LEFT JOIN {$config['translations']} AS translation
               ON translation.{$config['fk']} = entity.id AND translation.language_code = 'en'
             WHERE entity.is_active = 1
             ORDER BY name ASC, entity.id ASC"
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @param array<string, mixed> $config @return array{0: string, 1: array<string, string>}
     */
    private function listWhere(array $config, array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . $this->escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(entity.slug LIKE :search_slug ESCAPE '=' OR translation_search.name LIKE :search_name ESCAPE '=')";
            $parameters['search_slug'] = $escaped;
            $parameters['search_name'] = $escaped;
        }
        if (($criteria['active'] ?? null) !== null) {
            $conditions[] = 'entity.is_active = :is_active';
            $parameters['is_active'] = (string) $criteria['active'] === '1' ? '1' : '0';
        }

        $join = ($criteria['search'] ?? null) !== null
            ? "LEFT JOIN {$config['translations']} AS translation_search
                 ON translation_search.{$config['fk']} = entity.id AND translation_search.language_code = 'en'"
            : '';

        return [$join . ' ' . ($conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions)), $parameters];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function extraSelectList(array $config, string $alias): string
    {
        if ($config['extra'] === []) {
            return '';
        }

        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        return ', ' . implode(', ', array_map(
            static fn (string $column): string => "{$alias}.{$column}",
            $config['extra']
        ));
    }

    /**
     * FUNCTION PURPOSE: Escape like so special characters are treated literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
