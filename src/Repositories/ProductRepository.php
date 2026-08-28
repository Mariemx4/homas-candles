<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProductRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findActiveBySlug(string $slug, string $languageCode = 'en'): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT
                p.id,
                p.slug,
                p.selling_price,
                p.stock_quantity,
                p.minimum_quantity,
                p.purchase_mode,
                p.is_made_to_order,
                p.can_purchase_alone,
                p.main_image_path,
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
            WHERE p.slug = :slug AND p.is_active = 1
            LIMIT 1'
        );
        $statement->execute([
            'category_language' => $languageCode,
            'product_language' => $languageCode,
            'slug' => $slug,
        ]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    public function findActiveColors(int $productId, string $languageCode = 'en'): array
    {
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

    public function findActiveScents(int $productId, string $languageCode = 'en'): array
    {
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
