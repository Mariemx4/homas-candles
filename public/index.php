<?php


// WHAT: the homepage — hero, category carousel, brand story, and the two
//       "add your content here" placeholder sections below.
require_once __DIR__ . '/../app/Bootstrap/autoload.php';
$pageTitle = \App\Services\Locale::text('nav.home') . " | Hom'As Candles";
require_once VIEW_PATH . '/Layouts/header.php';
$categoryPresentation = [
  'candles' => ['image' => 'assets/brand/candle.jpg', 'body' => 'home.candles_body', 'action' => 'home.view_candles'],
  'candle-sets' => ['image' => 'assets/brand/candleSet.jpg', 'body' => 'home.sets_body', 'action' => 'home.view_sets'],
  'bouquets' => ['image' => 'assets/brand/bouquet.jpg', 'body' => 'home.bouquets_body', 'action' => 'home.view_bouquets'],
  'event-favors' => ['image' => 'assets/brand/eventFavors.jpg', 'body' => 'home.favors_body', 'action' => 'home.view_favors'],
  'home-diffusers' => ['body' => 'home.diffusers_body', 'action' => 'home.view_diffusers'],
  'accessories' => ['body' => 'home.accessories_body', 'action' => 'home.view_accessories'],
];
?>

<main id="main-content" tabindex="-1" class="home-page">
  <section class="hero">
    <p class="home-eyebrow"><?php echo site_text('footer.hand_poured'); ?></p>
    <h1>Hom'As Candles</h1>
    <p><?php echo site_text('footer.tagline'); ?></p>
    <a class="hero-order-link" href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('home.shop_now'); ?></a>

    <a class="hero-featured-link" href="<?php echo BASE_URL . 'shop.php'; ?>">
      <img src="<?php echo BASE_URL . 'assets/brand/bestSeller.jpg'; ?>"
        alt="Featured Hom'As candle" class="hero-image">
      <span><?php echo site_text('home.best_seller'); ?></span>
    </a>
  </section>

  <!-- WHAT: the "Explore our creations" carousel — driven by carousel.js -->
  <section class="categories-cards carousel">
    <p class="home-eyebrow"><?php echo site_text('home.moments'); ?></p>
    <h2><?php echo site_text('home.explore'); ?></h2>

    <div class="categories-grid carousel-track">
      <?php foreach ($catalogueCategories as $index => $category): ?>
        <?php $presentation = $categoryPresentation[$category['slug']] ?? []; ?>
        <article class="category-card carousel-slide<?php echo $index === 0 ? ' is-active' : ''; ?>">
          <?php if (isset($presentation['image'])): ?>
            <img src="<?php echo BASE_URL . $presentation['image']; ?>" alt="<?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>">
          <?php else: ?>
            <div class="category-card__media-placeholder" role="img" aria-label="<?php echo site_text('home.image_soon'); ?>"><?php echo site_text('home.image_soon'); ?></div>
          <?php endif; ?>
          <h3><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
          <p><?php echo site_text($presentation['body'] ?? 'category.generic.description'); ?></p>
          <a href="<?php echo htmlspecialchars(BASE_URL . $category['path'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo site_text($presentation['action'] ?? 'home.view_collection'); ?></a>
        </article>
      <?php endforeach; ?>

      <div class="carousel-controls">
        <button class="carousel-button carousel-button--previous" type="button"
          aria-label="Previous featured creation">
          <i data-lucide="arrow-left" aria-hidden="true"></i>
        </button>
        <p class="carousel-status" aria-live="polite"><?php echo $catalogueCategories === [] ? '0' : '1'; ?> <?php echo site_text('catalogue.of'); ?> <?php echo count($catalogueCategories); ?></p>
        <button class="carousel-button carousel-button--next" type="button"
          aria-label="Next featured creation">
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  </section>

  <!-- Featured products have no curated source yet. -->
  <section class="featured-products">
    <h2><?php echo site_text('home.featured'); ?></h2>
    <div class="products-grid">
      <!-- Add your featured products here / php -->
    </div>
  </section>

  <section class="call-to-action">
    <p class="home-eyebrow"><?php echo site_text('home.bring_warmth'); ?></p>
    <h2><?php echo site_text('home.ready'); ?></h2>
    <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('home.shop_now'); ?></a>
  </section>

  <section class="homas-story carousel">
    <p class="home-eyebrow"><?php echo site_text('home.behind'); ?></p>
    <h2><?php echo site_text('footer.story'); ?></h2>
    <ul class="carousel-track">
      <li class="carousel-slide is-active">
        <h3><?php echo site_text('home.story_1_title'); ?></h3>
        <p><?php echo site_text('home.story_1_body'); ?></p>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.story_2_title'); ?></h3>
        <p><?php echo site_text('home.story_2_body'); ?></p>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.story_3_title'); ?></h3>
        <p><?php echo site_text('home.story_3_body'); ?></p>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.story_4_title'); ?></h3>
        <p><?php echo site_text('home.story_4_body'); ?></p>
      </li>
    </ul>
    <div class="carousel-controls">
      <button class="carousel-button carousel-button--previous" type="button"
        aria-label="Previous Hom'As Story"><i data-lucide="arrow-left" aria-hidden="true"></i></button>
      <p class="carousel-status" aria-live="polite">1 of 4</p>
      <button class="carousel-button carousel-button--next" type="button"
        aria-label="Next Hom'As Story"><i data-lucide="arrow-right" aria-hidden="true"></i></button>
    </div>
  </section>

  <section class="why-homas carousel">
    <p class="home-eyebrow"><?php echo site_text('home.difference'); ?></p>
    <h2><?php echo site_text('home.why'); ?></h2>
    <ul class="carousel-track">
      <li class="carousel-slide is-active">
        <h3><?php echo site_text('home.why_1'); ?></h3>
        <div class="why-homas__media-placeholder" aria-hidden="true"><?php echo site_text('home.image_soon'); ?></div>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.why_2'); ?></h3>
        <div class="why-homas__media-placeholder" aria-hidden="true"><?php echo site_text('home.image_soon'); ?></div>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.why_3'); ?></h3>
        <div class="why-homas__media-placeholder" aria-hidden="true"><?php echo site_text('home.image_soon'); ?></div>
      </li>
      <li class="carousel-slide">
        <h3><?php echo site_text('home.why_4'); ?></h3>
        <div class="why-homas__media-placeholder" aria-hidden="true"><?php echo site_text('home.image_soon'); ?></div>
      </li>
    </ul>
    <div class="carousel-controls">
      <button class="carousel-button carousel-button--previous" type="button"
        aria-label="Previous Hom'As benefit"><i data-lucide="arrow-left" aria-hidden="true"></i></button>
      <p class="carousel-status" aria-live="polite">1 of 4</p>
      <button class="carousel-button carousel-button--next" type="button"
        aria-label="Next Hom'As benefit"><i data-lucide="arrow-right" aria-hidden="true"></i></button>
    </div>
  </section>

  <!-- Customer reviews have no approved public source yet. -->
  <section class="customer-reviews">
    <h2><?php echo site_text('home.reviews'); ?></h2>
    <div class="reviews-grid">
      <!-- Add your customer reviews here / php -->
    </div>
  </section>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>
