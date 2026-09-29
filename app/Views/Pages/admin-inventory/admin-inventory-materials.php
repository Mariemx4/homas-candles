<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Inventory Materials; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$filters = $materialList['filters'];
$formatQuantity = static fn (mixed $quantity, mixed $unit): string => $quantity === null
    ? 'Not tracked'
    : number_format((float) $quantity, 3) . ' ' . (string) $unit;
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Materials</h1>
    <p class="admin-page-header__summary">Track material identity, stock, and cost. Sort by lowest stock to spot materials that may need attention.</p>
  </div>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($materialList['total']); ?></strong>
    <span><?php echo (int) $materialList['total'] === 1 ? 'matching material' : 'matching materials'; ?></span>
  </div>
</header>

<nav class="admin-catalogue-tabs" aria-label="Inventory sections">
  <a class="admin-catalogue-tabs__item admin-catalogue-tabs__item--current" href="<?php echo $escape($selfUrl); ?>" aria-current="page">Materials</a>
  <a class="admin-catalogue-tabs__item" href="<?php echo BASE_URL . 'admin/inventory/movements.php'; ?>">Stock Movements</a>
</nav>

<?php if (is_string($materialList['loadError'])): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load materials'; $alertMessage = $materialList['loadError']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($materialList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Some filters were reset'; $alertMessage = 'One or more unsupported filter, sort, or page values were ignored safely.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<!-- SECTION PURPOSE: Group the Search and filters content, states, and controls as one accessible page region. -->
<section class="admin-filter-panel" aria-labelledby="material-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="material-filters-title">Search and filters</h2>
    <a href="<?php echo $escape($selfUrl); ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo $escape($selfUrl); ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="material-search">SKU or name</label>
      <input id="material-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search materials">
    </div>
    <div class="admin-field">
      <label for="material-type">Type</label>
      <select id="material-type" name="type">
        <option value="">All types</option>
        <?php foreach ($materialList['types'] as $type): ?>
          <option value="<?php echo $escape($type['id']); ?>"<?php echo $filters['type'] === (string) $type['id'] ? ' selected' : ''; ?>><?php echo $escape($type['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="material-active">Status</label>
      <select id="material-active" name="active">
        <option value="">All</option>
        <option value="1"<?php echo $filters['active'] === '1' ? ' selected' : ''; ?>>Active</option>
        <option value="0"<?php echo $filters['active'] === '0' ? ' selected' : ''; ?>>Inactive</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="material-tracks">Stock tracking</label>
      <select id="material-tracks" name="tracks_stock">
        <option value="">All</option>
        <option value="1"<?php echo $filters['tracks_stock'] === '1' ? ' selected' : ''; ?>>Tracked</option>
        <option value="0"<?php echo $filters['tracks_stock'] === '0' ? ' selected' : ''; ?>>Not tracked</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="material-sort">Sort by</label>
      <select id="material-sort" name="sort">
        <option value="name"<?php echo $materialList['sort'] === 'name' ? ' selected' : ''; ?>>Name</option>
        <option value="stock_quantity"<?php echo $materialList['sort'] === 'stock_quantity' ? ' selected' : ''; ?>>Lowest stock first</option>
        <option value="unit_cost"<?php echo $materialList['sort'] === 'unit_cost' ? ' selected' : ''; ?>>Unit cost</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="material-direction">Direction</label>
      <select id="material-direction" name="direction">
        <option value="asc"<?php echo $materialList['direction'] === 'asc' ? ' selected' : ''; ?>>Ascending</option>
        <option value="desc"<?php echo $materialList['direction'] === 'desc' ? ' selected' : ''; ?>>Descending</option>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<!-- SECTION PURPOSE: Group the Material records content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="material-results-title">
  <header class="admin-panel__header">
    <div><h2 id="material-results-title">Material records</h2></div>
    <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>">New material</a>
  </header>

  <?php if ($materialList['materials'] === []): ?>
    <?php
      $emptyTitle = is_string($materialList['loadError']) ? 'Materials unavailable' : 'No matching materials';
      $emptyMessage = is_string($materialList['loadError']) ? 'No material data could be displayed safely.' : 'Try clearing filters, or create a new material.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable materials table">
      <table class="admin-table">
        <caption class="admin-visually-hidden">Filtered materials</caption>
        <thead><tr><th scope="col">Material</th><th scope="col">Type</th><th scope="col">Stock</th><th scope="col">Unit cost</th><th scope="col">Status</th></tr></thead>
        <tbody>
          <?php foreach ($materialList['materials'] as $material): ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
            <?php $editUrl = $selfUrl . '?' . http_build_query(['id' => $material['id']]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($editUrl); ?>"><?php echo $escape($material['name']); ?></a><small><?php echo $escape($material['sku']); ?></small></td>
              <td><?php echo $escape($material['type_name']); ?></td>
              <td><?php echo $escape($formatQuantity($material['stock_quantity'], $material['unit'])); ?></td>
              <td><?php echo $escape(number_format((float) $material['unit_cost'], 3)); ?> TND</td>
              <td><span class="admin-badge admin-badge--<?php echo ((int) $material['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $material['is_active'] === 1) ? 'Active' : 'Inactive'; ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <span>Showing <?php echo $escape((($materialList['page'] - 1) * $materialList['perPage']) + 1); ?>–<?php echo $escape(min($materialList['page'] * $materialList['perPage'], $materialList['total'])); ?> of <?php echo $escape($materialList['total']); ?></span>
      <?php
        $currentPage = $materialList['page'];
        $totalPages = $materialList['totalPages'];
        $paginationBaseUrl = $selfUrl;
        $paginationQuery = $materialList['queryState'];
        require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
