<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Pricing; it owns the SQL used to read pricing inputs and store calculated catalogue prices.
 *
 * DATA FLOW: App\Services\PricingService calls this layer inside a transaction.
 * The repository reads products, recipes, material costs and set components,
 * and writes back only the current catalogue price columns. It never touches
 * orders or order_items, whose snapshots are the agreed historical prices.
 */

namespace App\Repositories;

use PDO;

final class PricingRepository
{
    public function quantityPrices(int $productId): array
    {
        $q = $this->connection->prepare('SELECT * FROM product_quantity_prices WHERE product_id = :id ORDER BY minimum_quantity');
        $q->execute(['id' => $productId]);
        return $q->fetchAll();
    }

    public function lockProduct(int $id): ?array
    {
        $q = $this->connection->prepare('SELECT * FROM products WHERE id = :id FOR UPDATE');
        $q->execute(['id' => $id]);
        return $q->fetch() ?: null;
    }

    public function insertQuantityPrice(int $id, array $tier): void
    {
        $q = $this->connection->prepare('INSERT INTO product_quantity_prices (product_id, minimum_quantity, maximum_quantity, unit_price, is_active) VALUES (:product, :minimum, :maximum, :price, :active)');
        $q->execute(['product' => $id] + $tier);
    }

    public function removeQuantityPrice(int $product, int $id): void
    {
        $q = $this->connection->prepare('DELETE FROM product_quantity_prices WHERE id = :id AND product_id = :product');
        $q->execute(['id' => $id, 'product' => $product]);
    }

    public function findPromotion(string $code, bool $lock = false): ?array
    {
        $q = $this->connection->prepare('SELECT * FROM promotion_codes WHERE code = :code' . ($lock ? ' FOR SHARE' : ''));
        $q->execute(['code' => $code]);
        return $q->fetch() ?: null;
    }

    public function promotions(): array { return $this->connection->query('SELECT * FROM promotion_codes ORDER BY code')->fetchAll(); }

    public function savePromotion(array $values): void
    {
        $q = $this->connection->prepare('INSERT INTO promotion_codes (code, discount_type, discount_value, is_active, valid_from, valid_until, minimum_subtotal)
            VALUES (:code,:type,:value,:active,:starts,:ends,:minimum)
            ON CONFLICT (code) DO UPDATE SET discount_type=EXCLUDED.discount_type, discount_value=EXCLUDED.discount_value,
                is_active=EXCLUDED.is_active, valid_from=EXCLUDED.valid_from, valid_until=EXCLUDED.valid_until, minimum_subtotal=EXCLUDED.minimum_subtotal');
        $q->execute($values);
    }
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Lock and return every product with the fields the pricing rules need.
     *
     * WHAT: FOR UPDATE locks the product rows until the transaction ends.
     *       Pass false for read-only display (PricingService::quote()).
     * WHY:  recalculations triggered by two admins at once are serialised, so
     *       the second one always prices from the first one's committed data
     *       instead of overwriting it with an older view.
     *
     * @return list<array<string, mixed>>
     */
    public function lockProducts(bool $lock = true): array
    {
        $statement = $this->connection->query(
            'SELECT product.id, category.slug AS category_slug, product.pricing_reference_product_id,
                    product.selling_price, product.actual_cost, product.price_rule,
                    product.price_calculated_at, product.pricing_issue
             FROM products AS product
             LEFT JOIN categories AS category ON category.id = product.category_id
             ORDER BY product.id'
            // PostgreSQL cannot lock the nullable category side of this LEFT JOIN.
            . ($lock ? ' FOR UPDATE OF product' : '')
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Return every recipe line with its material's current unit cost.
     *
     * WHAT: FOR SHARE reads the latest committed costs and blocks a
     *       concurrent cost edit until this recalculation commits.
     *
     * @return list<array{product_id: mixed, recipe_type: mixed, quantity_required: mixed, unit_cost: mixed}>
     */
    public function recipeLines(bool $lock = true): array
    {
        $statement = $this->connection->query(
            'SELECT pm.product_id, pm.recipe_type, pm.quantity_required, material.unit_cost
             FROM product_materials AS pm
             INNER JOIN materials AS material ON material.id = pm.material_id
             ORDER BY pm.product_id'
            . ($lock ? ' FOR SHARE' : '')
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Return every set-to-component link with its quantity.
     *
     * @return list<array{set_product_id: mixed, component_product_id: mixed, quantity: mixed}>
     */
    public function setComponents(bool $lock = true): array
    {
        $statement = $this->connection->query(
            'SELECT set_product_id, component_product_id, quantity
             FROM set_components
             ORDER BY set_product_id'
            . ($lock ? ' FOR SHARE' : '')
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Store the calculated pricing columns for one product.
     *
     * WHAT: when the rules cannot price a product, selling_price is left as it
     *       is (a legacy manual price keeps the storefront working) and only
     *       the explanation columns change.
     *
     * @param array{selling_price: ?string, actual_cost: ?string, price_rule: ?string, price_calculated_at: ?string, pricing_issue: ?string} $pricing
     */
    public function updatePricing(int $productId, array $pricing): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE products
             SET selling_price = COALESCE(:selling_price, selling_price),
                 actual_cost = :actual_cost,
                 price_rule = :price_rule,
                 price_calculated_at = :price_calculated_at,
                 pricing_issue = :pricing_issue
             WHERE id = :id'
        );
        $statement->execute($pricing + ['id' => $productId]);
    }
}
