<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Orders; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

/**
 * @var array $orderList
 * @var callable $escape
 */

use App\Services\OrderService;

$filters = $orderList['filters'];
$humanize = static fn(mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDate = static function (string $value): string {
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not set';
};
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Orders</h1>
    <p class="admin-page-header__summary">Find orders by customer, schedule, source, fulfilment, or status.</p>
  </div>
  <a class="admin-button" href="<?php echo BASE_URL . 'admin/orders/create.php'; ?>">Add manual order</a>
  <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/trash.php'; ?>">Trash</a>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($orderList['total']); ?></strong>
    <span><?php echo (int) $orderList['total'] === 1 ? 'matching order' : 'matching orders'; ?></span>
  </div>
</header>

<?php if (isset($listStatusNotice) && ($listStatusNotice['success'] !== null || $listStatusNotice['errors'] !== [])): ?>
  <?php
    $alertTone = $listStatusNotice['success'] !== null ? 'success' : 'error';
    $alertTitle = $listStatusNotice['success'] !== null ? 'Order status updated' : 'Status not updated';
    $alertMessage = $listStatusNotice['success'] ?? implode(' ', array_unique(array_values($listStatusNotice['errors'])));
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>
<?php if (is_string($orderList['loadError'])): ?>
  <?php
  $alertTone = 'error';
  $alertTitle = 'Unable to load orders';
  $alertMessage = $orderList['loadError'];
  require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php elseif ($orderList['filtersAdjusted']): ?>
  <?php
  $alertTone = 'warning';
  $alertTitle = 'Some filters were reset';
  $alertMessage = 'One or more unsupported filter, sort, date, or page values were ignored safely.';
  require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="order-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="order-filters-title">Search and filters</h2>
    <a href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/orders/index.php'; ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="order-search">Order number or customer</label>
      <input id="order-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search orders">
    </div>
    <div class="admin-field">
      <label for="order-status">Status</label>
      <select id="order-status" name="status">
        <option value="">All statuses</option>
        <?php foreach (OrderService::STATUSES as $statusOption): ?>
          <option value="<?php echo $escape($statusOption); ?>" <?php echo $filters['status'] === $statusOption ? ' selected' : ''; ?>>
            <?php echo $escape($humanize($statusOption)); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="order-source">Source</label>
      <select id="order-source" name="source">
        <option value="">All sources</option>
        <?php foreach (OrderService::SOURCES as $sourceOption): ?>
          <option value="<?php echo $escape($sourceOption); ?>" <?php echo $filters['source'] === $sourceOption ? ' selected' : ''; ?>>
            <?php echo $escape($humanize($sourceOption)); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="order-method">Fulfilment</label>
      <select id="order-method" name="method">
        <option value="">All methods</option>
        <?php foreach (OrderService::METHODS as $methodOption): ?>
          <option value="<?php echo $escape($methodOption); ?>" <?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>>
            <?php echo $escape($humanize($methodOption)); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="order-date-from">Required from</label>
      <input id="order-date-from" name="date_from" type="date" value="<?php echo $escape($filters['date_from'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="order-date-to">Required through</label>
      <input id="order-date-to" name="date_to" type="date" value="<?php echo $escape($filters['date_to'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="order-sort">Sort by</label>
      <select id="order-sort" name="sort">
        <option value="required_date" <?php echo $orderList['sort'] === 'required_date' ? ' selected' : ''; ?>>Required date</option>
        <option value="created_at" <?php echo $orderList['sort'] === 'created_at' ? ' selected' : ''; ?>>Creation date</option>
        <option value="total" <?php echo $orderList['sort'] === 'total' ? ' selected' : ''; ?>>Total</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="order-direction">Direction</label>
      <select id="order-direction" name="direction">
        <option value="asc" <?php echo $orderList['direction'] === 'asc' ? ' selected' : ''; ?>>Ascending</option>
        <option value="desc" <?php echo $orderList['direction'] === 'desc' ? ' selected' : ''; ?>>Descending</option>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="order-results-title">
  <header class="admin-panel__header">
    <div>
      <h2 id="order-results-title">Order records</h2>
    </div>
    <?php if ((int) $orderList['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($orderList['page']); ?> of <?php echo $escape($orderList['totalPages']); ?></span>
    <?php endif; ?>
  </header> 

  <?php if ($orderList['orders'] === []): ?>
    <?php
    $emptyTitle = is_string($orderList['loadError']) ? 'Orders unavailable' : 'No matching orders';
    $emptyMessage = is_string($orderList['loadError'])
      ? 'No order data could be displayed safely.'
      : 'Try clearing or changing the current search and filters.';
    require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable orders table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered orders</caption>
        <thead>
          <tr>
            <th scope="col">Order</th>
            <th scope="col">Customer</th>
            <th scope="col">Required</th>
            <th scope="col">Fulfilment</th>
            <th scope="col">Total</th>
            <th scope="col">Timing</th>
            <th scope="col">Order status</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orderList['orders'] as $order): ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value. 
            ?>
            <?php $detailUrl = BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $order['id'], 'return' => $orderList['returnQuery']]); ?>
            <tr>
              <td>
                <a class="admin-table__primary-link" href="<?php echo $escape($detailUrl); ?>"><?php echo $escape($order['order_number']); ?></a>
                <small><?php echo $escape($humanize($order['order_source'])); ?></small>
              </td>
              <td>
                <?php echo $escape($order['customer_name_snapshot']); ?>
              </td>
              <td>
                <time datetime="<?php echo $escape($order['required_date']); ?>"><?php echo $escape($formatDate((string) $order['required_date'])); ?></time>
              </td>
              <td>
                <?php echo $escape($humanize($order['delivery_method'])); ?>
              </td>
              <td>
                <?php echo $escape(number_format((float) $order['total_amount'], 3)); ?> TND
              </td>
              <td>
                <span class="admin-badge admin-badge--<?php echo $escape($order['timing']['tone']); ?>"><?php echo $escape($order['timing']['label']); ?></span>
              </td>
              <td>
                <?php if (isset($order['statusOptions'])): ?>
                  <?php
                    $statusControlOrder = $order;
                    $statusControlOptions = $order['statusOptions'];
                    $statusControlReturn = $orderList['returnQuery'];
                    $statusControlDestination = 'list';
                    $statusControlReason = $order['statusReason'];
                    require __DIR__ . '/../../Components/Admin/order-status-select.php';
                  ?>
                <?php else: ?>
                  <?php $status = $order['order_status']; require __DIR__ . '/../../Components/Admin/status-badge.php'; ?>
                <?php endif; ?>
              </td>
              <td><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/edit.php?' . http_build_query(['id' => $order['id'], 'return' => $orderList['returnQuery']])); ?>" aria-label="<?php echo $escape('Edit order ' . $order['order_number']); ?>">Edit</a> <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/delete.php?id=' . $order['id']); ?>">Delete</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <span>Showing <?php echo $escape((($orderList['page'] - 1) * $orderList['perPage']) + 1); ?>–<?php echo $escape(min($orderList['page'] * $orderList['perPage'], $orderList['total'])); ?> of <?php echo $escape($orderList['total']); ?></span>
      <?php
      $currentPage = $orderList['page'];
      $totalPages = $orderList['totalPages'];
      $paginationBaseUrl = BASE_URL . 'admin/orders/index.php';
      $paginationQuery = $orderList['queryState'];
      require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
<script src="<?php echo BASE_URL . 'assets/js/admin-order-status.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-order-status.js'); ?>" defer></script>
