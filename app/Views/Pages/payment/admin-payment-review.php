<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Payment Review; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$formatMoney = static fn (mixed $amount): string => number_format((float) $amount, 3) . ' TND';
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not provided';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y, H:i');
    } catch (Throwable) {
        return 'Not provided';
    }
};
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$reviewForm ??= ['errors' => [], 'values' => [], 'success' => null];
$generalError = $reviewForm['errors']['form'] ?? null;
$reasonError = $reviewForm['errors']['reason'] ?? null;
$payment = $paymentReview['payment'];
$action = $paymentReview['action'] === 'reject' ? 'reject' : 'verify';
?>
<?php if (!is_array($payment)): ?>
  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>Review unavailable</h1>
      <p><?php echo $escape($paymentReview['loadError'] ?? 'The requested payment could not be loaded.'); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/payments/index.php'; ?>">Back to payments</a>
    </div>
  </section>
<?php elseif (!$payment['reviewable']): ?>
  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>This payment cannot be reviewed</h1>
      <p>Only a pending D17 payment can be verified or rejected. This payment has already been reviewed or does not require review.</p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>">Back to order payments</a>
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
      require __DIR__ . '/../../Components/Admin/alert.php';
    ?>
  <?php endif; ?>

  <div class="admin-transition-confirmation">
    <!-- SECTION PURPOSE: Group the Payment details content, states, and controls as one accessible page region. -->
    <section class="admin-panel admin-transition-confirmation__summary" aria-labelledby="payment-summary-title">
      <header class="admin-panel__header"><h2 id="payment-summary-title">Payment details</h2></header>
      <dl class="admin-definition-list">
        <div><dt>Type</dt><dd><?php echo $escape($humanize($payment['payment_type'])); ?></dd></div>
        <div><dt>Amount</dt><dd><?php echo $escape($formatMoney($payment['amount'])); ?></dd></div>
        <div><dt>D17 reference</dt><dd><?php echo $escape($payment['d17_reference'] ?: 'Not provided'); ?></dd></div>
        <div><dt>Customer-declared time</dt><dd><?php echo $escape($formatDate($payment['customer_declared_paid_at'])); ?></dd></div>
        <div><dt>Recorded</dt><dd><time datetime="<?php echo $escape($payment['created_at']); ?>"><?php echo $escape($formatDate($payment['created_at'])); ?></time></dd></div>
        <div>
          <dt>Evidence</dt>
          <dd>
            <?php if ((bool) $payment['has_evidence']): ?>
              <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
              <a href="<?php echo $escape(BASE_URL . 'admin/payments/evidence.php?' . http_build_query(['id' => $payment['id']])); ?>" target="_blank" rel="noopener">View uploaded evidence</a>
            <?php else: ?>
              No evidence uploaded
            <?php endif; ?>
          </dd>
        </div>
      </dl>
    </section>

    <!-- SECTION PURPOSE: Group the Administrator decision content, states, and controls as one accessible page region. -->
    <section class="admin-panel admin-transition-confirmation__form" aria-labelledby="payment-decision-title">
      <header class="admin-panel__header"><h2 id="payment-decision-title">Administrator decision</h2></header>
      <div class="admin-payment-decision-tabs" role="tablist" aria-label="Decision type">
        <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
        <a role="tab" aria-selected="<?php echo $action === 'verify' ? 'true' : 'false'; ?>" class="admin-button<?php echo $action === 'verify' ? '' : ' admin-button--secondary'; ?>" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify'])); ?>">Verify</a>
        <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
        <a role="tab" aria-selected="<?php echo $action === 'reject' ? 'true' : 'false'; ?>" class="admin-button<?php echo $action === 'reject' ? '' : ' admin-button--secondary'; ?>" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'reject'])); ?>">Reject</a>
      </div>

      <!-- FORM PURPOSE: Submit status form with POST; the receiving PHP route must validate the values before using them. -->
      <form class="admin-status-form" action="<?php echo BASE_URL . 'admin/payments/review.php'; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="id" value="<?php echo $escape($payment['id']); ?>">
        <input type="hidden" name="action" value="<?php echo $escape($action); ?>">

        <?php if ($action === 'reject'): ?>
          <label for="review-reason">Rejection reason</label>
          <textarea id="review-reason" name="reason" maxlength="500" rows="4" required aria-describedby="review-reason-help<?php echo $reasonError !== null ? ' review-reason-error' : ''; ?>"<?php echo $reasonError !== null ? ' aria-invalid="true"' : ''; ?>><?php echo $escape($reviewForm['values']['reason'] ?? ''); ?></textarea>
          <p id="review-reason-help">Recorded permanently in the payment audit trail. Do not include payment credentials or authentication data.</p>
          <?php if ($reasonError !== null): ?><p class="admin-field-error" id="review-reason-error"><?php echo $escape($reasonError); ?></p><?php endif; ?>
          <button class="admin-button" type="submit">Reject payment</button>
        <?php else: ?>
          <p>Verifying records your administrator identity and the current time against this payment permanently. This cannot be undone.</p>
          <button class="admin-button" type="submit">Verify payment</button>
        <?php endif; ?>
      </form>
    </section>
  </div>
<?php endif; ?>
