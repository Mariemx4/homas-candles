<?php

declare(strict_types=1);

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


    public function __construct(private readonly PDO $connection)
    {
    }


    public function countForList(string $kind, array $criteria): int
    {
        $config = self::CONFIG[$kind];
        [$where, $parameters] = $this->listWhere($config, $criteria);
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM {$config['table']} AS entity {$where}"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(string $kind, array $criteria, int $offset, int $limit): array
    {
        $config = self::CONFIG[$kind];
        [$where, $parameters] = $this->listWhere($config, $criteria);
        $extra = $this->extraSelectList($config, 'entity');
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


    public function dependencies(string $kind, int $id): array
    {
        return (new AdminLifecycleRepository($this->connection))->dependencies($kind, $id);
    }
    public function material(int $id): ?array
    {
        $q = $this->connection->prepare('SELECT id,name,is_active FROM materials WHERE id=:id');
        $q->execute(['id' => $id]);
        return $q->fetch() ?: null;
    }

    public function find(string $kind, int $id, bool $lock = false): ?array
    {
        $config = self::CONFIG[$kind];
        $extra = $this->extraSelectList($config, 'entity');
        $statement = $this->connection->prepare(
            "SELECT entity.id, entity.slug, entity.is_active{$extra}
             FROM {$config['table']} AS entity
             WHERE entity.id = :id
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $id]);
        $entity = $statement->fetch();
        if ($entity === false) {
            return null;
        }
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


    public function slugExists(string $kind, string $slug, ?int $excludeId): bool
    {
        $config = self::CONFIG[$kind];
        $sql = "SELECT 1 FROM {$config['table']} WHERE slug = :slug";
        $parameters = ['slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }


    public function insert(string $kind, array $core): int
    {
        $config = self::CONFIG[$kind];
        $columns = array_merge(['slug', 'is_active'], $config['extra']);
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        $statement = $this->connection->prepare(
            "INSERT INTO {$config['table']} (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
        );
        $statement->execute(array_intersect_key($core, array_flip($columns)));

        return (int) $this->connection->lastInsertId();
    }


    public function update(string $kind, int $id, array $core): void
    {
        $config = self::CONFIG[$kind];
        $columns = array_merge(['slug', 'is_active'], $config['extra']);
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", $columns));
        $statement = $this->connection->prepare(
            "UPDATE {$config['table']} SET {$assignments} WHERE id = :id"
        );
        $statement->execute(array_intersect_key($core, array_flip($columns)) + ['id' => $id]);
    }


    public function setActive(string $kind, int $id, bool $active): void
    {
        $config = self::CONFIG[$kind];
        $statement = $this->connection->prepare(
            "UPDATE {$config['table']} SET is_active = :is_active WHERE id = :id"
        );
        $statement->execute(['is_active' => $active ? 1 : 0, 'id' => $id]);
    }


    public function upsertTranslation(string $kind, int $id, string $languageCode, ?string $name): void
    {
        $config = self::CONFIG[$kind];
        if ($name === null) {
            $statement = $this->connection->prepare(
                "DELETE FROM {$config['translations']} WHERE {$config['fk']} = :id AND language_code = :language_code"
            );
            $statement->execute(['id' => $id, 'language_code' => $languageCode]);

            return;
        }
        $statement = $this->connection->prepare(
            "INSERT INTO {$config['translations']} ({$config['fk']}, language_code, name)
             VALUES (:id, :language_code, :name)
             ON CONFLICT ({$config['fk']}, language_code) DO UPDATE SET name = EXCLUDED.name"
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
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }


    public function activeMaterialExists(int $materialId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM materials WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $materialId]);

        return $statement->fetchColumn() !== false;
    }


    public function findActiveFragranceMaterialOptions(): array
    {
        $statement = $this->connection->query(
            "SELECT id, name FROM materials WHERE is_active = 1 ORDER BY name ASC, id ASC"
        );

        return $statement->fetchAll();
    }


    public function findActiveOptions(string $kind): array
    {
        $config = self::CONFIG[$kind];
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


    private function listWhere(array $config, array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(LOWER(entity.slug) COLLATE \"C\" LIKE LOWER(:search_slug) ESCAPE '='
                OR LOWER(translation_search.name) COLLATE \"C\" LIKE LOWER(:search_name) ESCAPE '=')";
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

        return ', ' . implode(', ', array_map(
            static fn (string $column): string => "{$alias}.{$column}",
            $config['extra']
        ));
    }


}
