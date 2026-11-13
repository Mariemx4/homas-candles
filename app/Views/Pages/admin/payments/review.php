<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;

$formatMoney = Presentation::money(...);
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not provided';
    }
    try {
        return \App\Services\BusinessClock::displayTimestamp($value, 'd M Y, H:i');
    } catch (Throwable) {
        return 'Not provided';
    }
};
$humanize = Presentation::humanize(...);
$reviewForm ??= ['errors' => [], 'values' => [], 'success' => null];
$generalError = $reviewForm['errors']['form'] ?? null;
$reasonError = $reviewForm['errors']['reason'] ?? null;
$payment = $paymentReview['payment'];
$action = $paymentReview['action'] === 'reject' ? 'reject' : 'verify';
?>
<?php if (!is_array($payment)): ?>
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>Review unavailable</h1>
      <p><?php echo $escape($paymentReview['loadError'] ?? 'The requested payment could not be loaded.'); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>"><?php echo AdminIcons::render('back'); ?>Back to payments</a>
    </div>
  </section>
<?php elseif (!$payment['reviewable']): ?>
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>This payment cannot be reviewed</h1>
      <p>Only a pending D17 payment can be verified or rejected. This payment has already been reviewed or does not require review.</p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to order payments</a>
    </div>
  </section>
<?php else: ?>
  <header class="admin-order-header">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($backUrl); ?>">← Cancel and return to order payments</a>
      <h1>Review D17 payment</h1>
      <p>Order <?php echo $escape($payment['order_number']); ?></p>
    </div>
  </header>

  <?php if ($generalError !== null): ?>
    <?php
      $alertTone = 'error';
      $alertTitle = 'Decision not completed';
      $alertMessage = $generalError;
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <div class="admin-transition-confirmation">
    <section class="admin-panel admin-transition-confirmation__summary" aria-labelledby="payment-summary-title">
      <header class="admin-panel__header"><h2 id="payment-summary-title">Payment details</h2></header>
      <dl class="admin-definition-list">
        <div><dt>Customer</dt><dd><?php echo $escape($payment['customer_name_snapshot']); ?></dd></div>
        <div><dt>Current amount due for this payment type</dt><dd><?php echo $escape($formatMoney($payment['expected_amount'])); ?></dd></div>
        <div><dt>Status</dt><dd><?php $status = $payment['payment_status'];
require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></dd></div>
        <div><dt>Type</dt><dd><?php echo $escape($humanize($payment['payment_type'])); ?></dd></div>
        <div><dt>Amount</dt><dd><?php echo $escape($formatMoney($payment['amount'])); ?></dd></div>
        <div><dt>D17 reference</dt><dd><?php echo $escape($payment['d17_reference'] ?: 'Not provided'); ?></dd></div>
        <div><dt>Customer-declared time</dt><dd><?php echo $escape($formatDate($payment['customer_declared_paid_at'])); ?></dd></div>
        <div><dt>Recorded</dt><dd><time datetime="<?php echo $escape(\App\Services\BusinessClock::displayTimestamp($payment['created_at'])); ?>"><?php echo $escape($formatDate($payment['created_at'])); ?></time></dd></div>
        <div>
          <dt>Evidence</dt>
          <dd>
            <?php if ((bool) $payment['has_evidence']): ?>
              <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
              <a href="<?php echo $escape(BASE_URL . 'admin/payments/evidence.php?' . http_build_query(['id' => $payment['id']])); ?>" target="_blank" rel="noopener"><?php echo AdminIcons::render('eye'); ?>View uploaded evidence</a>
            <?php else: ?>
              No evidence uploaded
            <?php endif; ?>
          </dd>
        </div>
      </dl>
      <p class="admin-payment-unavailable">Compare the recorded amount with the actual transfer. The current amount due reflects verified payments and the current order snapshot.</p>
      <p class="admin-payment-unavailable"><a href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $payment['order_id']])); ?>"><?php echo AdminIcons::render('eye'); ?>View order</a></p>
    </section>

    <section class="admin-panel admin-transition-confirmation__form" aria-labelledby="payment-decision-title">
      <header class="admin-panel__header"><h2 id="payment-decision-title">Administrator decision</h2></header>
      <nav class="admin-payment-decision-tabs" aria-label="Decision type">
        <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
        <a <?php echo $action === 'verify' ? 'aria-current="page" ' : ''; ?>class="admin-button<?php echo $action === 'verify' ? '' : ' admin-button--secondary'; ?>" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify'])); ?>"><?php echo AdminIcons::render('check'); ?>Approve</a>
        <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
        <a <?php echo $action === 'reject' ? 'aria-current="page" ' : ''; ?>class="admin-button<?php echo $action === 'reject' ? '' : ' admin-button--secondary'; ?>" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'reject'])); ?>">Reject</a>
      </nav>

      <form class="admin-status-form" action="<?php echo BASE_URL . 'admin/payments/review.php'; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="order_context" value="<?php echo !empty($reviewInOrder) ? '1' : '0'; ?>"><input type="hidden" name="id" value="<?php echo $escape($payment['id']); ?>">
        <input type="hidden" name="action" value="<?php echo $escape($action); ?>">

        <?php if ($action === 'reject'): ?>
          <label for="review-reason">Rejection reason</label>
          <textarea id="review-reason" name="reason" maxlength="500" rows="4" required aria-describedby="review-reason-help<?php echo $reasonError !== null ? ' review-reason-error' : ''; ?>"<?php echo $reasonError !== null ? ' aria-invalid="true"' : ''; ?>><?php echo $escape($reviewForm['values']['reason'] ?? ''); ?></textarea>
          <p id="review-reason-help">Recorded permanently in the payment audit trail. Do not include payment credentials or authentication data.</p>
          <?php if ($reasonError !== null): ?><p class="admin-field-error" id="review-reason-error"><?php echo $escape($reasonError); ?></p><?php endif; ?>
          <button class="admin-button" type="submit">Reject payment</button>
        <?php else: ?>
          <p>Verifying records your administrator identity and the current time against this payment permanently. This cannot be undone.</p>
          <button class="admin-button" type="submit"><?php echo AdminIcons::render('check'); ?>Verify payment</button>
        <?php endif; ?>
      </form>
    </section>
  </div>
<?php endif; ?>
