<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Inventory Movements; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\MaterialService;

$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y, H:i');
    } catch (Throwable) {
        return 'Not set';
    }
};
$filters = $movementList['filters'];
$allTypes = array_merge(MaterialService::MANUAL_MOVEMENT_TYPES, ['ORDER_DEDUCTION', 'ORDER_RESTOCK']);
$selfMovementsUrl = BASE_URL . 'admin/inventory/movements.php';
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Stock Movements</h1>
    <p class="admin-page-header__summary">The complete, immutable audit trail behind every material balance.</p>
  </div>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($movementList['total']); ?></strong>
    <span><?php echo (int) $movementList['total'] === 1 ? 'matching movement' : 'matching movements'; ?></span>
  </div>
</header>

<nav class="admin-catalogue-tabs" aria-label="Inventory sections">
  <a class="admin-catalogue-tabs__item" href="<?php echo BASE_URL . 'admin/inventory/materials.php'; ?>">Materials</a>
  <a class="admin-catalogue-tabs__item admin-catalogue-tabs__item--current" href="<?php echo $escape($selfMovementsUrl); ?>" aria-current="page">Stock Movements</a>
</nav>

<?php if (is_string($movementList['loadError'])): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load movements'; $alertMessage = $movementList['loadError']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($movementList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Some filters were reset'; $alertMessage = 'One or more unsupported filter or page values were ignored safely.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<!-- SECTION PURPOSE: Group the Filters content, states, and controls as one accessible page region. -->
<section class="admin-filter-panel" aria-labelledby="movement-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="movement-filters-title">Filters</h2>
    <a href="<?php echo $escape($selfMovementsUrl); ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo $escape($selfMovementsUrl); ?>" method="get">
    <div class="admin-field">
      <label for="movement-material">Material ID</label>
      <input id="movement-material" name="material" type="text" inputmode="numeric" maxlength="19" value="<?php echo $escape($filters['material'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="movement-type-filter">Type</label>
      <select id="movement-type-filter" name="type">
        <option value="">All types</option>
        <?php foreach ($allTypes as $type): ?>
          <option value="<?php echo $escape($type); ?>"<?php echo $filters['type'] === $type ? ' selected' : ''; ?>><?php echo $escape($humanize($type)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="movement-order">Order ID</label>
      <input id="movement-order" name="order" type="text" inputmode="numeric" maxlength="19" value="<?php echo $escape($filters['order'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="movement-administrator">Administrator ID</label>
      <input id="movement-administrator" name="administrator" type="text" inputmode="numeric" maxlength="19" value="<?php echo $escape($filters['administrator'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="movement-date-from">From</label>
      <input id="movement-date-from" name="date_from" type="date" value="<?php echo $escape($filters['date_from'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="movement-date-to">Through</label>
      <input id="movement-date-to" name="date_to" type="date" value="<?php echo $escape($filters['date_to'] ?? ''); ?>">
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<!-- SECTION PURPOSE: Group the Movements content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-panel--table" aria-labelledby="movement-results-title">
  <header class="admin-panel__header"><div><h2 id="movement-results-title">Movements</h2></div></header>

  <?php if ($movementList['movements'] === []): ?>
    <?php
      $emptyTitle = is_string($movementList['loadError']) ? 'Movements unavailable' : 'No matching movements';
      $emptyMessage = is_string($movementList['loadError']) ? 'No movement data could be displayed safely.' : 'Try clearing or changing the current filters.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable stock movements table">
      <table class="admin-table">
        <caption class="admin-visually-hidden">Filtered stock movements</caption>
        <thead><tr><th scope="col">Material</th><th scope="col">Type</th><th scope="col">Change</th><th scope="col">Balance</th><th scope="col">Order</th><th scope="col">By</th><th scope="col">When</th><th scope="col">Note</th></tr></thead>
        <tbody>
          <?php foreach ($movementList['movements'] as $movement): ?>
            <tr>
              <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
              <td><a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/inventory/materials.php?' . http_build_query(['id' => $movement['material_id']])); ?>"><?php echo $escape($movement['material_name']); ?></a><small><?php echo $escape($movement['material_sku']); ?></small></td>
              <td><?php echo $escape($humanize($movement['movement_type'])); ?></td>
              <td><?php echo $escape(number_format((float) $movement['quantity_change'], 3)); ?> <?php echo $escape($movement['material_unit']); ?></td>
              <td><?php echo $escape(number_format((float) $movement['stock_before'], 3)); ?> → <?php echo $escape(number_format((float) $movement['stock_after'], 3)); ?></td>
              <td><?php echo $escape($movement['order_number'] ?: '—'); ?></td>
              <td><?php echo $escape($movement['administrator_name'] ?: 'System'); ?></td>
              <td><time datetime="<?php echo $escape($movement['created_at']); ?>"><?php echo $escape($formatDate($movement['created_at'])); ?></time></td>
              <td><?php echo $escape($movement['movement_note'] ?: '—'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-list-footer" aria-live="polite">
      Showing <?php echo $escape((($movementList['page'] - 1) * $movementList['perPage']) + 1); ?>–<?php echo $escape(min($movementList['page'] * $movementList['perPage'], $movementList['total'])); ?> of <?php echo $escape($movementList['total']); ?>
    </footer>
    <?php
      $currentPage = $movementList['page'];
      $totalPages = $movementList['totalPages'];
      $paginationBaseUrl = $selfMovementsUrl;
      $paginationQuery = $movementList['queryState'];
      require __DIR__ . '/../../Components/Admin/pagination.php';
    ?>
  <?php endif; ?>
</section>
