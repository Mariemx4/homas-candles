<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;
use App\Services\PaymentService;

$filters = $paymentList['filters'];
$humanize = Presentation::humanize(...);
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    try {
        return \App\Services\BusinessClock::displayTimestamp($value, 'd M Y, H:i');
    } catch (Throwable) {
        return 'Not set';
    }
};
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Payments</h1>
    <p class="admin-page-header__summary">Review D17 proof and confirm payments received on delivery or pickup. Fulfilment does not confirm payment.</p>
  </div>
  <div class="admin-result-summary" aria-live="polite">
    <strong><?php echo $escape($paymentList['total']); ?></strong>
    <span><?php echo (int) $paymentList['total'] === 1 ? 'matching payment' : 'matching payments'; ?></span>
  </div>
</header>

<?php if (is_array($paymentList['overview'])): ?>
  <nav class="admin-payment-queue-summary" aria-label="Payment queues">
    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/payments/index.php?' . http_build_query(['method' => 'D17', 'status' => 'PENDING_VERIFICATION'])); ?>"><?php echo $escape($paymentList['overview']['pending']); ?> D17 payments pending verification</a>
    <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/payments/index.php?status=VERIFIED'; ?>"><?php echo $escape($paymentList['overview']['verified']); ?> verified payments</a>
  </nav>
<?php endif; ?>

<?php if (is_string($paymentList['loadError'])): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Unable to load payments';
    $alertMessage = $paymentList['loadError'];
    require VIEW_PATH . '/Components/Admin/alert.php';
    ?>
<?php elseif ($paymentList['filtersAdjusted']): ?>
  <?php
      $alertTone = 'warning';
        $alertTitle = 'Some filters were reset';
        $alertMessage = 'One or more unsupported filter, date, or page values were ignored safely.';
        require VIEW_PATH . '/Components/Admin/alert.php';
        ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="payment-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="payment-filters-title">Search and filters</h2>
    <a href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>">Reset filters</a>
  </div>
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
          <option value="<?php echo $escape($methodOption); ?>"<?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>><?php echo $escape($methodOption === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $methodOption); ?></option>
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
            $emptyTitle = is_string($paymentList['loadError']) ? 'Payments unavailable' : ($paymentList['hasActiveFilters'] ? 'No matching payments' : 'No payments recorded yet');
      $emptyMessage = is_string($paymentList['loadError'])
          ? 'No payment data could be displayed safely.'
          : ($paymentList['hasActiveFilters'] ? 'Try clearing or changing the current search and filters.' : 'Open an order’s payment page to confirm a receipt. Customer D17 submissions will appear here for review.');
      require VIEW_PATH . '/Components/Admin/empty-state.php';
      ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" role="region" aria-label="Scrollable payments table">
      <table class="admin-table admin-orders-table">
        <caption class="admin-visually-hidden">Filtered payments</caption>
        <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Method</th><th scope="col">Amount</th><th scope="col">Submitted / Recorded</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($paymentList['payments'] as $payment): ?>
            <?php
                $orderUrl = BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $payment['order_id']]);
              $reviewable = (string) $payment['payment_method'] === 'D17' && (string) $payment['payment_status'] === 'PENDING_VERIFICATION';
              ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($orderUrl); ?>"><?php echo $escape($payment['order_number']); ?></a></td>
              <td><?php echo $escape($payment['customer_name_snapshot']); ?></td>
              <td><?php echo $escape($payment['payment_method'] === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $payment['payment_method']); ?><small><?php echo $escape($humanize($payment['payment_type'])); ?></small></td>
              <td><?php echo $escape(number_format((float) $payment['amount'], 3) . ' ' . (string) $payment['currency']); ?><?php if ($reviewable): ?><small>Current amount due: <?php echo $escape($payment['expected_amount']); ?> TND</small><?php endif; ?></td>
              <td><time datetime="<?php echo $escape(\App\Services\BusinessClock::displayTimestamp($payment['created_at'])); ?>"><?php echo $escape($formatDate($payment['created_at'])); ?></time></td>
              <td><?php $status = $payment['payment_status'];
              require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
              <td>
                <div class="admin-payment-row-actions">
                <a href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $payment['order_id']])); ?>" aria-label="<?php echo $escape('View order ' . $payment['order_number']); ?>"><?php echo AdminIcons::render('eye'); ?>View order</a>
                <a href="<?php echo $escape($orderUrl); ?>" aria-label="<?php echo $escape('Payments for order ' . $payment['order_number']); ?>">Order payments</a>
                <?php if ((bool) $payment['has_evidence']): ?>
                  <a href="<?php echo $escape(BASE_URL . 'admin/payments/evidence.php?' . http_build_query(['id' => $payment['id']])); ?>" target="_blank" rel="noopener" aria-label="<?php echo $escape('View proof for payment ' . $payment['id'] . ' (opens in a new tab)'); ?>"><?php echo AdminIcons::render('eye'); ?>View proof</a>
                <?php endif; ?>
                <?php if ($reviewable): ?>
                  <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
                  <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify'])); ?>" aria-label="<?php echo $escape('Review D17 payment ' . $payment['id'] . ' for order ' . $payment['order_number']); ?>">Review</a>
                <?php endif; ?>
                </div>
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
require VIEW_PATH . '/Components/Admin/pagination.php';
?>
    </footer>
  <?php endif; ?>
</section>
