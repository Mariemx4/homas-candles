<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Production Queue; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\ProductionService;

$filters = $productionQueue['filters'];
$filterOptions = $productionQueue['filterOptions'];
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDate = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not set';
};
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Production queue</h1>
    <p class="admin-page-header__summary">Confirmed and preparing orders, ordered by overdue state and required date.</p>
  </div>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($productionQueue['total']); ?></strong>
    <span><?php echo (int) $productionQueue['total'] === 1 ? 'order in queue' : 'orders in queue'; ?></span>
  </div>
</header>

<?php require __DIR__ . '/../../Components/Admin/production-tabs.php'; ?>

<?php if (is_string($productionQueue['loadError'])): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Unable to load the production queue';
    $alertMessage = $productionQueue['loadError'];
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php elseif ($productionQueue['filtersAdjusted']): ?>
  <?php
    $alertTone = 'warning';
    $alertTitle = 'Some filters were reset';
    $alertMessage = 'One or more unsupported filter, sort, date, or page values were ignored safely.';
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="production-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="production-filters-title">Search and filters</h2>
    <a href="<?php echo BASE_URL . 'admin/production/queue.php'; ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/production/queue.php'; ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="production-search">Order number or customer</label>
      <input id="production-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search orders">
    </div>
    <div class="admin-field">
      <label for="production-status">Status</label>
      <select id="production-status" name="status">
        <option value="">All queue statuses</option>
        <?php foreach (ProductionService::STATUSES as $statusOption): ?>
          <option value="<?php echo $escape($statusOption); ?>"<?php echo $filters['status'] === $statusOption ? ' selected' : ''; ?>><?php echo $escape($humanize($statusOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-method">Fulfilment</label>
      <select id="production-method" name="method">
        <option value="">All methods</option>
        <?php foreach (ProductionService::METHODS as $methodOption): ?>
          <option value="<?php echo $escape($methodOption); ?>"<?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>><?php echo $escape($humanize($methodOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-product">Product</label>
      <select id="production-product" name="product">
        <option value="">All products</option>
        <?php foreach ($filterOptions['products'] as $product): ?>
          <option value="<?php echo $escape($product['id']); ?>"<?php echo (string) $filters['product_id'] === (string) $product['id'] ? ' selected' : ''; ?>><?php echo $escape($product['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-scent">Scent</label>
      <select id="production-scent" name="scent">
        <option value="">All scents</option>
        <?php foreach ($filterOptions['scents'] as $scent): ?>
          <option value="<?php echo $escape($scent['id']); ?>"<?php echo (string) $filters['scent_id'] === (string) $scent['id'] ? ' selected' : ''; ?>><?php echo $escape($scent['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-color">Color</label>
      <select id="production-color" name="color">
        <option value="">All colors</option>
        <?php foreach ($filterOptions['colors'] as $color): ?>
          <option value="<?php echo $escape($color['id']); ?>"<?php echo (string) $filters['color_id'] === (string) $color['id'] ? ' selected' : ''; ?>><?php echo $escape($color['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-date-from">Required from</label>
      <input id="production-date-from" name="date_from" type="date" value="<?php echo $escape($filters['date_from'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="production-date-to">Required through</label>
      <input id="production-date-to" name="date_to" type="date" value="<?php echo $escape($filters['date_to'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="production-sort">Sort by</label>
      <select id="production-sort" name="sort">
        <option value="priority"<?php echo $productionQueue['sort'] === 'priority' ? ' selected' : ''; ?>>Overdue first</option>
        <option value="required_date"<?php echo $productionQueue['sort'] === 'required_date' ? ' selected' : ''; ?>>Required date</option>
        <option value="created_at"<?php echo $productionQueue['sort'] === 'created_at' ? ' selected' : ''; ?>>Creation date</option>
        <option value="total"<?php echo $productionQueue['sort'] === 'total' ? ' selected' : ''; ?>>Total</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="production-direction">Direction</label>
      <select id="production-direction" name="direction">
        <option value="asc"<?php echo $productionQueue['direction'] === 'asc' ? ' selected' : ''; ?>>Ascending</option>
        <option value="desc"<?php echo $productionQueue['direction'] === 'desc' ? ' selected' : ''; ?>>Descending</option>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="production-results-title">
  <header class="admin-panel__header">
    <div>
      <h2 id="production-results-title">Queued orders</h2>
    </div>
    <?php if ((int) $productionQueue['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($productionQueue['page']); ?> of <?php echo $escape($productionQueue['totalPages']); ?></span>
    <?php endif; ?>
  </header>

  <?php if ($productionQueue['orders'] === []): ?>
    <?php
      $emptyTitle = is_string($productionQueue['loadError']) ? 'Production queue unavailable' : 'No matching orders';
      $emptyMessage = is_string($productionQueue['loadError'])
          ? 'No production data could be displayed safely.'
          : 'Try clearing or changing the current search and filters.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable production queue table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered production queue</caption>
        <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Required</th><th scope="col">Fulfilment</th><th scope="col">Total</th><th scope="col">Status</th><th scope="col">Timing</th></tr></thead>
        <tbody>
          <?php foreach ($productionQueue['orders'] as $order): ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
            <?php $detailUrl = BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $order['id']]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($detailUrl); ?>"><?php echo $escape($order['order_number']); ?></a></td>
              <td><?php echo $escape($order['customer_name_snapshot']); ?></td>
              <td><time datetime="<?php echo $escape($order['required_date']); ?>"><?php echo $escape($formatDate((string) $order['required_date'])); ?></time></td>
              <td><?php echo $escape($humanize($order['delivery_method'])); ?></td>
              <td><?php echo $escape(number_format((float) $order['total_amount'], 3)); ?> TND</td>
              <td><?php $status = $order['order_status']; require __DIR__ . '/../../Components/Admin/status-badge.php'; ?></td>
              <td><span class="admin-badge admin-badge--<?php echo $escape($order['timing']['tone']); ?>"><?php echo $escape($order['timing']['label']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer admin-print-hide">
      <span>Showing <?php echo $escape((($productionQueue['page'] - 1) * $productionQueue['perPage']) + 1); ?>–<?php echo $escape(min($productionQueue['page'] * $productionQueue['perPage'], $productionQueue['total'])); ?> of <?php echo $escape($productionQueue['total']); ?></span>
      <?php
        $currentPage = $productionQueue['page'];
        $totalPages = $productionQueue['totalPages'];
        $paginationBaseUrl = BASE_URL . 'admin/production/queue.php';
        $paginationQuery = $productionQueue['queryState'];
        require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
