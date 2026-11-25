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
// WHAT: every individual candle, its current selling price and the calculated suggestion. The owner
//       ticks candles and approves their suggestions in one step. Manual prices are never touched here.
$candleSourceLabels = [
    'manual' => 'Set by you',
    'calculated' => 'Approved calculation',
    'automatic_incorrect' => 'Old rule (not valid)',
];
$candleRows = $productList['candleComparison'] ?? [];
$candleMoneyText = static fn (?int $millis): string => $millis === null ? '—' : \App\Services\PricingService::formatMoney($millis) . ' TND';
?>
<?php if ($candleRows !== []): ?>
<section class="admin-panel admin-panel--table" aria-labelledby="candle-comparison-title">
  <header class="admin-panel__header">
    <div>
      <p class="admin-eyebrow">Individual candles</p>
      <h2 id="candle-comparison-title">Price comparison</h2>
      <p>Suggestions come from weight, recipe and the owner's settings. Ticking and approving changes only the candles you select.</p>
    </div>
  </header>
  <form method="post" action="<?php echo $escape($selfUrl); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
    <input type="hidden" name="action" value="apply_suggested_prices">
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col"><span class="admin-visually-hidden">Select</span></th>
            <th scope="col">Candle</th>
            <th scope="col">Weight</th>
            <th scope="col">Current price</th>
            <th scope="col">Source</th>
            <th scope="col">Suggested price</th>
            <th scope="col">Difference</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($candleRows as $row): ?>
            <?php $productId = (int) $row['product_id']; ?>
            <tr>
              <td>
                <?php if ($row['suggested_price_millis'] !== null && $row['current_price_millis'] !== $row['suggested_price_millis']): ?>
                  <input type="checkbox" name="product_ids[]" value="<?php echo $escape($productId); ?>" aria-label="Approve suggested price for candle <?php echo $escape($productId); ?>">
                <?php endif; ?>
              </td>
              <td><a href="<?php echo $escape($selfUrl . '?id=' . $productId); ?>">Candle #<?php echo $escape($productId); ?></a></td>
              <td><?php echo $row['weight_grams'] === null ? 'Not set' : $escape($row['weight_grams']) . ' g'; ?></td>
              <td><?php echo $escape($candleMoneyText($row['current_price_millis'])); ?></td>
              <td><?php echo $escape($candleSourceLabels[$row['selling_price_source']] ?? $row['selling_price_source']); ?></td>
              <td><?php echo $row['issue'] !== null ? '<span class="admin-field-error">' . $escape(['no_weight' => 'No weight', 'no_recipe' => 'No recipe', 'no_unit_cost' => 'Missing unit cost'][$row['issue']] ?? 'Incomplete') . '</span>' : $escape($candleMoneyText($row['suggested_price_millis'])); ?></td>
              <td><?php echo $row['difference_millis'] === null ? '—' : $escape(($row['difference_millis'] >= 0 ? '+' : '') . \App\Services\PricingService::formatMoney($row['difference_millis']) . ' TND'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="admin-button" type="submit">Approve ticked suggestions</button>
  </form>
</section>
<?php endif; ?>

<?php $candleSettings = $productList['candleSettings'] ?? null; ?>
<?php if (is_array($candleSettings)): ?>
<section class="admin-panel" aria-labelledby="candle-settings-title">
  <header class="admin-panel__header">
    <div>
      <p class="admin-eyebrow">Individual candles</p>
      <h2 id="candle-settings-title">Pricing settings</h2>
      <p>Changing these recalculates suggestions only. Selling prices change only when you approve them.</p>
    </div>
  </header>
  <form class="admin-status-form" method="post" action="<?php echo $escape($selfUrl); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
    <input type="hidden" name="action" value="save_pricing_settings">
    <div class="admin-field"><label for="ps-multiplier">Retail multiplier (× known cost)</label><input id="ps-multiplier" name="retail_multiplier" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['retail_multiplier'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-markup">Minimum markup (%)</label><input id="ps-markup" name="candle_minimum_markup_percent" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['candle_minimum_markup_percent'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-exponent">Quantity exponent</label><input id="ps-exponent" name="quantity_price_exponent" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['quantity_price_exponent'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-retail-round">Retail rounding up to (TND)</label><input id="ps-retail-round" name="retail_rounding_tnd" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['retail_rounding_tnd'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-qty-round">Quantity price rounding up to (TND)</label><input id="ps-qty-round" name="quantity_rounding_tnd" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['quantity_rounding_tnd'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-packaging">Packaging per candle (TND)</label><input id="ps-packaging" name="packaging_per_candle_tnd" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['packaging_per_candle_tnd'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-density">Fragrance density (g/ml, provisional 1)</label><input id="ps-density" name="density_g_per_ml" type="text" inputmode="decimal" value="<?php echo $escape(rtrim(rtrim(number_format($candleSettings['density_g_per_ml'], 4, '.', ''), '0'), '.')); ?>"></div>
    <div class="admin-field"><label for="ps-density-confirmed">Fragrance density confirmed?</label><select id="ps-density-confirmed" name="fragrance_density_confirmed"><option value="0"<?php echo ((int) $candleSettings['fragrance_density_confirmed']) === 1 ? '' : ' selected'; ?>>No (provisional)</option><option value="1"<?php echo ((int) $candleSettings['fragrance_density_confirmed']) === 1 ? ' selected' : ''; ?>>Yes</option></select></div>
    <button class="admin-button" type="submit">Save pricing settings</button>
  </form>
</section>
<?php endif; ?>

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
      <table class="admin-table admin-orders-table">
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
