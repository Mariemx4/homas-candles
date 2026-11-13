<?php

declare(strict_types=1);



if (!isset(
    $selectedCategory,
    $catalogueCategories,
    $selectedProductType,
    $selectedSort,
    $products,
    $totalProducts,
    $currentPage,
    $totalPages,
    $selectedSearch,
    $catalogueProductTypes,
    $pageTitle
)) {
    throw new RuntimeException('Shop view data is required.');
}

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="shop-page">
  <header class="shop-hero">
    <p class="shop-eyebrow"><?php echo site_text('shop.eyebrow'); ?></p>
    <div class="shop-hero__heading">
      <h1><?php echo site_text('shop.headline_1'); ?><br><em><?php echo site_text('shop.headline_2'); ?></em></h1>
      <p><?php echo site_text('shop.description'); ?></p>
    </div>
  </header>

  <nav class="shop-categories" aria-label="<?php echo site_text('nav.categories'); ?>">
    <?php foreach ($catalogueCategories as $category): ?>
      <a href="<?php echo htmlspecialchars(BASE_URL . $category['path'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="shop-catalogue" aria-labelledby="catalogue-title">
    <div class="shop-toolbar">
      <div class="shop-toolbar__title">
        <p class="shop-eyebrow"><?php echo site_text('shop.browse_all'); ?></p>
        <h2 id="catalogue-title"><?php echo site_text('shop.creations'); ?></h2>
        <p><span data-product-count><?php echo $totalProducts; ?></span> <?php echo site_text('shop.pieces'); ?></p>
      </div>

      <!-- Category, type, and sort selections are applied by CatalogueController. -->
      <form class="shop-filters" action="<?php echo BASE_URL . 'shop.php'; ?>" method="GET"
        data-catalogue-filters>
        <?php if ($selectedSearch !== ''): ?>
          <input type="hidden" name="query" value="<?php echo htmlspecialchars($selectedSearch); ?>">
        <?php endif; ?>
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars(\App\Services\Locale::current(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="shop-select-field">
          <label for="category-filter"><?php echo site_text('shop.category'); ?></label>
          <select id="category-filter" name="category">
            <option value="all" <?php echo $selectedCategory === 'all' ? 'selected' : ''; ?>><?php echo site_text('shop.all_categories'); ?></option>
            <?php foreach ($catalogueCategories as $category): ?>
              <option value="<?php echo htmlspecialchars($category['slug'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedCategory === $category['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="shop-select-field">
          <label for="product-type-filter"><?php echo site_text('shop.product_type'); ?></label>
          <select id="product-type-filter" name="type">
            <option value="all" <?php echo $selectedProductType === 'all' ? 'selected' : ''; ?>><?php echo site_text('shop.all_types'); ?></option>
            <?php foreach ($catalogueProductTypes as $typeSlug => $typeName): ?>
              <option value="<?php echo htmlspecialchars($typeSlug); ?>"
                <?php echo $selectedProductType === $typeSlug ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($typeName); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="shop-select-field">
          <label for="sort-products"><?php echo site_text('shop.sort'); ?></label>
          <select id="sort-products" name="sort">
            <option value="featured" <?php echo $selectedSort === 'featured' ? 'selected' : ''; ?>><?php echo site_text('shop.featured'); ?></option>
            <option value="newest" <?php echo $selectedSort === 'newest' ? 'selected' : ''; ?>><?php echo site_text('shop.newest'); ?></option>
            <option value="price-low" <?php echo $selectedSort === 'price-low' ? 'selected' : ''; ?>><?php echo site_text('shop.price_low'); ?></option>
            <option value="price-high" <?php echo $selectedSort === 'price-high' ? 'selected' : ''; ?>><?php echo site_text('shop.price_high'); ?></option>
          </select>
        </div>

        <button type="submit" class="shop-filter-submit">
          <?php echo site_text('shop.apply'); ?>
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </form>
    </div>

    <?php
    $catalogueView = [
      'class_prefix' => 'shop',
      'loading_title' => \App\Services\Locale::text('shop.loading'),
      'error_title' => \App\Services\Locale::text('shop.error'),
      'empty_icon' => 'store',
      'empty_eyebrow' => \App\Services\Locale::text('shop.empty_eyebrow'),
      'empty_title' => \App\Services\Locale::text('shop.empty'),
      'empty_description' => \App\Services\Locale::text('shop.empty_body'),
      'empty_link' => 'contact.php',
      'empty_link_label' => \App\Services\Locale::text('shop.empty_link'),
    ];
$cataloguePagePath = 'shop.php';
require VIEW_PATH . '/Components/catalogue-results.php';
?>
  </section>

  <?php
  $catalogueClassPrefix = 'shop';
require VIEW_PATH . '/Components/catalogue-promise.php';
?>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>