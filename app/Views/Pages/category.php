<?php

declare(strict_types=1);

if (!isset(
  $categoryPage,
  $selectedProductType,
  $selectedSort,
  $products,
  $catalogueProductTypes,
  $pageTitle
)) {
  throw new RuntimeException('Category view data is required.');
}

// WHAT: the "switch to another category" links shown on every category page.
$categoryNavigation = [
  ['slug' => 'candles', 'name' => 'Candles', 'path' => 'categories/candles.php'],
  ['slug' => 'candle-sets', 'name' => 'Candle sets', 'path' => 'categories/candle-sets.php'],
  ['slug' => 'candle-bouquets', 'name' => 'Candle bouquets', 'path' => 'categories/candle-bouquets.php'],
  ['slug' => 'event-favors', 'name' => 'Event Favors', 'path' => 'categories/event-favors.php'],
  ['slug' => 'home-diffusers', 'name' => 'Home diffusers', 'path' => 'categories/home-diffusers.php'],
  ['slug' => 'accessories', 'name' => 'Accessories', 'path' => 'categories/accessories.php'],
];

require_once __DIR__ . '/../Layouts/header.php';
?>

<main class="category-page">
  <header class="category-hero">
    <nav class="category-breadcrumb" aria-label="Breadcrumb">
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">Shop</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?php echo htmlspecialchars($categoryPage['name']); ?></span>
    </nav>

    <p class="category-eyebrow"><?php echo htmlspecialchars($categoryPage['eyebrow']); ?></p>
    <div class="category-hero__heading">
      <h1><?php echo htmlspecialchars($categoryPage['title']); ?></h1>
      <p><?php echo htmlspecialchars($categoryPage['description']); ?></p>
    </div>
  </header>

  <nav class="category-switcher" aria-label="Browse another category">
    <?php foreach ($categoryNavigation as $category): ?>
      <a href="<?php echo BASE_URL . $category['path']; ?>"
        <?php echo $category['slug'] === $categoryPage['slug'] ? 'class="active" aria-current="page"' : ''; ?>>
        <?php echo htmlspecialchars($category['name']); ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="category-catalogue" aria-labelledby="category-products-title">
    <div class="category-toolbar">
      <div class="category-toolbar__heading">
        <p class="category-eyebrow">Browse the collection</p>
        <h2 id="category-products-title"><?php echo htmlspecialchars($categoryPage['name']); ?></h2>
        <p><span data-product-count><?php echo count($products); ?></span> pieces</p>
      </div>

      <!-- WHAT: the type/sort filter form — submits back to this same page as a GET request -->
      <form class="category-filters" action="<?php echo BASE_URL . $categoryPage['path']; ?>" method="GET"
        data-catalogue-filters>
        <div class="category-select-field">
          <label for="category-product-type">Product type</label>
          <select id="category-product-type" name="type">
            <option value="all" <?php echo $selectedProductType === 'all' ? 'selected' : ''; ?>>All product types</option>
            <?php foreach ($catalogueProductTypes as $typeSlug => $typeName): ?>
              <option value="<?php echo htmlspecialchars($typeSlug); ?>"
                <?php echo $selectedProductType === $typeSlug ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($typeName); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="category-select-field">
          <label for="category-sort">Sort by</label>
          <select id="category-sort" name="sort">
            <option value="featured" <?php echo $selectedSort === 'featured' ? 'selected' : ''; ?>>Featured</option>
            <option value="newest" <?php echo $selectedSort === 'newest' ? 'selected' : ''; ?>>Newest</option>
            <option value="price-low" <?php echo $selectedSort === 'price-low' ? 'selected' : ''; ?>>Price: low to high</option>
            <option value="price-high" <?php echo $selectedSort === 'price-high' ? 'selected' : ''; ?>>Price: high to low</option>
          </select>
        </div>

        <button type="submit" class="category-filter-submit">
          Apply
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </form>
    </div>

    <!-- WHAT: loading/error/success banner — see state_controller.js for how data-ui-state gets toggled -->
    <?php
    $catalogueView = [
      'class_prefix' => 'category',
      'loading_title' => 'Refreshing this collection…',
      'error_title' => 'This collection could not be loaded.',
      'empty_icon' => 'sparkles',
      'empty_eyebrow' => 'Collection in progress',
      'empty_title' => $categoryPage['empty_title'],
      'empty_description' => 'No pieces are available in this collection yet. Please check back soon.',
      'empty_link' => 'shop.php',
      'empty_link_label' => 'Return to all collections',
    ];
    require __DIR__ . '/../Components/catalogue-results.php';
    ?>
  </section>

  <?php
  $catalogueClassPrefix = 'category';
  require __DIR__ . '/../Components/catalogue-promise.php';
  ?>
</main>

<?php
require_once __DIR__ . '/../Layouts/footer.php';
?>
