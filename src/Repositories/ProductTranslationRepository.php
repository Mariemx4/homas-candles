<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Product names, descriptions and their review state, per language. SQL lives only here; services decide what may change. */
final class ProductTranslationRepository
{
    /** Store the shared PDO connection used by every query below. */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Fetch translations and re-key them by language (`en`, `fr`, `ar`) for
     * direct lookup instead of leaving PDO's numeric list of rows.
     */
    public function findTranslations(int $productId): array
    {
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

    /** @return array<string, array<string, mixed>> */
    public function findTranslationReviews(int $productId): array
    {
        $statement = $this->connection->prepare('SELECT * FROM product_translation_reviews WHERE product_id = :id');
        $statement->execute(['id' => $productId]);
        $reviews = [];
        foreach ($statement->fetchAll() as $row) {
            $reviews[(string) $row['language_code']] = $row;
        }
        return $reviews;
    }

    public function findTranslationReview(int $productId, string $languageCode, bool $lock = false): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM product_translation_reviews WHERE product_id = :id AND language_code = :language' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $productId, 'language' => $languageCode]);
        return $statement->fetch() ?: null;
    }

    public function saveTranslationReview(int $productId, string $target, string $source, string $sourceHash, ?string $name, ?string $description, string $status, ?string $error): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO product_translation_reviews
                (product_id, language_code, source_language_code, source_hash, name, description, status, failure_message, generated_at, reviewed_at, reviewed_by)
             VALUES (:id, :target, :source, :source_hash, :name, :description, :status, :error, NOW(), NULL, NULL)
             ON CONFLICT (product_id, language_code) DO UPDATE SET source_language_code = EXCLUDED.source_language_code, source_hash = EXCLUDED.source_hash,
                name = EXCLUDED.name, description = EXCLUDED.description, status = EXCLUDED.status,
                failure_message = EXCLUDED.failure_message, generated_at = NOW(), reviewed_at = NULL, reviewed_by = NULL'
        );
        $statement->execute(compact('name', 'description', 'status') + [
            'id' => $productId, 'target' => $target, 'source' => $source,
            'source_hash' => $sourceHash, 'error' => $error,
        ]);
    }

    public function markTranslationReview(int $productId, string $languageCode, string $status, int $administratorId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE product_translation_reviews SET status = :status, reviewed_at = NOW(), reviewed_by = :administrator
             WHERE product_id = :id AND language_code = :language'
        );
        $statement->execute(['status' => $status, 'administrator' => $administratorId, 'id' => $productId, 'language' => $languageCode]);
    }

    /**
     * Save a translation pair. Null means delete it; otherwise INSERT it or
     * UPDATE the existing row selected by the product/language unique key.
     */
    public function upsertTranslation(int $productId, string $languageCode, ?string $name, ?string $description): void
    {
        if ($name === null || $description === null) {
            $statement = $this->connection->prepare(
                'DELETE FROM product_translations WHERE product_id = :id AND language_code = :language_code'
            );
            $statement->execute(['id' => $productId, 'language_code' => $languageCode]);

            return;
        }
        $statement = $this->connection->prepare(
            'INSERT INTO product_translations (product_id, language_code, name, description)
             VALUES (:id, :language_code, :name, :description)
             ON CONFLICT (product_id, language_code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description'
        );
        $statement->execute(['id' => $productId, 'language_code' => $languageCode, 'name' => $name, 'description' => $description]);
    }
}
