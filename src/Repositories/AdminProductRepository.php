<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Product records: listing, lookup, slugs, core columns, activation and the main image. SQL lives only here; services decide what may change. */
final class AdminProductRepository
{
    /** Store the shared PDO connection used by every query below. */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Count products matching normalized filters; pagination uses this total.
     * listWhere() returns SQL structure separately from safely bound values.
     *
     * @param array<string, string|null> $criteria
     */
    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM products AS product
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = product.id AND product_text.language_code = 'en'
             {$where}"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * Fetch one page of product summaries. LIMIT controls the number of rows;
     * OFFSET controls how many matching rows are skipped before this page.
     *
     * @param array<string, string|null> $criteria
     * @return list<array<string, mixed>>
     */
    public function findForList(array $criteria, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT product.id, product.slug, product.selling_price, product.purchase_mode,
                    product.is_active, product.main_image_path,
                    COALESCE(product_text.name, 'Untranslated product') AS name,
                    COALESCE(category_text.name, 'No category') AS category_name
             FROM products AS product
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = product.id AND product_text.language_code = 'en'
             LEFT JOIN categories AS category ON category.id = product.category_id
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = category.id AND category_text.language_code = 'en'
             {$where}
             ORDER BY product.id DESC
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

    /** Return one product plus readable category/type metadata, or null. */
    public function find(int $productId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT product.*,
                    category.slug AS category_slug, category.is_active AS category_is_active, product_type.is_active AS type_is_active,
                    COALESCE(category_text.name, category.slug) AS category_name,
                    product_type.slug AS product_type_slug,
                    reference.slug AS pricing_reference_slug,
                    COALESCE(reference_text.name, reference.slug) AS pricing_reference_name
             FROM products AS product
             LEFT JOIN categories AS category ON category.id = product.category_id
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = category.id AND category_text.language_code = 'en'
             LEFT JOIN product_types AS product_type ON product_type.id = product.product_type_id
             LEFT JOIN products AS reference ON reference.id = product.pricing_reference_product_id
             LEFT JOIN product_translations AS reference_text
               ON reference_text.product_id = reference.id AND reference_text.language_code = 'en'
             WHERE product.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    /** Lock one product row against competing writes until transaction end. */
    public function lockCore(int $productId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, category_id, product_type_id, pricing_reference_product_id, slug,
                    selling_price, stock_quantity, candle_count, minimum_quantity, purchase_mode,
                    is_made_to_order, can_purchase_alone, main_image_path, is_active
             FROM products WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    /** Return the minimal category fields required by service validation. */
    public function findCategory(int $categoryId): ?array
    {
        $statement = $this->connection->prepare('SELECT id, slug, is_active FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);
        $category = $statement->fetch();

        return $category === false ? null : $category;
    }

    /** Check whether a category ID exists without fetching an entire record. */
    public function categoryExists(int $categoryId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a chosen product type exists and remains active. */
    public function activeProductTypeExists(int $productTypeId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM product_types WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $productTypeId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check whether a product ID exists. */
    public function productExists(int $productId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM products WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $productId]);

        return $statement->fetchColumn() !== false;
    }

    /** Detect a duplicate slug, excluding the currently edited product if any. */
    public function slugExists(string $slug, ?int $excludeId): bool
    {
        $sql = 'SELECT 1 FROM products WHERE slug = :slug';
        $parameters = ['slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        $statement = $this->connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Insert validated core fields and return the generated product ID.
     *
     * selling_price starts NULL; PricingService fills it in the same transaction.
     */
    public function insertCore(array $core): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO products
                (category_id, product_type_id, pricing_reference_product_id, slug, selling_price,
                 stock_quantity, candle_count, minimum_quantity, purchase_mode, is_made_to_order,
                 can_purchase_alone, is_active)
             VALUES
                (:category_id, :product_type_id, :pricing_reference_product_id, :slug, NULL,
                 :stock_quantity, :candle_count, :minimum_quantity, :purchase_mode, :is_made_to_order,
                 :can_purchase_alone, 0)'
        );
        $statement->execute($core);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the editable core fields of an existing product.
     *
     * selling_price is deliberately absent: only PricingService writes it.
     */
    public function updateCore(int $productId, array $core): void
    {
        $statement = $this->connection->prepare(
            'UPDATE products
             SET category_id = :category_id, product_type_id = :product_type_id,
                 pricing_reference_product_id = :pricing_reference_product_id, slug = :slug,
                 stock_quantity = :stock_quantity,
                 candle_count = :candle_count, minimum_quantity = :minimum_quantity,
                 purchase_mode = :purchase_mode, is_made_to_order = :is_made_to_order,
                 can_purchase_alone = :can_purchase_alone
             WHERE id = :id'
        );
        $statement->execute($core + ['id' => $productId]);
    }

    /** Save or clear the public main-image path. */
    public function setMainImage(int $productId, ?string $path): void
    {
        $statement = $this->connection->prepare('UPDATE products SET main_image_path = :path WHERE id = :id');
        $statement->execute(['path' => $path, 'id' => $productId]);
    }

    /** Persist the active boolean as the database integer 1 or 0. */
    public function setActive(int $productId, bool $active): void
    {
        $statement = $this->connection->prepare('UPDATE products SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $active ? 1 : 0, 'id' => $productId]);
    }

    /**
     * Return the IDs of every active product, locked until the transaction ends.
     *
     * FOR UPDATE stops a concurrent activation from slipping in while
     * ProductReadinessService is returning broken products to draft.
     *
     * @return list<int>
     */
    public function findActiveProductIds(): array
    {
        $statement = $this->connection->query('SELECT id FROM products WHERE is_active = 1 ORDER BY id FOR UPDATE');

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return compact active-product options for relationship select controls.
     * Optional arguments narrow a category or exclude the current product.
     */
    public function findActiveProductOptions(?string $categorySlug, ?int $excludeId): array
    {
        $sql = "SELECT product.id, product.slug, COALESCE(product_text.name, product.slug) AS name
                FROM products AS product
                LEFT JOIN product_translations AS product_text
                  ON product_text.product_id = product.id AND product_text.language_code = 'en'
                LEFT JOIN categories AS category ON category.id = product.category_id
                WHERE product.is_active = 1";
        $parameters = [];
        if ($categorySlug !== null) {
            $sql .= ' AND category.slug = :category_slug';
            $parameters['category_slug'] = $categorySlug;
        }
        if ($excludeId !== null) {
            $sql .= ' AND product.id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        $statement = $this->connection->prepare($sql . ' ORDER BY name ASC, product.id ASC');
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * Translate normalized filters into a SQL WHERE fragment and parameters.
     * Fixed code builds SQL clauses; user values remain separately bound.
     *
     * @param array<string, string|null> $criteria
     * @return array{0: string, 1: array<string, string>}
     */
    private function listWhere(array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            // Surround with `%` for contains-search after escaping wildcard
            // characters the user meant literally.
            $escaped = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(LOWER(product.slug) COLLATE \"C\" LIKE LOWER(:search_slug) ESCAPE '='
                OR LOWER(product_text.name) COLLATE \"C\" LIKE LOWER(:search_name) ESCAPE '=')";
            $parameters['search_slug'] = $escaped;
            $parameters['search_name'] = $escaped;
        }
        if (($criteria['category'] ?? null) !== null) {
            $conditions[] = 'product.category_id = :category_id';
            $parameters['category_id'] = (string) $criteria['category'];
        }
        if (($criteria['type'] ?? null) !== null) {
            $conditions[] = 'product.product_type_id = :product_type_id';
            $parameters['product_type_id'] = (string) $criteria['type'];
        }
        if (($criteria['active'] ?? null) !== null) {
            $conditions[] = 'product.is_active = :is_active';
            $parameters['is_active'] = (string) $criteria['active'] === '1' ? '1' : '0';
        }
        if (($criteria['purchase_mode'] ?? null) !== null) {
            $conditions[] = 'product.purchase_mode = :purchase_mode';
            $parameters['purchase_mode'] = (string) $criteria['purchase_mode'];
        }

        // No filters means no WHERE clause. Otherwise every condition must be
        // true because they are joined with SQL AND.
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }
}
