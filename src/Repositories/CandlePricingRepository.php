<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Reads and writes the data behind individual-candle prices. Money is kept as NUMERIC strings. */
final class CandlePricingRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** setting_key => value, as floats. */
    public function settings(): array
    {
        $rows = $this->connection->query('SELECT setting_key, setting_value FROM pricing_settings')->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map(static fn ($value): float => (float) $value, $rows);
    }

    public function product(int $productId, bool $lock = false): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.slug, p.selling_price, p.selling_price_source, p.candle_weight_grams, p.is_active,
                    c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id' . ($lock ? ' FOR UPDATE OF p' : '')
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetch() ?: null;
    }

    /** The product's own recipe (its ACTUAL lines), with each material's unit and unit cost. */
    public function recipe(int $productId): array
    {
        $statement = $this->connection->prepare(
            "SELECT m.name, m.unit, m.unit_cost, pm.quantity_required
             FROM product_materials pm
             JOIN materials m ON m.id = pm.material_id
             WHERE pm.product_id = :id AND pm.recipe_type = 'ACTUAL'
             ORDER BY m.sku"
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetchAll();
    }

    /** Ids of every product in the candles category, in catalogue order. */
    public function candleProductIds(): array
    {
        return array_map('intval', $this->connection->query(
            "SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id WHERE c.slug = 'candles' ORDER BY p.id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function setCandleWeight(int $productId, ?string $grams): void
    {
        $statement = $this->connection->prepare('UPDATE products SET candle_weight_grams = :grams WHERE id = :id');
        $statement->execute(['grams' => $grams, 'id' => $productId]);
    }

    public function setSellingPrice(int $productId, string $price, string $source): void
    {
        $statement = $this->connection->prepare('UPDATE products SET selling_price = :price, selling_price_source = :source WHERE id = :id');
        $statement->execute(['price' => $price, 'source' => $source, 'id' => $productId]);
    }

    public function recordPriceChange(int $productId, ?string $previousPrice, ?string $previousSource, string $newPrice, string $newSource, ?int $administratorId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO product_price_history (product_id, previous_price, new_price, previous_source, new_source, administrator_id)
             VALUES (:product, :previous_price, :new_price, :previous_source, :new_source, :administrator)'
        );
        $statement->execute([
            'product' => $productId,
            'previous_price' => $previousPrice,
            'new_price' => $newPrice,
            'previous_source' => $previousSource,
            'new_source' => $newSource,
            'administrator' => $administratorId,
        ]);
    }

    /** Updates one existing pricing setting; a missing key is an error, never a silent insert. */
    public function saveSetting(string $key, string $value): bool
    {
        $statement = $this->connection->prepare('UPDATE pricing_settings SET setting_value = :value WHERE setting_key = :key');
        $statement->execute(['value' => $value, 'key' => $key]);

        return $statement->rowCount() === 1;
    }

    public function priceHistory(int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT previous_price, new_price, previous_source, new_source, changed_at
             FROM product_price_history WHERE product_id = :id ORDER BY changed_at DESC, id DESC LIMIT 20'
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetchAll();
    }
}
