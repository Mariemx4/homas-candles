<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminProductRepository;
use App\Repositories\ProductTranslationRepository;
use DomainException;

/**
 * Product names/descriptions in English, French and Arabic.
 *
 * Owner: catalogue administration, called directly by AdminProductController.
 *
 * Workflow:
 * 1. An administrator saves one language (save). Name and
 *    description are a pair: both filled, or both blank.
 * 2. If a translation generator is configured, drafts for the other languages
 *    are generated (generate). Drafts are stored as PENDING
 *    reviews, never published directly; failures are stored as FAILED so the
 *    edit screen can show them and the administrator can retry.
 * 3. An administrator approves or rejects each draft (review).
 *    Approval is refused if the source text changed after generation, which
 *    is detected with a SHA-256 hash of the source pair.
 *
 * Every published change goes through ProductReadinessService::applyChange(),
 * so clearing a required language on an active product is rejected in the
 * same transaction. Generation itself runs outside a transaction because it
 * calls an external service.
 */
final class ProductTranslationService
{
    /** Languages every product may be translated into, in display order. */
    public const LANGUAGES = ['en', 'fr', 'ar'];
    public const NAME_LIMIT = 180;
    public const DESCRIPTION_LIMIT = 20000;

    public function __construct(
        private readonly AdminProductRepository $products,
        private readonly ProductTranslationRepository $texts,
        private readonly ProductReadinessService $readiness,
        private readonly ?ProductTranslationGenerator $generator = null
    ) {
    }

    /**
     * Save or clear one language's name and description as a pair, then
     * generate drafts for the other languages from it.
     *
     * @return array{success: bool, errors: array<string, string>, notice?: string}
     */
    public function save(int $productId, string $languageCode, string $name, string $description): array
    {
        if (!in_array($languageCode, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported language.']];
        }
        $name = trim($name);
        $description = trim($description);
        $errors = [];
        if ($name !== '' && Text::length($name) > self::NAME_LIMIT) {
            $errors['name'] = 'The name must be 180 characters or fewer.';
        }
        if ($description !== '' && Text::length($description) > self::DESCRIPTION_LIMIT) {
            $errors['description'] = 'The description must be 20,000 characters or fewer.';
        }
        // True only when exactly one of the two fields is empty.
        if (($name === '') !== ($description === '')) {
            $errors['form'] = 'Provide both a name and a description for this language, or leave both blank.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $languageCode, $name, $description): void {
                if (!$this->products->productExists($productId)) {
                    throw new DomainException('The product could not be found.');
                }
                // Empty pairs become SQL NULL: a deliberately absent translation.
                $this->texts->upsertTranslation(
                    $productId,
                    $languageCode,
                    $name === '' ? null : $name,
                    $description === '' ? null : $description
                );
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }

        $generationNotice = $name !== '' ? $this->generate($productId, $languageCode)['notice'] : '';
        return ['success' => true, 'errors' => [], 'notice' => trim(($applied['notice'] ?? '') . ' ' . $generationNotice)];
    }

    /**
     * Generate PENDING review drafts for every other language from a saved source.
     *
     * Each target language is attempted independently; one failure does not
     * stop the others. Nothing here changes the published translations.
     */
    public function generate(int $productId, string $sourceLanguage): array
    {
        if (!in_array($sourceLanguage, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported source language.']];
        }
        $source = $this->texts->findTranslations($productId)[$sourceLanguage] ?? null;
        if ($source === null || trim($source['name']) === '' || trim($source['description']) === '') {
            return ['success' => false, 'errors' => ['form' => 'Save a complete source translation first.']];
        }
        $hash = self::sourceHash($source['name'], $source['description']);
        $pending = 0;
        $failed = 0;
        foreach (self::LANGUAGES as $target) {
            if ($target === $sourceLanguage) {
                continue;
            }
            try {
                if ($this->generator === null) {
                    throw new \RuntimeException('Automatic translation is not configured.');
                }
                $translated = $this->generator->translate($sourceLanguage, $target, $source['name'], $source['description']);
                $candidateName = trim($translated['name']);
                $candidateDescription = trim($translated['description']);
                if ($candidateName === '' || $candidateDescription === ''
                    || Text::length($candidateName) > self::NAME_LIMIT || Text::length($candidateDescription) > self::DESCRIPTION_LIMIT) {
                    throw new \RuntimeException('Generated text was empty or exceeded the product field limits.');
                }
                // The external call can take seconds; refuse a draft whose source
                // was edited meanwhile, so a review never approves stale text.
                $current = $this->texts->findTranslations($productId)[$sourceLanguage] ?? null;
                if ($current === null || self::sourceHash($current['name'], $current['description']) !== $hash) {
                    throw new \RuntimeException('The source changed during generation. Retry from the current text.');
                }
                $this->texts->saveTranslationReview($productId, $target, $sourceLanguage, $hash, $candidateName, $candidateDescription, 'PENDING', null);
                $pending++;
            } catch (\Throwable $exception) {
                // Only our own RuntimeException messages are safe to show; other
                // errors may contain provider details, so they get a generic text.
                $message = $exception instanceof \RuntimeException ? $exception->getMessage() : 'Translation service failed. Retry later.';
                $this->texts->saveTranslationReview($productId, $target, $sourceLanguage, $hash, null, null, 'FAILED', substr($message, 0, 255));
                $failed++;
            }
        }
        return ['success' => true, 'errors' => [], 'notice' => "{$pending} translation(s) pending administrator review; {$failed} failed. View the language cards for details."];
    }

    /** Approve (publish) or reject one PENDING draft after checking its source is unchanged. */
    public function review(int $productId, string $languageCode, bool $approve, int $administratorId): array
    {
        if (!in_array($languageCode, self::LANGUAGES, true)) {
            return ['success' => false, 'errors' => ['form' => 'Unsupported language.']];
        }
        try {
            $applied = $this->readiness->applyChange(function () use ($productId, $languageCode, $approve, $administratorId): void {
                $review = $this->texts->findTranslationReview($productId, $languageCode, true);
                if ($review === null || $review['status'] !== 'PENDING' || trim((string) $review['name']) === '' || trim((string) $review['description']) === '') {
                    throw new DomainException('There is no pending translation to review.');
                }
                if ($approve) {
                    $source = $this->texts->findTranslations($productId)[$review['source_language_code']] ?? null;
                    if ($source === null || self::sourceHash($source['name'], $source['description']) !== $review['source_hash']) {
                        throw new DomainException('The source text has changed. Generate this translation again.');
                    }
                    $this->texts->upsertTranslation($productId, $languageCode, $review['name'], $review['description']);
                }
                $this->texts->markTranslationReview($productId, $languageCode, $approve ? 'APPROVED' : 'REJECTED', $administratorId);
            }, [$productId]);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /** Fingerprint of a source pair; the NUL separator keeps "ab"+"c" distinct from "a"+"bc". */
    private static function sourceHash(string $name, string $description): string
    {
        return hash('sha256', $name . "\0" . $description);
    }
}
