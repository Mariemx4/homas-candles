<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\TaxonomyRepository;
use App\Services\AccessoryStockService;
use App\Services\AdminProductService;
use App\Services\PricingService;
use App\Services\ProductImageStorage;
use App\Services\ProductImageService;
use App\Services\ProductTranslationService;
use App\Services\ProductReadinessService;
use App\Services\ProductTranslationGenerator;

[$db, $databaseName] = create_throwaway_database('translations');
$check = TestCheck::printing();

try {
    $db->exec("INSERT INTO products (id, category_id, product_type_id, slug) VALUES (1, 1, 1, 'translation-test')");
    // A fresh disposable database has no staff; reviews must name a real administrator.
    $db->exec("INSERT INTO administrators_accounts (first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES ('Translation', 'Reviewer', 'translation-reviewer@example.test', '70000001', 'not-a-login-hash', 'ACTIVE')");
    $reviewerId = (int) $db->lastInsertId();
    $generator = new class () implements ProductTranslationGenerator {
        public bool $failArabic = false;

        public function translate(string $sourceLanguage, string $targetLanguage, string $name, string $description): array
        {
            if ($targetLanguage === 'ar' && $this->failArabic) {
                throw new RuntimeException('Provider unavailable.');
            }
            return ['name' => "{$targetLanguage}: {$name}", 'description' => "{$targetLanguage}: {$description}"];
        }
    };
    $transactions = new TransactionManager($db);
    $repository = new AdminProductRepository($db);
    $texts = new \App\Repositories\ProductTranslationRepository($db);
    $pricing = new PricingService(new PricingRepository($db), $transactions);
    $readiness = new ProductReadinessService($repository, $pricing, $transactions, new \App\Repositories\ProductOptionRepository($db), new \App\Repositories\ProductTranslationRepository($db));
    $translations = new ProductTranslationService($repository, new \App\Repositories\ProductTranslationRepository($db), $readiness, $generator);
    $products = new AdminProductService(
        $repository,
        new \App\Repositories\ProductOptionRepository($db),
        new \App\Repositories\ProductTranslationRepository($db),
        new TaxonomyRepository($db),
        $transactions,
        new ProductImageService($repository, new \App\Repositories\ProductOptionRepository($db), $transactions, new ProductImageStorage(PRODUCT_IMAGE_PATH)),
        $pricing,
        $readiness,
        new AccessoryStockService(new AccessoryStockRepository($db), $transactions),
        $translations
    );

    $saved = $translations->save(1, 'en', 'Rose candle', 'Hand poured');
    $detail = $products->detail(1);
    $check($saved['success'] && $detail['translationReviews']['fr']['status'] === 'PENDING'
        && $detail['translationReviews']['ar']['status'] === 'PENDING', 'Saving a source generates two pending drafts.');
    $check(
        $detail['translations']['fr']['name'] === '' && $detail['translations']['ar']['name'] === '',
        'Pending drafts do not become published translations.'
    );
    $items = array_column($detail['readiness']['items'], 'met', 'key');
    $check(!$items['translation_fr'] && !$items['translation_ar'], 'Pending drafts cannot satisfy activation.');

    $approved = $translations->review(1, 'fr', true, $reviewerId);
    $detail = $products->detail(1);
    $check($approved['success'] && $detail['translations']['fr']['name'] === 'fr: Rose candle'
        && $detail['translationReviews']['fr']['status'] === 'APPROVED', 'Approval publishes the reviewed draft.');

    $texts->upsertTranslation(1, 'en', 'Changed source', 'Changed description');
    $stale = $translations->review(1, 'ar', true, $reviewerId);
    $check(
        !$stale['success'] && $products->detail(1)['translations']['ar']['name'] === '',
        'A draft from changed source text cannot be approved.'
    );

    $generator->failArabic = true;
    $retried = $translations->generate(1, 'en');
    $detail = $products->detail(1);
    $check(
        $retried['success'] && $detail['translationReviews']['ar']['status'] === 'FAILED'
        && $detail['translations']['ar']['name'] === '' && $detail['translations']['fr']['name'] === 'fr: Rose candle',
        'Provider failure is visible and leaves published text alone.'
    );
    $generator->failArabic = false;
    $translations->generate(1, 'en');
    $check(
        $translations->review(1, 'ar', true, $reviewerId)['success']
        && array_column($products->detail(1)['readiness']['items'], 'met', 'key')['translation_ar'],
        'An approved generated translation satisfies its activation check.'
    );
    $check(
        $translations->review(1, 'fr', false, $reviewerId)['success']
        && $products->detail(1)['translationReviews']['fr']['status'] === 'REJECTED'
        && $products->detail(1)['translations']['fr']['name'] === 'fr: Rose candle',
        'Rejecting a new draft retains the last approved text.'
    );
} finally {
    drop_throwaway_database($db, $databaseName);
}
