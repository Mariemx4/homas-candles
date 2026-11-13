<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;

$formatMoney = Presentation::money(...);
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
$humanize = Presentation::humanize(...);
$recordForm ??= ['errors' => [], 'values' => [], 'success' => null];
$recordNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$values = $recordForm['values'];
$errors = $recordForm['errors'];
?>
<?php if (!is_array($paymentOrder['detail'])): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="payment-order-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="payment-order-error-title"><?php echo (int) $paymentOrder['httpStatus'] === 404 ? 'Order not found' : 'Unable to show order payments'; ?></h1>
      <p><?php echo $escape($paymentOrder['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>"><?php echo AdminIcons::render('back'); ?>Back to payments</a>
    </div>
  </section>
<?php else: ?>
  <?php $order = $paymentOrder['detail']['order'];
    $payments = $paymentOrder['detail']['payments']; ?>
  <header class="admin-order-header">
    <div>
      <a class="admin-back-link" href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>">← Back to payments</a>
      <p class="admin-eyebrow">Order payment record</p>
      <div class="admin-order-header__title">
        <h1><?php echo $escape($order['order_number']); ?></h1>
        <?php $status = $order['order_status'];
    require VIEW_PATH . '/Components/Admin/status-badge.php'; ?>
      </div>
      <p><?php echo $escape($order['customer_name_snapshot']); ?></p>
    </div>
    <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $order['id']])); ?>"><?php echo AdminIcons::render('eye'); ?>View order</a>
  </header>

  <?php if (is_string($recordNotice['success'])): ?>
    <?php
      $alertTone = 'success';
      $alertTitle = 'Payment updated';
      $alertMessage = $recordNotice['success'];
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <?php if (isset($errors['form'])): ?>
    <?php
        $alertTone = 'error';
      $alertTitle = 'Payment not recorded';
      $alertMessage = $errors['form'];
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <div class="admin-detail-grid admin-payment-summary-grid">
    <section class="admin-panel admin-detail-card" aria-labelledby="payment-summary-title">
      <header class="admin-panel__header"><h2 id="payment-summary-title">Payment summary</h2></header>
      <dl class="admin-money-list">
        <div><dt>Order total</dt><dd><?php echo $escape($formatMoney($order['total_amount'])); ?></dd></div>
        <div><dt>Required deposit</dt><dd><?php echo $escape($formatMoney($order['required_deposit_amount'])); ?></dd></div>
        <div><dt>Verified</dt><dd><?php echo $escape($formatMoney($order['verified_amount'])); ?></dd></div>
        <div><dt>Pending review</dt><dd><?php echo $escape($formatMoney($order['pending_amount'])); ?></dd></div>
        <div><dt>Rejected</dt><dd><?php echo $escape($formatMoney($order['rejected_amount'])); ?></dd></div>
        <div class="admin-money-list__balance"><dt>Remaining balance</dt><dd><?php echo $escape($formatMoney($order['remaining_balance'])); ?></dd></div>
        <div><dt>Payment status</dt><dd><?php echo $escape($order['payment_status_label']); ?></dd></div>
        <div><dt>Recorded payment methods</dt><dd><?php echo $escape($order['payment_methods_label']); ?></dd></div>
        <?php if ($order['credit_balance'] !== '0.000'): ?><div><dt>Credit / refund due</dt><dd><?php echo $escape($formatMoney($order['credit_balance'])); ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <section class="admin-panel admin-detail-card admin-payment-form-card" aria-labelledby="record-payment-title">
      <header class="admin-panel__header"><h2 id="record-payment-title">Confirm a payment received</h2></header>
      <?php if ($order['deleted_at'] !== null || $order['recordable_amount'] === '0.000'): ?>
        <p class="admin-payment-unavailable">No new payment can be recorded: this order is deleted, settled, or its remaining amount is awaiting verification.</p>
      <?php else: ?>
      <?php $paymentRecordOrder = $order;
          $recordInOrder = false;
          require VIEW_PATH . '/Components/Admin/payment-record-form.php'; ?>
      <?php endif; ?>
    </section>
  </div>

  <section class="admin-panel admin-panel--table" aria-labelledby="payment-history-title">
    <header class="admin-panel__header"><div><h2 id="payment-history-title">Recorded payments</h2></div><span class="admin-panel__count"><?php echo $escape(count($payments)); ?></span></header>
    <?php if ($payments === []): ?>
      <?php $emptyTitle = 'No payments recorded';
        $emptyMessage = 'Payments recorded for this order will appear here.';
        require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable order payments table">
        <table class="admin-table">
          <caption class="admin-visually-hidden">Payments recorded for this order</caption>
          <thead><tr><th scope="col">Type</th><th scope="col">Method</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Recorded</th><th scope="col">Reviewer</th><th scope="col">Notes</th><th scope="col">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $payment): ?>
              <?php $reviewable = (string) $payment['payment_method'] === 'D17' && (string) $payment['payment_status'] === 'PENDING_VERIFICATION'; ?>
              <tr>
                <td><?php echo $escape($humanize($payment['payment_type'])); ?></td>
                <td><?php echo $escape($payment['payment_method'] === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $payment['payment_method']); ?></td>
                <td><?php echo $escape($formatMoney($payment['amount'])); ?></td>
                <td><?php $status = $payment['payment_status'];
                require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
                <td><time datetime="<?php echo $escape(\App\Services\BusinessClock::displayTimestamp($payment['created_at'])); ?>"><?php echo $escape($formatDate($payment['created_at'])); ?></time></td>
                <td><?php echo $escape($payment['reviewer_name'] ?: 'Not yet reviewed'); ?><?php if ($payment['verified_at'] !== null || $payment['rejected_at'] !== null): ?><small><?php echo $escape($formatDate($payment['verified_at'] ?? $payment['rejected_at'])); ?></small><?php endif; ?></td>
                <td><?php echo $escape($payment['rejection_reason'] ?: '—'); ?></td>
                <td>
                  <?php if ($reviewable): ?>
                    <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
                    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify'])); ?>">Review</a>
                  <?php elseif ((bool) $payment['has_evidence']): ?>
                    <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
                    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/payments/evidence.php?' . http_build_query(['id' => $payment['id']])); ?>" target="_blank" rel="noopener"><?php echo AdminIcons::render('eye'); ?>View evidence</a>
                  <?php else: ?>
                    <span class="admin-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<script src="<?php echo BASE_URL . 'assets/js/admin/payment-record.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/payment-record.js'); ?>" defer></script>
