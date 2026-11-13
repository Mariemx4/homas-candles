<?php

declare(strict_types=1);

use App\View\Presentation;
/**
 * @var array $orderList
 * @var callable $escape
 */

use App\Services\OrderService;

$filters = $orderList['filters'];
$humanize = Presentation::humanize(...);
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
  <div class="admin-order-header__actions">
    <a class="admin-button" href="<?php echo BASE_URL . 'admin/orders/create.php'; ?>">+ Create order</a>
    <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/trash.php'; ?>">Trash</a>
  </div>
</header>

<?php if (isset($listStatusNotice) && ($listStatusNotice['success'] !== null || $listStatusNotice['errors'] !== [])): ?>
  <?php
    $alertTone = $listStatusNotice['success'] !== null ? 'success' : 'error';
    $alertTitle = $listStatusNotice['success'] !== null ? 'Order status updated' : 'Status not updated';
    $alertMessage = $listStatusNotice['success'] ?? implode(' ', array_unique(array_values($listStatusNotice['errors'])));
    require VIEW_PATH . '/Components/Admin/alert.php';
    ?>
<?php endif; ?>
<?php if (is_string($orderList['loadError'])): ?>
  <?php
    $alertTone = 'error';
        $alertTitle = 'Unable to load orders';
        $alertMessage = $orderList['loadError'];
        require VIEW_PATH . '/Components/Admin/alert.php';
        ?>
<?php elseif ($orderList['filtersAdjusted']): ?>
  <?php
        $alertTone = 'warning';
            $alertTitle = 'Some filters were reset';
            $alertMessage = 'One or more unsupported filter, sort, date, or page values were ignored safely.';
            require VIEW_PATH . '/Components/Admin/alert.php';
            ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="order-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="order-filters-title">Search and filters</h2>
    <a href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Reset filters</a>
  </div>
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/orders/index.php'; ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="order-search">Order number or customer</label>
      <input id="order-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search orders">
    </div>
    <div class="admin-field">
      <label for="order-status">Status</label>
      <select id="order-status" name="status">
        <option value="">All statuses</option>
        <option value="INCOMPLETE"<?php echo $filters['status'] === 'INCOMPLETE' ? ' selected' : ''; ?>>Incomplete orders</option>
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
      <?php if ($orderList['loadError'] === null): ?>
        <span class="admin-panel__meta"><?php echo $escape($orderList['total']); ?> <?php echo (int) $orderList['total'] === 1 ? 'order' : 'orders'; ?></span>
      <?php endif; ?>
    </div>
    <?php if ((int) $orderList['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($orderList['page']); ?> of <?php echo $escape($orderList['totalPages']); ?></span>
    <?php endif; ?>
  </header> 

  <?php if ($orderList['orders'] === []): ?>
    <?php
              $emptyTitle = is_string($orderList['loadError']) ? 'Orders unavailable' : ($orderList['hasActiveFilters'] ? 'No matching orders' : 'No orders yet');
      $emptyMessage = is_string($orderList['loadError'])
        ? 'No order data could be displayed safely.'
        : ($orderList['hasActiveFilters'] ? 'Try changing or clearing the current filters.' : 'Orders created manually or received through the shop will appear here.');
      require VIEW_PATH . '/Components/Admin/empty-state.php';
      ?>
    <?php if ($orderList['loadError'] === null): ?>
      <div class="admin-orders-empty-actions">
        <?php if ($orderList['hasActiveFilters']): ?>
          <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Reset filters</a>
        <?php else: ?>
          <a class="admin-button" href="<?php echo BASE_URL . 'admin/orders/create.php'; ?>">+ Create first order</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" role="region" aria-label="Scrollable orders table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered orders</caption>
        <thead>
          <tr>
            <th scope="col">Order</th>
            <th scope="col">Customer</th>
            <th scope="col">Required date</th>
            <th scope="col">Fulfilment</th>
            <th scope="col">Total</th>
            <th scope="col">Payment</th>
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
                <?php if ($order['customer_id'] !== null): ?>
                  <small><a href="<?php echo $escape(BASE_URL . 'admin/customers/detail.php?' . http_build_query(['id' => $order['customer_id']])); ?>" aria-label="<?php echo $escape('View linked customer account for order ' . $order['order_number']); ?>">Customer account</a></small>
                <?php endif; ?>
              </td>
              <td>
                <time datetime="<?php echo $escape($order['required_date']); ?>"><?php echo $escape($formatDate((string) $order['required_date'])); ?></time>
                <small><span class="admin-badge admin-badge--<?php echo $escape($order['deadline']['tone']); ?>"><?php echo $escape($order['deadline']['label']); ?></span></small>
              </td>
              <td>
                <?php echo $escape($humanize($order['delivery_method'])); ?>
              </td>
              <td class="admin-table__money">
                <?php echo $escape(number_format((float) $order['total_amount'], 3)); ?> TND
              </td>
              <td>
                <span class="admin-badge admin-badge--<?php echo $escape($order['paymentSummary']['tone']); ?>"><?php echo $escape($order['paymentSummary']['label']); ?></span>
                <?php if ($order['paymentSummary']['hasPending'] && $order['paymentSummary']['label'] !== 'Pending verification'): ?>
                  <small>Pending verification</small>
                <?php endif; ?>
              </td>
              <td>
                <?php if (isset($order['statusOptions'])): ?>
                  <?php
                      $statusControlOrder = $order;
                    $statusControlOptions = $order['statusOptions'];
                    $statusControlReturn = $orderList['returnQuery'];
                    $statusControlDestination = 'list';
                    $statusControlReason = $order['statusReason'];
                    require VIEW_PATH . '/Components/Admin/order-status-select.php';
                    ?>
                <?php else: ?>
                  <?php echo $escape($humanize($order['order_status'])); ?>
                <?php endif; ?>
              </td>
              <td>
                <div class="admin-order-row-actions">
                  <a class="admin-button admin-button--ghost admin-button--icon" href="<?php echo $escape($detailUrl); ?>" title="View order" aria-label="<?php echo $escape('View order ' . $order['order_number']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></a>
                  <a class="admin-button admin-button--ghost admin-button--icon" href="<?php echo $escape(BASE_URL . 'admin/orders/edit.php?' . http_build_query(['id' => $order['id'], 'return' => $orderList['returnQuery']])); ?>" title="Edit order" aria-label="<?php echo $escape('Edit order ' . $order['order_number']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5M4 20l4-1L21 6a2 2 0 0 0-3-3L5 16l-1 4Z"/></svg></a>
                  <a class="admin-button admin-button--ghost admin-button--icon" href="<?php echo $escape(BASE_URL . 'admin/orders/delete.php?' . http_build_query(['id' => $order['id']])); ?>" title="Delete order" aria-label="<?php echo $escape('Delete order ' . $order['order_number']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/></svg></a>
                </div>
              </td>
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
require VIEW_PATH . '/Components/Admin/pagination.php';
?>
    </footer>
  <?php endif; ?>
</section>
<script src="<?php echo BASE_URL . 'assets/js/admin/order-status.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/order-status.js'); ?>" defer></script>
