<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProductRecipeRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }


    public function findProduct(int $productId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT product.id, product.slug, product.selling_price,
                    category.slug AS category_slug,
                    COALESCE(product_text.name, product.slug, CONCAT('Product #', product.id)) AS name
             FROM products AS product
             LEFT JOIN categories AS category ON category.id = product.category_id
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = product.id AND product_text.language_code = 'en'
             WHERE product.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }


    public function findRecipe(int $productId, string $recipeType): array
    {
        $statement = $this->connection->prepare(
            "SELECT pm.material_id, pm.quantity_required, pm.recipe_type,
                    material.sku, material.name, material.unit, material.unit_cost,
                    ROUND(pm.quantity_required * material.unit_cost, 3) AS line_cost
             FROM product_materials AS pm
             INNER JOIN materials AS material ON material.id = pm.material_id
             WHERE pm.product_id = :product_id AND pm.recipe_type = :recipe_type
             ORDER BY material.name ASC"
        );
        $statement->execute(['product_id' => $productId, 'recipe_type' => $recipeType]);

        return $statement->fetchAll();
    }

    public function totalCost(int $productId, string $recipeType): string
    {
        $statement = $this->connection->prepare(
            "SELECT ROUND(COALESCE(SUM(pm.quantity_required * material.unit_cost), 0.000), 3)
             FROM product_materials AS pm
             INNER JOIN materials AS material ON material.id = pm.material_id
             WHERE pm.product_id = :product_id AND pm.recipe_type = :recipe_type"
        );
        $statement->execute(['product_id' => $productId, 'recipe_type' => $recipeType]);

        return (string) $statement->fetchColumn();
    }


    public function recipeExists(int $productId, int $materialId, string $recipeType): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM product_materials
             WHERE product_id = :product_id AND material_id = :material_id AND recipe_type = :recipe_type
             LIMIT 1'
        );
        $statement->execute(['product_id' => $productId, 'material_id' => $materialId, 'recipe_type' => $recipeType]);

        return $statement->fetchColumn() !== false;
    }


    public function insert(int $productId, int $materialId, string $recipeType, string $quantity): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required)
             VALUES (:product_id, :material_id, :recipe_type, :quantity)'
        );
        $statement->execute([
            'product_id' => $productId,
            'material_id' => $materialId,
            'recipe_type' => $recipeType,
            'quantity' => $quantity,
        ]);
    }


    public function updateQuantity(int $productId, int $materialId, string $recipeType, string $quantity): void
    {
        $statement = $this->connection->prepare(
            'UPDATE product_materials SET quantity_required = :quantity
             WHERE product_id = :product_id AND material_id = :material_id AND recipe_type = :recipe_type'
        );
        $statement->execute([
            'quantity' => $quantity,
            'product_id' => $productId,
            'material_id' => $materialId,
            'recipe_type' => $recipeType,
        ]);
    }


    public function remove(int $productId, int $materialId, string $recipeType): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM product_materials
             WHERE product_id = :product_id AND material_id = :material_id AND recipe_type = :recipe_type'
        );
        $statement->execute(['product_id' => $productId, 'material_id' => $materialId, 'recipe_type' => $recipeType]);
    }


    public function findMaterialForRecipe(int $materialId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT material.id, material.name, material.is_active, type.is_recipe_input
             FROM materials AS material
             INNER JOIN material_types AS type ON type.id = material.material_type_id
             WHERE material.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $materialId]);
        $material = $statement->fetch();

        return $material === false ? null : $material;
    }


    public function findRecipeEligibleMaterials(): array
    {
        $statement = $this->connection->query(
            "SELECT material.id, material.sku, material.name, material.unit
             FROM materials AS material
             INNER JOIN material_types AS type ON type.id = material.material_type_id
             WHERE material.is_active = 1 AND type.is_recipe_input = 1
             ORDER BY material.name ASC"
        );

        return $statement->fetchAll();
    }
}
