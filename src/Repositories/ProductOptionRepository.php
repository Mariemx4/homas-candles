<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Colours, scents, set components, recipes and colour photos attached to a product. SQL lives only here; services decide what may change. */
final class ProductOptionRepository
{
    /** Store the shared PDO connection used by every query below. */
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Return colors assigned to the product, including optional image paths. */
    public function findColors(int $productId): array
    {
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
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_colors WHERE product_id = :product_id AND color_id = :color_id LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a color exists and is active/selectable. */
    public function activeColorExists(int $colorId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM colors WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $colorId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert a relationship between one product and one color. */
    public function addColor(int $productId, int $colorId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO product_colors (product_id, color_id) VALUES (:product_id, :color_id)'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Delete a relationship between one product and one color. */
    public function removeColor(int $productId, int $colorId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM product_colors WHERE product_id = :product_id AND color_id = :color_id'
        );
        $statement->execute(['product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Save or clear the image path belonging to an assigned color. */
    public function setColorImage(int $productId, int $colorId, ?string $path): void
    {
        $statement = $this->connection->prepare(
            'UPDATE product_colors SET image_path = :path WHERE product_id = :product_id AND color_id = :color_id'
        );
        $statement->execute(['path' => $path, 'product_id' => $productId, 'color_id' => $colorId]);
    }

    /** Return a color image path so storage can delete a replaced file. */
    public function findColorImagePath(int $productId, int $colorId): ?string
    {
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
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_scents WHERE product_id = :product_id AND scent_id = :scent_id LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);

        return $statement->fetchColumn() !== false;
    }

    /** Check that a scent exists and is active/selectable. */
    public function activeScentExists(int $scentId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM scents WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $scentId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert a relationship between one product and one scent. */
    public function addScent(int $productId, int $scentId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO product_scents (product_id, scent_id) VALUES (:product_id, :scent_id)'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);
    }

    /** Delete a relationship between one product and one scent. */
    public function removeScent(int $productId, int $scentId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM product_scents WHERE product_id = :product_id AND scent_id = :scent_id'
        );
        $statement->execute(['product_id' => $productId, 'scent_id' => $scentId]);
    }

    /** Return products and configured quantities belonging to one candle set. */
    public function findComponents(int $setProductId): array
    {
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
        $statement = $this->connection->prepare(
            'SELECT 1 FROM set_components WHERE set_product_id = :set_id AND component_product_id = :component_id LIMIT 1'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId]);

        return $statement->fetchColumn() !== false;
    }

    /** Insert one product component and its quantity into a set. */
    public function addComponent(int $setProductId, int $componentProductId, int $quantity): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO set_components (set_product_id, component_product_id, quantity)
             VALUES (:set_id, :component_id, :quantity)'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId, 'quantity' => $quantity]);
    }

    /** Remove one product component from a set. */
    public function removeComponent(int $setProductId, int $componentProductId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM set_components WHERE set_product_id = :set_id AND component_product_id = :component_id'
        );
        $statement->execute(['set_id' => $setProductId, 'component_id' => $componentProductId]);
    }

    /** Count component rows whose products belong to the candles category. */
    public function countCandleComponents(int $setProductId): int
    {
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
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM product_scents AS ps
             INNER JOIN scents AS scent ON scent.id = ps.scent_id AND scent.is_active = 1
             WHERE ps.product_id = :id'
        );
        $statement->execute(['id' => $productId]);
        return (int) $statement->fetchColumn();
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
}
