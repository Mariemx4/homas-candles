<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Payments; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\PaymentService;

$filters = $paymentList['filters'];
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
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Payments</h1>
    <p class="admin-page-header__summary">Review recorded payments and verify or reject pending D17 transfers.</p>
  </div>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($paymentList['total']); ?></strong>
    <span><?php echo (int) $paymentList['total'] === 1 ? 'matching payment' : 'matching payments'; ?></span>
  </div>
</header>

<?php if (is_string($paymentList['loadError'])): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Unable to load payments';
    $alertMessage = $paymentList['loadError'];
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php elseif ($paymentList['filtersAdjusted']): ?>
  <?php
    $alertTone = 'warning';
    $alertTitle = 'Some filters were reset';
    $alertMessage = 'One or more unsupported filter, date, or page values were ignored safely.';
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="payment-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="payment-filters-title">Search and filters</h2>
    <a href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>">Clear all</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/payments/index.php'; ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="payment-search">Order number or customer</label>
      <input id="payment-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search payments">
    </div>
    <div class="admin-field">
      <label for="payment-status">Status</label>
      <select id="payment-status" name="status">
        <option value="">All statuses</option>
        <?php foreach (PaymentService::STATUSES as $statusOption): ?>
          <option value="<?php echo $escape($statusOption); ?>"<?php echo $filters['status'] === $statusOption ? ' selected' : ''; ?>><?php echo $escape($humanize($statusOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="payment-method">Method</label>
      <select id="payment-method" name="method">
        <option value="">All methods</option>
        <?php foreach (PaymentService::METHODS as $methodOption): ?>
          <option value="<?php echo $escape($methodOption); ?>"<?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>><?php echo $escape($humanize($methodOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="payment-date-from">Recorded from</label>
      <input id="payment-date-from" name="date_from" type="date" value="<?php echo $escape($filters['date_from'] ?? ''); ?>">
    </div>
    <div class="admin-field">
      <label for="payment-date-to">Recorded through</label>
      <input id="payment-date-to" name="date_to" type="date" value="<?php echo $escape($filters['date_to'] ?? ''); ?>">
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="payment-results-title">
  <header class="admin-panel__header">
    <div>
      <h2 id="payment-results-title">Payment records</h2>
    </div>
    <?php if ((int) $paymentList['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($paymentList['page']); ?> of <?php echo $escape($paymentList['totalPages']); ?></span>
    <?php endif; ?>
  </header>

  <?php if ($paymentList['payments'] === []): ?>
    <?php
      $emptyTitle = is_string($paymentList['loadError']) ? 'Payments unavailable' : 'No matching payments';
      $emptyMessage = is_string($paymentList['loadError'])
          ? 'No payment data could be displayed safely.'
          : 'Try clearing or changing the current search and filters.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable payments table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered payments</caption>
        <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Type</th><th scope="col">Method</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Recorded</th><th scope="col">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($paymentList['payments'] as $payment): ?>
            <?php
              // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
              $orderUrl = BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $payment['order_id']]);
              $reviewable = (string) $payment['payment_method'] === 'D17' && (string) $payment['payment_status'] === 'PENDING_VERIFICATION';
            ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($orderUrl); ?>"><?php echo $escape($payment['order_number']); ?></a></td>
              <td><?php echo $escape($payment['customer_name_snapshot']); ?></td>
              <td><?php echo $escape($humanize($payment['payment_type'])); ?></td>
              <td><?php echo $escape($humanize($payment['payment_method'])); ?></td>
              <td><?php echo $escape(number_format((float) $payment['amount'], 3) . ' ' . (string) $payment['currency']); ?></td>
              <td><?php $status = $payment['payment_status']; require __DIR__ . '/../../Components/Admin/status-badge.php'; ?></td>
              <td><time datetime="<?php echo $escape($payment['created_at']); ?>"><?php echo $escape($formatDate($payment['created_at'])); ?></time></td>
              <td>
                <?php if ($reviewable): ?>
                  <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
                  <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify'])); ?>">Review</a>
                <?php else: ?>
                  <span class="admin-muted">No action</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <span>Showing <?php echo $escape((($paymentList['page'] - 1) * $paymentList['perPage']) + 1); ?>–<?php echo $escape(min($paymentList['page'] * $paymentList['perPage'], $paymentList['total'])); ?> of <?php echo $escape($paymentList['total']); ?></span>
      <?php
        $currentPage = $paymentList['page'];
        $totalPages = $paymentList['totalPages'];
        $paginationBaseUrl = BASE_URL . 'admin/payments/index.php';
        $paginationQuery = $paymentList['queryState'];
        require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
