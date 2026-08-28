<?php
// WHAT: the homepage — hero, category carousel, brand story, and the two
//       "add your content here" placeholder sections below.
$pageTitle = "Home | Hom'As Candles";
require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="home-page">
  <section class="hero">
    <p class="home-eyebrow">Hand-poured in Tunisia</p>
    <h1>Hom'As Candles</h1>
    <p>Light the moment, smell the memory.</p>
    <a class="hero-order-link" href="<?php echo BASE_URL . 'shop.php'; ?>">Shop now</a>

    <a class="hero-featured-link" href="<?php echo BASE_URL . 'shop.php'; ?>">
      <img src="<?php echo BASE_URL . 'assets/brand/bestSeller.jpg'; ?>"
        alt="Featured Hom'As candle" class="hero-image">
      <span>Our best seller</span>
    </a>
  </section>

  <!-- WHAT: the "Explore our creations" carousel — driven by carousel.js -->
  <section class="categories-cards carousel">
    <p class="home-eyebrow">Made for your moments</p>
    <h2>Explore our creations</h2>

    <div class="categories-grid carousel-track">
      <article class="category-card carousel-slide is-active">
        <img src="<?php echo BASE_URL . 'assets/brand/candle.jpg'; ?>"
          alt="Hom'As candle collection">
        <h3>Candles</h3>
        <p>Explore our candle collection.</p>
        <a href="<?php echo BASE_URL . 'categories/candles.php'; ?>">View candles</a>
      </article>

      <article class="category-card carousel-slide">
        <img src="<?php echo BASE_URL . 'assets/brand/candleSet.jpg'; ?>"
          alt="Hom'As candle set collection">
        <h3>Candle sets</h3>
        <p>Explore our candle sets.</p>
        <a href="<?php echo BASE_URL . 'categories/candle-sets.php'; ?>">View candle sets</a>
      </article>

      <article class="category-card carousel-slide">
        <img src="<?php echo BASE_URL . 'assets/brand/bouquet.jpg'; ?>"
          alt="Hom'As candle bouquet collection">
        <h3>Candle bouquets</h3>
        <p>Explore our candle bouquets.</p>
        <a href="<?php echo BASE_URL . 'categories/candle-bouquets.php'; ?>">View candle bouquets</a>
      </article>

      <article class="category-card carousel-slide">
        <img src="<?php echo BASE_URL . 'assets/brand/eventFavors.jpg'; ?>"
          alt="Hom'As event favors collection">
        <h3>Event Favors</h3>
        <p>Explore our event favors collection.</p>
        <a href="<?php echo BASE_URL . 'categories/event-favors.php'; ?>">View event favors</a>
      </article>

      <article class="category-card carousel-slide">
        <div class="category-card__media-placeholder" role="img"
          aria-label="Home diffusers collection image coming soon">Image coming soon</div>
        <h3>Home diffusers</h3>
        <p>Explore our home diffusers.</p>
        <a href="<?php echo BASE_URL . 'categories/home-diffusers.php'; ?>">View home diffusers</a>
      </article>

      <article class="category-card carousel-slide">
        <div class="category-card__media-placeholder" role="img"
          aria-label="Accessories collection image coming soon">Image coming soon</div>
        <h3>Accessories</h3>
        <p>Explore our accessories.</p>
        <a href="<?php echo BASE_URL . 'categories/accessories.php'; ?>">View accessories</a>
      </article>

      <div class="carousel-controls">
        <button class="carousel-button carousel-button--previous" type="button"
          aria-label="Previous featured creation">
          <i data-lucide="arrow-left" aria-hidden="true"></i>
        </button>
        <p class="carousel-status" aria-live="polite">1 of 6</p>
        <button class="carousel-button carousel-button--next" type="button"
          aria-label="Next featured creation">
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  </section>

  <!-- WHAT: empty on purpose — fill with product-card.php renders once shop.php is DB-backed -->
  <section class="featured-products">
    <h2>Featured products</h2>
    <div class="products-grid">
      <!-- Add your featured products here / php -->
    </div>
  </section>

  <section class="call-to-action">
    <p class="home-eyebrow">Bring warmth home</p>
    <h2>Ready to light up your home?</h2>
    <a href="<?php echo BASE_URL . 'shop.php'; ?>">Shop now</a>
  </section>

  <section class="homas-story carousel">
    <p class="home-eyebrow">Behind the flame</p>
    <h2>Our story</h2>
    <ul class="carousel-track">
      <li class="carousel-slide is-active">
        <h3>How Hom'As began</h3>
        <p>
          Creativity has always been part of who I am. Since childhood,
          I’ve loved turning simple materials into something beautiful
          from crochet to handmade accessories.
          When I discovered the world of scented candles, it felt different.
          It brought together everything I loved: creating,
          experimenting and turning raw materials into little pieces of art.
        </p>
      </li>
      <li class="carousel-slide">
        <h3>Why I kept going</h3>
        <p>
          Hom'As started as a business from the very beginning,
          but what made me believe in it was seeing the difference in what
          I was creating. Beautiful designs, strong scents and attention
          to the smallest details made each candle feel special
          especially compared with many candles on the market that
          looked affordable, but offered little to no fragrance.
        </p>
      </li>
      <li class="carousel-slide">
        <h3>Inspired by flowers</h3>
        <p>
          Flowers are at the heart of many Hom'As creations because honestly,
          who doesn’t love flowers? Their shapes, softness and endless variety
          inspire us to turn candles into decorative pieces that can bring
          the beauty of flowers into your space in a different way.
        </p>
      </li>
      <li class="carousel-slide">
        <h3>Made personal</h3>
        <p>
          Your candle doesn’t always have to look like everyone else’s.
          Depending on the design and available options, you can personalise
          the colour, fragrance, shape, bouquet composition, packaging and even
          add your own message making each creation feel a little more yours.
        </p>
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
    <p class="home-eyebrow">The Hom'As difference</p>
    <h2>Why choose Hom'As Candles?</h2>
    <ul class="carousel-track">
      <li class="carousel-slide is-active">
        <h3>Handcrafted with care</h3>
        <div class="why-homas__media-placeholder" aria-hidden="true">Image coming soon</div>
      </li>
      <li class="carousel-slide">
        <h3>High-quality ingredients</h3>
        <div class="why-homas__media-placeholder" aria-hidden="true">Image coming soon</div>
      </li>
      <li class="carousel-slide">
        <h3>Unique and inspiring designs</h3>
        <div class="why-homas__media-placeholder" aria-hidden="true">Image coming soon</div>
      </li>
      <li class="carousel-slide">
        <h3>Long-lasting and delightful experience</h3>
        <div class="why-homas__media-placeholder" aria-hidden="true">Image coming soon</div>
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

  <!-- WHAT: empty on purpose — fill once there's a reviews table/source to pull from -->
  <section class="customer-reviews">
    <h2>Customer reviews</h2>
    <div class="reviews-grid">
      <!-- Add your customer reviews here / php -->
    </div>
  </section>
</main>

<?php
require_once __DIR__ . '/../app/Views/Layouts/footer.php';
?>