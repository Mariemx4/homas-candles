<?php

declare(strict_types=1);

use App\View\AdminIcons;
use App\Services\AdminProductService;

// Variables in this view were prepared by the route/controller/layout. These
// local names keep repeated nested array access readable.
$activeCatalogueTab = 'products';
$filters = $productList['filters'];
?>
<?php // SECTION 1 — SWITCH BETWEEN PRODUCTS AND TAXONOMY SCREENS.?>
<?php require VIEW_PATH . '/Components/Admin/catalogue-tabs.php'; ?>

<!-- SECTION 2 — TITLE AND CURRENT RESULT COUNT -->
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Products</h1>
    <p class="admin-page-header__summary">Search, filter, and manage every product's configuration and activation state.</p>
  </div>
  <div class="admin-detail-actions">
    <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>"><?php echo AdminIcons::render('plus'); ?>Add product</a>
    <div class="admin-result-summary" aria-live="polite">
      <strong><?php echo $escape($productList['total']); ?></strong>
      <span><?php echo (int) $productList['total'] === 1 ? 'matching product' : 'matching products'; ?></span>
    </div>
  </div>
</header>

<?php if (is_string($productList['loadError'])): ?>
  <?php // A load failure has priority over the adjusted-filter warning.?>
  <?php $alertTone = 'error';
    $alertTitle = 'Unable to load products';
    $alertMessage = $productList['loadError'];
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php elseif ($productList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning';
        $alertTitle = 'Some filters were reset';
        $alertMessage = 'One or more unsupported filter or page values were ignored safely.';
        require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="product-filters-title">
  <!-- SECTION 3 — GET filters change the URL but do not modify database data. -->
  <div class="admin-filter-panel__heading">
    <h2 id="product-filters-title">Search and filters</h2>
    <a href="<?php echo $escape($selfUrl); ?>">Clear all</a>
  </div>
  <form class="admin-filter-form" action="<?php echo $escape($selfUrl); ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="product-search">Name or slug</label>
      <input id="product-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search products">
    </div>
    <input type="hidden" name="category" value="<?php echo $escape($filters['category'] ?? ''); ?>">
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
          <?php // Convert `REQUEST_ONLY` into the readable label `Request Only`.?>
          <option value="<?php echo $escape($mode); ?>"<?php echo $filters['purchase_mode'] === $mode ? ' selected' : ''; ?>><?php echo $escape(ucwords(strtolower(str_replace('_', ' ', $mode)))); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>


<?php
$activeCategoryId = $filters['category'] ?? null;
$categoryCounts = $productList['categoryCounts'] ?? [];
$totalProducts = array_sum($categoryCounts);
$activeCategory = null;
foreach ($productList['categories'] as $category) {
    if ((string) $category['id'] === (string) $activeCategoryId) {
        $activeCategory = $category;
    }
}
// The rule exists only for a selected category; "All" shows the product list alone.
$activeRule = $activeCategory !== null ? ($productList['categoryRules'][(string) $activeCategory['id']] ?? null) : null;
$ruleValues = $ruleForm['values'] ?? [];
// Candle pricing numbers belong to the candles rule, so they are edited in the same window.
$candleSettings = $productList['candleSettings'] ?? null;
$isCandleRule = $activeCategory !== null && $activeCategory['slug'] === 'candles' && is_array($candleSettings);
$decimal = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
?>
<nav class="admin-category-tabs" aria-label="Product categories">
  <a class="admin-category-tab<?php echo $activeCategory === null ? ' is-active' : ''; ?>" href="<?php echo $escape($selfUrl); ?>"<?php echo $activeCategory === null ? ' aria-current="page"' : ''; ?>>All (<?php echo $escape($totalProducts); ?>)</a>
  <?php foreach ($productList['categories'] as $category): ?>
    <?php $isActiveTab = $activeCategory !== null && (string) $category['id'] === (string) $activeCategory['id']; ?>
    <a class="admin-category-tab<?php echo $isActiveTab ? ' is-active' : ''; ?>" href="<?php echo $escape($selfUrl . '?category=' . rawurlencode((string) $category['id'])); ?>"<?php echo $isActiveTab ? ' aria-current="page"' : ''; ?>><?php echo $escape($category['name']); ?> (<?php echo $escape($categoryCounts[(string) $category['id']] ?? 0); ?>)</a>
  <?php endforeach; ?>
</nav>

<div class="admin-category-layout<?php echo $activeRule === null ? ' is-single' : ''; ?>">
<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="product-results-title">
  <!-- SECTION 4 — FILTERED PRODUCT RECORDS -->
  <header class="admin-panel__header">
    <div><h2 id="product-results-title">Product records</h2></div>
    <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>"><?php echo AdminIcons::render('plus'); ?>New product</a>
  </header>

  <?php if ($productList['products'] === []): ?>
    <?php
            // Empty data needs different wording when caused by an error versus filters.
            $emptyTitle = is_string($productList['loadError']) ? 'Products unavailable' : 'No matching products';
      $emptyMessage = is_string($productList['loadError']) ? 'No product data could be displayed safely.' : 'Try clearing or changing the current search and filters, or create a new product.';
      require VIEW_PATH . '/Components/Admin/empty-state.php';
      ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable products table">
      <table class="admin-table admin-orders-table<?php echo $activeCategory !== null ? ' admin-table--in-category' : ''; ?>">
        <caption class="admin-visually-hidden">Filtered products</caption>
        <thead><tr><th scope="col">Product</th><th scope="col">Category</th><th scope="col">Price</th><th scope="col">Purchase mode</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($productList['products'] as $product): ?>
            <?php // Build an encoded `id=...` query string for the edit link.?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
            <?php $editUrl = $selfUrl . '?' . http_build_query(['id' => $product['id']]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($editUrl); ?>"><?php echo $escape($product['name']); ?></a><small><?php echo $escape($product['slug'] ?: 'No slug yet'); ?></small></td>
              <td><?php echo $escape($product['category_name']); ?></td>
              <td><?php echo $product['selling_price'] !== null ? $escape(number_format((float) $product['selling_price'], 3) . ' TND') : 'Not set'; ?></td>
              <td><?php echo $escape(ucwords(strtolower(str_replace('_', ' ', (string) $product['purchase_mode'])))); ?></td>
              <td><span class="admin-badge admin-badge--<?php echo ((int) $product['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $product['is_active'] === 1) ? 'Active' : 'Draft'; ?></span></td>
            <td><?php echo AdminIcons::recordActions($editUrl, 'products', (int) $product['id'], (string) $product['name']); ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <?php // Calculate the visible row range from page number and page size.?>
      <span>Showing <?php echo $escape((($productList['page'] - 1) * $productList['perPage']) + 1); ?>–<?php echo $escape(min($productList['page'] * $productList['perPage'], $productList['total'])); ?> of <?php echo $escape($productList['total']); ?></span>
      <?php
          // Rename values to the variable contract expected by pagination.php.
          $currentPage = $productList['page'];
      $totalPages = $productList['totalPages'];
      $paginationBaseUrl = $selfUrl;
      $paginationQuery = $productList['queryState'];
      require VIEW_PATH . '/Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
  <?php if ($activeRule !== null): ?>
  <!-- SECTION 5 — PRICING RULE OF THE SELECTED CATEGORY (list on the left, rule on the right) -->
  <aside class="admin-panel admin-category-rule" aria-label="Pricing rule">
    <header class="admin-panel__header admin-category-rule__header">
      <div>
        <p class="admin-eyebrow">Pricing rule</p>
        <h2 id="category-rule-title"><?php echo $escape($activeRule['title']); ?></h2>
      </div>
      <button type="button" class="admin-button admin-button--secondary" data-dialog-open="category-rule-dialog">Edit rule</button>
    </header>
    <div class="admin-category-rule__body">
      <?php if (($ruleNotice['success'] ?? null) !== null): ?>
        <?php $alertTone = 'success'; $alertTitle = 'Saved'; $alertMessage = $ruleNotice['success']; require VIEW_PATH . '/Components/Admin/alert.php'; ?>
      <?php endif; ?>
      <p><?php echo $escape($activeRule['body']); ?></p>
    </div>
  </aside>
  <?php endif; ?>
</div>

<?php if ($activeRule !== null): ?>
<dialog id="category-rule-dialog" class="admin-rule-dialog" aria-labelledby="category-rule-dialog-title"<?php echo $ruleForm['errors'] !== [] ? ' data-open-on-load' : ''; ?>>
  <div class="admin-rule-dialog__content">
    <header class="admin-rule-dialog__header">
      <p class="admin-eyebrow"><?php echo $escape($activeCategory['name']); ?></p>
      <h2 id="category-rule-dialog-title">Edit pricing rule</h2>
    </header>
    <?php if (isset($ruleForm['errors']['form'])): ?>
      <p class="admin-field-error" role="alert"><?php echo $escape($ruleForm['errors']['form']); ?></p>
    <?php endif; ?>

    <form class="admin-rule-dialog__form" method="post" action="<?php echo $escape($selfUrl); ?>">
      <h3>Rule text</h3>
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="action" value="save_category_rule">
      <input type="hidden" name="category_id" value="<?php echo $escape($activeCategory['id']); ?>">
      <div class="admin-field">
        <label for="rule-title">Title</label>
        <input id="rule-title" name="title" type="text" maxlength="120" required value="<?php echo $escape($ruleValues['title'] ?? $activeRule['title']); ?>">
        <?php if (isset($ruleForm['errors']['title'])): ?><small class="admin-field-error"><?php echo $escape($ruleForm['errors']['title']); ?></small><?php endif; ?>
      </div>
      <div class="admin-field">
        <label for="rule-body">Description</label>
        <textarea id="rule-body" name="body" rows="5" maxlength="1000" required><?php echo $escape($ruleValues['body'] ?? $activeRule['body']); ?></textarea>
        <?php if (isset($ruleForm['errors']['body'])): ?><small class="admin-field-error"><?php echo $escape($ruleForm['errors']['body']); ?></small><?php endif; ?>
      </div>
      <div class="admin-rule-dialog__actions">
        <button class="admin-button" type="submit">Save rule text</button>
      </div>
    </form>

    <?php if ($isCandleRule): ?>
    <form class="admin-rule-dialog__form" method="post" action="<?php echo $escape($selfUrl); ?>" aria-labelledby="candle-numbers-title">
      <h3 id="candle-numbers-title">Candle numbers</h3>
      <p class="admin-rule-dialog__note">Changing these recalculates suggestions only. Selling prices change only when you approve them.</p>
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="action" value="save_pricing_settings">
      <input type="hidden" name="category_id" value="<?php echo $escape($activeCategory['id']); ?>">
      <div class="admin-rule-dialog__grid">
        <div class="admin-field"><label for="ps-multiplier">Retail multiplier (× known cost)</label><input id="ps-multiplier" name="retail_multiplier" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['retail_multiplier'])); ?>"></div>
        <div class="admin-field"><label for="ps-markup">Minimum markup (%)</label><input id="ps-markup" name="candle_minimum_markup_percent" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['candle_minimum_markup_percent'])); ?>"></div>
        <div class="admin-field"><label for="ps-exponent">Quantity exponent</label><input id="ps-exponent" name="quantity_price_exponent" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['quantity_price_exponent'])); ?>"></div>
        <div class="admin-field"><label for="ps-retail-round">Retail rounding up to (TND)</label><input id="ps-retail-round" name="retail_rounding_tnd" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['retail_rounding_tnd'])); ?>"></div>
        <div class="admin-field"><label for="ps-qty-round">Quantity price rounding up to (TND)</label><input id="ps-qty-round" name="quantity_rounding_tnd" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['quantity_rounding_tnd'])); ?>"></div>
        <div class="admin-field"><label for="ps-packaging">Packaging per candle (TND)</label><input id="ps-packaging" name="packaging_per_candle_tnd" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['packaging_per_candle_tnd'])); ?>"></div>
        <div class="admin-field"><label for="ps-density">Fragrance density (g/ml, provisional 1)</label><input id="ps-density" name="density_g_per_ml" type="text" inputmode="decimal" value="<?php echo $escape($decimal($candleSettings['density_g_per_ml'])); ?>"></div>
        <div class="admin-field"><label for="ps-density-confirmed">Fragrance density confirmed?</label><select id="ps-density-confirmed" name="fragrance_density_confirmed"><option value="0"<?php echo ((int) $candleSettings['fragrance_density_confirmed']) === 1 ? '' : ' selected'; ?>>No (provisional)</option><option value="1"<?php echo ((int) $candleSettings['fragrance_density_confirmed']) === 1 ? ' selected' : ''; ?>>Yes</option></select></div>
      </div>
      <div class="admin-rule-dialog__actions">
        <button class="admin-button" type="submit">Save candle numbers</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="admin-rule-dialog__footer">
      <button type="button" class="admin-button admin-button--ghost" data-dialog-close="category-rule-dialog">Close</button>
    </div>
  </div>
</dialog>
<script src="<?php echo BASE_URL . 'assets/js/admin/category-rule.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/category-rule.js'); ?>" defer></script>
<?php endif; ?>


