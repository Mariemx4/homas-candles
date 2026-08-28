<?php

declare(strict_types=1);

if (!isset(
    $selectedCategory,
    $selectedProductType,
    $selectedSort,
    $products,
    $catalogueProductTypes,
    $pageTitle
)) {
    throw new RuntimeException('Shop view data is required.');
}

require_once __DIR__ . '/../Layouts/header.php';
?>

<main class="shop-page">
  <header class="shop-hero">
    <p class="shop-eyebrow">The Hom'As collection</p>
    <div class="shop-hero__heading">
      <h1>Made slowly.<br><em>Chosen lovingly.</em></h1>
      <p>Discover handmade candles, thoughtful sets, and meaningful pieces created to bring warmth into every kind of moment.</p>
    </div>
  </header>

  <nav class="shop-categories" aria-label="Shop categories">
    <a href="<?php echo BASE_URL . 'categories/candles.php'; ?>">Candles</a>
    <a href="<?php echo BASE_URL . 'categories/candle-sets.php'; ?>">Candle sets</a>
    <a href="<?php echo BASE_URL . 'categories/candle-bouquets.php'; ?>">Candle bouquets</a>
    <a href="<?php echo BASE_URL . 'categories/event-favors.php'; ?>">Event Favors</a>
    <a href="<?php echo BASE_URL . 'categories/home-diffusers.php'; ?>">Home diffusers</a>
    <a href="<?php echo BASE_URL . 'categories/accessories.php'; ?>">Accessories</a>
  </nav>

  <section class="shop-catalogue" aria-labelledby="catalogue-title">
    <div class="shop-toolbar">
      <div class="shop-toolbar__title">
        <p class="shop-eyebrow">Browse all</p>
        <h2 id="catalogue-title">Our creations</h2>
        <p><span data-product-count><?php echo count($products); ?></span> pieces</p>
      </div>

      <!-- WHAT: category/type/sort filter form — read into $_GET above, not yet applied to a query -->
      <form class="shop-filters" action="<?php echo BASE_URL . 'shop.php'; ?>" method="GET"
        data-catalogue-filters>
        <div class="shop-select-field">
          <label for="category-filter">Category</label>
          <select id="category-filter" name="category">
            <option value="all" <?php echo $selectedCategory === 'all' ? 'selected' : ''; ?>>All categories</option>
            <option value="candles" <?php echo $selectedCategory === 'candles' ? 'selected' : ''; ?>>Candles</option>
            <option value="candle-sets" <?php echo $selectedCategory === 'candle-sets' ? 'selected' : ''; ?>>Candle sets</option>
            <option value="bouquets" <?php echo $selectedCategory === 'bouquets' ? 'selected' : ''; ?>>Candle bouquets</option>
            <option value="event-favors" <?php echo $selectedCategory === 'event-favors' ? 'selected' : ''; ?>>Event Favors</option>
            <option value="home-diffusers" <?php echo $selectedCategory === 'home-diffusers' ? 'selected' : ''; ?>>Home diffusers</option>
            <option value="accessories" <?php echo $selectedCategory === 'accessories' ? 'selected' : ''; ?>>Accessories</option>
          </select>
        </div>

        <div class="shop-select-field">
          <label for="product-type-filter">Product type</label>
          <select id="  " name="type">
            <option value="all" <?php echo $selectedProductType === 'all' ? 'selected' : ''; ?>>All product types</option>
            <?php foreach ($catalogueProductTypes as $typeSlug => $typeName): ?>
              <option value="<?php echo htmlspecialchars($typeSlug); ?>"
                <?php echo $selectedProductType === $typeSlug ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($typeName); ?>
              </option> 
            <?php endforeach; ?>
          </select>
        </div>

        <div class="shop-select-field">
          <label for="sort-products">Sort by</label>
          <select id="sort-products" name="sort">
            <option value="featured" <?php echo $selectedSort === 'featured' ? 'selected' : ''; ?>>Featured</option>
            <option value="newest" <?php echo $selectedSort === 'newest' ? 'selected' : ''; ?>>Newest</option>
            <option value="price-low" <?php echo $selectedSort === 'price-low' ? 'selected' : ''; ?>>Price: low to high</option>
            <option value="price-high" <?php echo $selectedSort === 'price-high' ? 'selected' : ''; ?>>Price: high to low</option>
          </select>
        </div>

        <button type="submit" class="shop-filter-submit">
          Apply
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </form>
    </div>

    <?php
    $catalogueView = [
      'class_prefix' => 'shop',
      'loading_title' => 'Refreshing the collection…',
      'error_title' => 'The collection could not be loaded.',
      'empty_icon' => 'store',
      'empty_eyebrow' => 'Coming to the shelves',
      'empty_title' => 'The collection is being prepared.',
      'empty_description' => 'New pieces will appear here soon, ready for you to explore.',
      'empty_link' => 'contact.php',
      'empty_link_label' => 'Ask about a handmade order',
    ];
    require __DIR__ . '/../Components/catalogue-results.php';
    ?>
  </section>

  <?php
  $catalogueClassPrefix = 'shop';
  require __DIR__ . '/../Components/catalogue-promise.php';
  ?>
</main>

<?php
require_once __DIR__ . '/../Layouts/footer.php';
?>


