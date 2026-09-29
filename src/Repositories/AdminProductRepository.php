<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Admin Product Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT product.*,
                    category.slug AS category_slug,
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT id, slug FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);
        $category = $statement->fetch();

        return $category === false ? null : $category;
    }

    /** Check whether a category ID exists without fetching an entire record. */
    public function categoryExists(int $categoryId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT 1 FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a chosen product type exists and remains active. */
    public function activeProductTypeExists(int $productTypeId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT 1 FROM product_types WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $productTypeId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check whether a product ID exists. */
    public function productExists(int $productId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('UPDATE products SET main_image_path = :path WHERE id = :id');
        $statement->execute(['path' => $path, 'id' => $productId]);
    }

    /** Persist the active boolean as the database integer 1 or 0. */
    public function setActive(int $productId, bool $active): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('UPDATE products SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $active ? 1 : 0, 'id' => $productId]);
    }

    /**
     * Fetch translations and re-key them by language (`en`, `fr`, `ar`) for
     * direct lookup instead of leaving PDO's numeric list of rows.
     */
    public function findTranslations(int $productId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT language_code, name, description FROM product_translations WHERE product_id = :id'
        );
        $statement->execute(['id' => $productId]);
        // Build `$translations['en']`, `$translations['fr']`, and so on.
        $translations = [];
        foreach ($statement->fetchAll() as $row) {
            $translations[(string) $row['language_code']] = ['name' => (string) $row['name'], 'description' => (string) $row['description']];
        }

        return $translations;
    }

    /**
     * Save a translation pair. Null means delete it; otherwise INSERT it or
     * UPDATE the existing row selected by the product/language unique key.
     */
    public function upsertTranslation(int $productId, string $languageCode, ?string $name, ?string $description): void
    {
        if ($name === null || $description === null) {
            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $statement = $this->connection->prepare(
                'DELETE FROM product_translations WHERE product_id = :id AND language_code = :language_code'
            );
            $statement->execute(['id' => $productId, 'language_code' => $languageCode]);

            return;
        }

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO product_translations (product_id, language_code, name, description)
             VALUES (:id, :language_code, :name, :description)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        $statement->execute(['id' => $productId, 'language_code' => $languageCode, 'name' => $name, 'description' => $description]);
    }

    /** Return colors assigned to the product, including optional image paths. */
    public function findColors(int $productId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT color.id, color.slug, color.hex_code, color.is_active, pc.image_path,
                    COALESCE(color_text.name, color.slug) AS name
             FROM product_colors AS pc
             INNER JOIN colors AS color ON color.id = pc.color_id
             LEFT JOIN color_translations AS color_text
               ON color_text.color_id = color.id AND color_text.language_code = 'en'
             WHERE pc.product_id = :id
             ORDER BY name ASC"
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetchAll();
    }

    /** Check whether the product-color join table already contains this pair. */
    public function colorAssigned(int $productId, int $colorId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_colors WHERE product_id = :product_id AND color_id = :color_id LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a color exists and is active/selectable. */
    public function activeColorExists(int $colorId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT 1 FROM colors WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $colorId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert a relationship between one product and one color. */
    public function addColor(int $productId, int $colorId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO product_colors (product_id, color_id) VALUES (:product_id, :color_id)'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Delete a relationship between one product and one color. */
    public function removeColor(int $productId, int $colorId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'DELETE FROM product_colors WHERE product_id = :product_id AND color_id = :color_id'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Save or clear the image path belonging to an assigned color. */
    public function setColorImage(int $productId, int $colorId, ?string $path): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE product_colors SET image_path = :path WHERE product_id = :product_id AND color_id = :color_id'
        );
        $statement->execute(['path' => $path, 'product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Return a color image path so storage can delete a replaced file. */
    public function findColorImagePath(int $productId, int $colorId): ?string
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT image_path FROM product_colors WHERE product_id = :product_id AND color_id = :color_id LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);
        $path = $statement->fetchColumn();

        return $path === false || $path === null ? null : (string) $path;
    }

    /** Return scent metadata assigned to the product. */
    public function findScents(int $productId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT scent.id, scent.slug, COALESCE(scent_text.name, scent.slug) AS name
             FROM product_scents AS ps
             INNER JOIN scents AS scent ON scent.id = ps.scent_id
             LEFT JOIN scent_translations AS scent_text
               ON scent_text.scent_id = scent.id AND scent_text.language_code = 'en'
             WHERE ps.product_id = :id
             ORDER BY name ASC"
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetchAll();
    }

    /** Check whether the product-scent join table already contains this pair. */
    public function scentAssigned(int $productId, int $scentId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_scents WHERE product_id = :product_id AND scent_id = :scent_id LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a scent exists and is active/selectable. */
    public function activeScentExists(int $scentId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT 1 FROM scents WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $scentId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert a relationship between one product and one scent. */
    public function addScent(int $productId, int $scentId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO product_scents (product_id, scent_id) VALUES (:product_id, :scent_id)'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);
    }

    /** Delete a relationship between one product and one scent. */
    public function removeScent(int $productId, int $scentId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'DELETE FROM product_scents WHERE product_id = :product_id AND scent_id = :scent_id'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);
    }

    /** Return products and configured quantities belonging to one candle set. */
    public function findComponents(int $setProductId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT component.id, component.slug, sc.quantity,
                    COALESCE(component_text.name, component.slug) AS name,
                    category.slug AS category_slug
             FROM set_components AS sc
             INNER JOIN products AS component ON component.id = sc.component_product_id
             LEFT JOIN product_translations AS component_text
               ON component_text.product_id = component.id AND component_text.language_code = 'en'
             LEFT JOIN categories AS category ON category.id = component.category_id
             WHERE sc.set_product_id = :id
             ORDER BY name ASC"
        );
        $statement->execute(['id' => $setProductId]);

        return $statement->fetchAll();
    }

    /** Check whether the set-component join table already contains this pair. */
    public function componentAssigned(int $setProductId, int $componentProductId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT 1 FROM set_components WHERE set_product_id = :set_id AND component_product_id = :component_id LIMIT 1'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert one product component and its quantity into a set. */
    public function addComponent(int $setProductId, int $componentProductId, int $quantity): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO set_components (set_product_id, component_product_id, quantity)
             VALUES (:set_id, :component_id, :quantity)'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId, 'quantity' => $quantity]);
    }

    /** Remove one product component from a set. */
    public function removeComponent(int $setProductId, int $componentProductId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'DELETE FROM set_components WHERE set_product_id = :set_id AND component_product_id = :component_id'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId]);
    }

    /** Count component rows whose products belong to the candles category. */
    public function countCandleComponents(int $setProductId): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM set_components AS sc
             INNER JOIN products AS component ON component.id = sc.component_product_id
             INNER JOIN categories AS category ON category.id = component.category_id
             WHERE sc.set_product_id = :id AND category.slug = 'candles'"
        );
        $statement->execute(['id' => $setProductId]);

        return (int) $statement->fetchColumn();
    }

    /** Check whether a product has at least one positive recipe line of the requested type. */
    public function hasRecipe(int $productId, string $recipeType): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_materials
             WHERE product_id = :product_id AND recipe_type = :recipe_type AND quantity_required > 0
             LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'recipe_type' => $recipeType]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Count assigned scents that are still active.
     *
     * Inactive scents are hidden by the storefront, so they cannot satisfy the
     * "at least one scent" activation requirement (ProductReadinessService).
     */
    public function countActiveAssignedScents(int $productId): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM product_scents AS ps
             INNER JOIN scents AS scent ON scent.id = ps.scent_id AND scent.is_active = 1
             WHERE ps.product_id = :id'
        );
        $statement->execute(['id' => $productId]);
        return (int) $statement->fetchColumn();
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
     * A set may have been configured while a component was active and that
     * component can later be deactivated. Activation therefore rechecks every
     * component instead of trusting the state at assignment time.
     */
    public function allComponentsActive(int $setProductId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM set_components AS sc
             INNER JOIN products AS component ON component.id = sc.component_product_id
             WHERE sc.set_product_id = :id AND component.is_active <> 1'
        );
        $statement->execute(['id' => $setProductId]);
        return (int) $statement->fetchColumn() === 0;
    }

    /**
     * Check both image-owning tables before deleting a physical file. This is
     * intentionally separate from filesystem code: repositories know database
     * references, while ProductImageStorage only knows safe paths.
     */
    public function imagePathInUse(string $path): bool
    {
        $statement = $this->connection->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM products WHERE main_image_path = :product_path)
                + (SELECT COUNT(*) FROM product_colors WHERE image_path = :color_path)
             )'
        );
        $statement->execute(['product_path' => $path, 'color_path' => $path]);
        return (int) $statement->fetchColumn() > 0;
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
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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
            $escaped = '%' . $this->escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(product.slug LIKE :search_slug ESCAPE '='
                OR product_text.name LIKE :search_name ESCAPE '=')";
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

    /** Escape SQL LIKE's chosen escape marker, `%`, and `_` metacharacters. */
    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
