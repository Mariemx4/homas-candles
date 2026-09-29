<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Product Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class ProductRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array<string, string> Active product type slugs and English names. */
    public function activeCatalogueTypes(): array
    {
        $statement = $this->connection->query(
            "SELECT t.slug, tt.name
             FROM product_types t
             INNER JOIN product_type_translations tt
                ON tt.product_type_id = t.id AND tt.language_code = 'en'
             WHERE t.is_active = 1
             ORDER BY tt.name, t.id"
        );

        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function countActiveForCatalogue(?string $categorySlug, ?string $typeSlug, string $search): int
    {
        [$fromWhere, $parameters] = $this->catalogueFilter($categorySlug, $typeSlug, $search);
        $statement = $this->connection->prepare('SELECT COUNT(*) ' . $fromWhere);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function findActiveForCatalogue(
        ?string $categorySlug,
        ?string $typeSlug,
        string $search,
        string $sort,
        int $limit,
        int $offset
    ): array {
        [$fromWhere, $parameters] = $this->catalogueFilter($categorySlug, $typeSlug, $search);
        // The schema has no featured rank or creation timestamp. Stable ID order
        // is the editorial default, and descending ID is the newest-first proxy.
        $order = match ($sort) {
            'newest' => 'p.id DESC',
            'price-low' => "CASE WHEN p.purchase_mode = 'REQUEST_ONLY' OR c.slug IN ('bouquets', 'event-favors') OR p.selling_price IS NULL THEN 1 ELSE 0 END, p.selling_price ASC, p.id DESC",
            'price-high' => "CASE WHEN p.purchase_mode = 'REQUEST_ONLY' OR c.slug IN ('bouquets', 'event-favors') OR p.selling_price IS NULL THEN 1 ELSE 0 END, p.selling_price DESC, p.id DESC",
            default => 'p.id ASC',
        };
        $statement = $this->connection->prepare(
            "SELECT p.id, p.slug, p.selling_price, p.stock_quantity,
                    CASE WHEN c.slug IN ('bouquets', 'event-favors')
                         THEN 'REQUEST_ONLY' ELSE p.purchase_mode END AS purchase_mode,
                    p.is_made_to_order, p.main_image_path,
                    ct.name AS category_name, pt.name, pt.description
             " . $fromWhere . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array{string, array<string, string>} */
    private function catalogueFilter(?string $categorySlug, ?string $typeSlug, string $search): array
    {
        $sql = "FROM products p
                INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                INNER JOIN category_translations ct
                    ON ct.category_id = c.id AND ct.language_code = 'en'
                INNER JOIN product_translations pt
                    ON pt.product_id = p.id AND pt.language_code = 'en'
                LEFT JOIN product_types t ON t.id = p.product_type_id
                WHERE p.is_active = 1 AND p.slug IS NOT NULL
                  AND p.main_image_path IS NOT NULL AND p.main_image_path <> ''";
        $parameters = [];
        if ($categorySlug !== null) {
            $sql .= ' AND c.slug = :category_slug';
            $parameters['category_slug'] = $categorySlug;
        }
        if ($typeSlug !== null) {
            // WHAT: a product matches its own type, and a candle set also
            //       matches every type of the products it contains.
            // WHY:  documented rule — "A candle set appears under every product
            //       type represented by its component products", and its
            //       visibility follows component changes automatically. Reading
            //       set_components live (instead of copying types onto the set)
            //       means there is nothing to keep in sync when an admin edits a set.
            $sql .= ' AND (
                    (t.slug = :type_slug AND t.is_active = 1)
                    OR EXISTS (
                        SELECT 1
                        FROM set_components sc
                        INNER JOIN products component ON component.id = sc.component_product_id
                        INNER JOIN product_types component_type
                            ON component_type.id = component.product_type_id AND component_type.is_active = 1
                        WHERE sc.set_product_id = p.id AND component_type.slug = :component_type_slug
                    )
                )';
            $parameters['type_slug'] = $typeSlug;
            // Native prepared statements cannot reuse one named placeholder twice.
            $parameters['component_type_slug'] = $typeSlug;
        }
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $sql .= " AND (pt.name LIKE :search_name ESCAPE '!'
                      OR pt.description LIKE :search_description ESCAPE '!'
                      OR p.slug LIKE :search_slug ESCAPE '!')";
            $parameters['search_name'] = $pattern;
            $parameters['search_description'] = $pattern;
            $parameters['search_slug'] = $pattern;
        }

        return [$sql, $parameters];
    }

    /**
     * FUNCTION PURPOSE: Fetch active by slug and return the matching data when it exists.
     */
    public function findActiveBySlug(string $slug, string $languageCode = 'en'): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT
                p.id,
                p.slug,
                p.selling_price,
                p.stock_quantity,
                p.minimum_quantity,
                CASE WHEN c.slug IN ('bouquets', 'event-favors')
                    THEN 'REQUEST_ONLY' ELSE p.purchase_mode END AS purchase_mode,
                p.is_made_to_order,
                p.can_purchase_alone,
                p.main_image_path,
                p.category_id,
                c.slug AS category_slug,
                ct.name AS category_name,
                pt.name,
                pt.description
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            INNER JOIN category_translations ct
                ON ct.category_id = c.id AND ct.language_code = :category_language
            INNER JOIN product_translations pt
                ON pt.product_id = p.id AND pt.language_code = :product_language
            WHERE p.slug = :slug AND p.is_active = 1 AND c.is_active = 1
            LIMIT 1"
        );
        $statement->execute([
            'category_language' => $languageCode,
            'product_language' => $languageCode,
            'slug' => $slug,
        ]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    /**
     * FUNCTION PURPOSE: Fetch the component products of one candle set, with the quantity each set contains.
     *
     * WHAT: every configured component is returned, plus an `is_available`
     *       flag (component and its category both active, and it has a slug).
     * WHY:  a component can be deactivated after the set was published. The
     *       set still physically contains it, so the page lists it, but it
     *       must not link to a product page that would now show "not available".
     *
     * @return list<array<string, mixed>>
     */
    public function findSetComponents(int $setProductId, string $languageCode = 'en'): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT
                component.id,
                component.slug,
                sc.quantity,
                component.selling_price,
                component.main_image_path,
                CASE WHEN category.slug IN ('bouquets', 'event-favors')
                    THEN 'REQUEST_ONLY' ELSE component.purchase_mode END AS purchase_mode,
                category.slug AS category_slug,
                COALESCE(category_text.name, category.slug) AS category_name,
                COALESCE(component_text.name, component.slug) AS name,
                type_text.name AS type_name,
                CASE WHEN component.is_active = 1 AND category.is_active = 1 AND component.slug IS NOT NULL
                    THEN 1 ELSE 0 END AS is_available
            FROM set_components sc
            INNER JOIN products component ON component.id = sc.component_product_id
            INNER JOIN categories category ON category.id = component.category_id
            LEFT JOIN category_translations category_text
                ON category_text.category_id = category.id AND category_text.language_code = :category_language
            LEFT JOIN product_translations component_text
                ON component_text.product_id = component.id AND component_text.language_code = :product_language
            LEFT JOIN product_type_translations type_text
                ON type_text.product_type_id = component.product_type_id AND type_text.language_code = :type_language
            WHERE sc.set_product_id = :set_id
            ORDER BY CASE WHEN category.slug = 'accessories' THEN 1 ELSE 0 END, name, component.id"
        );
        $statement->execute([
            'category_language' => $languageCode,
            'product_language' => $languageCode,
            'type_language' => $languageCode,
            'set_id' => $setProductId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch active colors and return the matching data when it exists.
     */
    public function findActiveColors(int $productId, string $languageCode = 'en'): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT c.id, c.hex_code, ct.name, pc.image_path
            FROM product_colors pc
            INNER JOIN colors c ON c.id = pc.color_id AND c.is_active = 1
            INNER JOIN color_translations ct
                ON ct.color_id = c.id AND ct.language_code = :language_code
            WHERE pc.product_id = :product_id
            ORDER BY c.id'
        );
        $statement->execute([
            'language_code' => $languageCode,
            'product_id' => $productId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch active scents and return the matching data when it exists.
     */
    public function findActiveScents(int $productId, string $languageCode = 'en'): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT s.id, st.name
            FROM product_scents ps
            INNER JOIN scents s ON s.id = ps.scent_id AND s.is_active = 1
            INNER JOIN scent_translations st
                ON st.scent_id = s.id AND st.language_code = :language_code
            WHERE ps.product_id = :product_id
            ORDER BY s.id'
        );
        $statement->execute([
            'language_code' => $languageCode,
            'product_id' => $productId,
        ]);

        return $statement->fetchAll();
    }
}
