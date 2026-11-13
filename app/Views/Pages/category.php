<?php

declare(strict_types=1);



if (!isset(
    $categoryPage,
    $selectedProductType,
    $selectedSort,
    $products,
    $totalProducts,
    $currentPage,
    $totalPages,
    $selectedSearch,
    $catalogueCategories,
    $catalogueProductTypes,
    $pageTitle
)) {
    throw new RuntimeException('Category view data is required.');
}

$categoryNavigation = $catalogueCategories;

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="category-page">
  <header class="category-hero">
    <nav class="category-breadcrumb" aria-label="<?php echo site_text('category.breadcrumb'); ?>">
      <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('nav.shop'); ?></a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?php echo htmlspecialchars($categoryPage['name']); ?></span>
    </nav>

    <p class="category-eyebrow"><?php echo htmlspecialchars($categoryPage['eyebrow']); ?></p>
    <div class="category-hero__heading">
      <h1><?php echo htmlspecialchars($categoryPage['title']); ?></h1>
      <p><?php echo htmlspecialchars($categoryPage['description']); ?></p>
    </div>
  </header>

  <nav class="category-switcher" aria-label="<?php echo site_text('category.browse_another'); ?>">
    <?php foreach ($categoryNavigation as $category): ?>
      <a href="<?php echo htmlspecialchars(BASE_URL . $category['path'], ENT_QUOTES, 'UTF-8'); ?>"
        <?php echo $category['slug'] === $categoryPage['slug'] ? 'class="active" aria-current="page"' : ''; ?>>
        <?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="category-catalogue" aria-labelledby="category-products-title">
    <div class="category-toolbar">
      <div class="category-toolbar__heading">
        <p class="category-eyebrow"><?php echo site_text('category.browse'); ?></p>
        <h2 id="category-products-title"><?php echo htmlspecialchars($categoryPage['name']); ?></h2>
        <p><span data-product-count><?php echo $totalProducts; ?></span> <?php echo site_text('shop.pieces'); ?></p>
      </div>

      <!-- WHAT: the type/sort filter form — submits back to this same page as a GET request -->
      <form class="category-filters" action="<?php echo htmlspecialchars(BASE_URL . strtok($categoryPage['path'], '?'), ENT_QUOTES, 'UTF-8'); ?>" method="GET"
        data-catalogue-filters>
        <?php if (str_contains($categoryPage['path'], '?')): ?>
          <input type="hidden" name="slug" value="<?php echo htmlspecialchars($categoryPage['database_slug'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <?php if ($selectedSearch !== ''): ?>
          <input type="hidden" name="query" value="<?php echo htmlspecialchars($selectedSearch); ?>">
        <?php endif; ?>
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars(\App\Services\Locale::current(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="category-select-field">
          <label for="category-product-type"><?php echo site_text('shop.product_type'); ?></label>
          <select id="category-product-type" name="type">
            <option value="all" <?php echo $selectedProductType === 'all' ? 'selected' : ''; ?>><?php echo site_text('shop.all_types'); ?></option>
            <?php foreach ($catalogueProductTypes as $typeSlug => $typeName): ?>
              <option value="<?php echo htmlspecialchars($typeSlug); ?>"
                <?php echo $selectedProductType === $typeSlug ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($typeName); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="category-select-field">
          <label for="category-sort"><?php echo site_text('shop.sort'); ?></label>
          <select id="category-sort" name="sort">
            <option value="featured" <?php echo $selectedSort === 'featured' ? 'selected' : ''; ?>><?php echo site_text('shop.featured'); ?></option>
            <option value="newest" <?php echo $selectedSort === 'newest' ? 'selected' : ''; ?>><?php echo site_text('shop.newest'); ?></option>
            <option value="price-low" <?php echo $selectedSort === 'price-low' ? 'selected' : ''; ?>><?php echo site_text('shop.price_low'); ?></option>
            <option value="price-high" <?php echo $selectedSort === 'price-high' ? 'selected' : ''; ?>><?php echo site_text('shop.price_high'); ?></option>
          </select>
        </div>

        <button type="submit" class="category-filter-submit">
          <?php echo site_text('shop.apply'); ?>
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </button>
      </form>
    </div>

    <!-- WHAT: loading/error/success banner — see state_controller.js for how data-ui-state gets toggled -->
    <?php
    $catalogueView = [
      'class_prefix' => 'category',
      'loading_title' => \App\Services\Locale::text('category.loading'),
      'error_title' => \App\Services\Locale::text('category.error'),
      'empty_icon' => 'sparkles',
      'empty_eyebrow' => \App\Services\Locale::text('category.empty_eyebrow'),
      'empty_title' => $categoryPage['empty_title'],
      'empty_description' => \App\Services\Locale::text('category.empty_body'),
      'empty_link' => 'shop.php',
      'empty_link_label' => \App\Services\Locale::text('category.empty_link'),
    ];
$cataloguePagePath = $categoryPage['path'];
require VIEW_PATH . '/Components/catalogue-results.php';
?>
  </section>

  <?php
  $catalogueClassPrefix = 'category';
require VIEW_PATH . '/Components/catalogue-promise.php';
?>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>