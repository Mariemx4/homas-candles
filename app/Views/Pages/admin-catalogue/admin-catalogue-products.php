<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Catalogue Products; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\AdminProductService;

// Variables in this view were prepared by the route/controller/layout. These
// local names keep repeated nested array access readable.
$activeCatalogueTab = 'products';
$filters = $productList['filters'];
?>
<?php // SECTION 1 — SWITCH BETWEEN PRODUCTS AND TAXONOMY SCREENS. ?>
<?php require __DIR__ . '/../../Components/Admin/catalogue-tabs.php'; ?>

<!-- SECTION 2 — TITLE AND CURRENT RESULT COUNT -->
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Products</h1>
    <p class="admin-page-header__summary">Search, filter, and manage every product's configuration and activation state.</p>
  </div>
  <div class="admin-detail-actions">
    <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>">Add product</a>
    <div class="admin-result-summary" aria-live="polite">
      <strong><?php echo $escape($productList['total']); ?></strong>
      <span><?php echo (int) $productList['total'] === 1 ? 'matching product' : 'matching products'; ?></span>
    </div>
  </div>
</header>

<?php if (is_string($productList['loadError'])): ?>
  <?php // A load failure has priority over the adjusted-filter warning. ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load products'; $alertMessage = $productList['loadError']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($productList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Some filters were reset'; $alertMessage = 'One or more unsupported filter or page values were ignored safely.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<!-- SECTION PURPOSE: Group the Search and filters content, states, and controls as one accessible page region. -->
<section class="admin-filter-panel" aria-labelledby="product-filters-title">
  <!-- SECTION 3 — GET filters change the URL but do not modify database data. -->
  <div class="admin-filter-panel__heading">
    <h2 id="product-filters-title">Search and filters</h2>
    <a href="<?php echo $escape($selfUrl); ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo $escape($selfUrl); ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="product-search">Name or slug</label>
      <input id="product-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search products">
    </div>
    <div class="admin-field">
      <label for="product-category">Category</label>
      <select id="product-category" name="category">
        <option value="">All categories</option>
        <?php foreach ($productList['categories'] as $category): ?>
          <option value="<?php echo $escape($category['id']); ?>"<?php echo $filters['category'] === (string) $category['id'] ? ' selected' : ''; ?>><?php echo $escape($category['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="product-type">Type</label>
      <select id="product-type" name="type">
        <option value="">All types</option>
        <?php foreach ($productList['productTypes'] as $type): ?>
          <option value="<?php echo $escape($type['id']); ?>"<?php echo $filters['type'] === (string) $type['id'] ? ' selected' : ''; ?>><?php echo $escape($type['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="product-active">Status</label>
      <select id="product-active" name="active">
        <option value="">All</option>
        <option value="1"<?php echo $filters['active'] === '1' ? ' selected' : ''; ?>>Active</option>
        <option value="0"<?php echo $filters['active'] === '0' ? ' selected' : ''; ?>>Inactive</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="product-mode">Purchase mode</label>
      <select id="product-mode" name="purchase_mode">
        <option value="">All modes</option>
        <?php foreach (AdminProductService::PURCHASE_MODES as $mode): ?>
          <?php // Convert `REQUEST_ONLY` into the readable label `Request Only`. ?>
          <option value="<?php echo $escape($mode); ?>"<?php echo $filters['purchase_mode'] === $mode ? ' selected' : ''; ?>><?php echo $escape(ucwords(strtolower(str_replace('_', ' ', $mode)))); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<!-- SECTION PURPOSE: Group the Product records content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="product-results-title">
  <!-- SECTION 4 — FILTERED PRODUCT RECORDS -->
  <header class="admin-panel__header">
    <div><h2 id="product-results-title">Product records</h2></div>
    <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>">New product</a>
  </header>

  <?php if ($productList['products'] === []): ?>
    <?php
      // Empty data needs different wording when caused by an error versus filters.
      $emptyTitle = is_string($productList['loadError']) ? 'Products unavailable' : 'No matching products';
      $emptyMessage = is_string($productList['loadError']) ? 'No product data could be displayed safely.' : 'Try clearing or changing the current search and filters, or create a new product.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable products table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered products</caption>
        <thead><tr><th scope="col">Product</th><th scope="col">Category</th><th scope="col">Price</th><th scope="col">Purchase mode</th><th scope="col">Status</th></tr></thead>
        <tbody>
          <?php foreach ($productList['products'] as $product): ?>
            <?php // Build an encoded `id=...` query string for the edit link. ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
            <?php $editUrl = $selfUrl . '?' . http_build_query(['id' => $product['id']]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($editUrl); ?>"><?php echo $escape($product['name']); ?></a><small><?php echo $escape($product['slug'] ?: 'No slug yet'); ?></small></td>
              <td><?php echo $escape($product['category_name']); ?></td>
              <td><?php echo $product['selling_price'] !== null ? $escape(number_format((float) $product['selling_price'], 3) . ' TND') : 'Not set'; ?></td>
              <td><?php echo $escape(ucwords(strtolower(str_replace('_', ' ', (string) $product['purchase_mode'])))); ?></td>
              <td><span class="admin-badge admin-badge--<?php echo ((int) $product['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $product['is_active'] === 1) ? 'Active' : 'Draft'; ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <?php // Calculate the visible row range from page number and page size. ?>
      <span>Showing <?php echo $escape((($productList['page'] - 1) * $productList['perPage']) + 1); ?>–<?php echo $escape(min($productList['page'] * $productList['perPage'], $productList['total'])); ?> of <?php echo $escape($productList['total']); ?></span>
      <?php
        // Rename values to the variable contract expected by pagination.php.
        $currentPage = $productList['page'];
        $totalPages = $productList['totalPages'];
        $paginationBaseUrl = $selfUrl;
        $paginationQuery = $productList['queryState'];
        require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
