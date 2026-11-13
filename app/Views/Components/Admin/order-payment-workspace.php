<?php

declare(strict_types=1); ?>
<?php if (is_array($orderPayment ?? null) && is_array($orderPayment['detail'])): ?>
  <section class="admin-panel">
    <h2>Record a payment received</h2>
    <p class="admin-panel__intro">Keep the money received separate from the delivery or pickup status.</p>
    <?php if (is_string($orderPaymentNotice['success'] ?? null)): $alertTone = 'success';
        $alertTitle = 'Payment updated';
        $alertMessage = $orderPaymentNotice['success'];
        require VIEW_PATH . '/Components/Admin/alert.php';
    endif; ?>
    <?php $paymentRecordOrder = $orderPayment['detail']['order'];
    $values = $orderPaymentForm['values'];
    $errors = $orderPaymentForm['errors'];
    $recordInOrder = true; ?>
    <?php if ($errors !== []): $alertTone = 'error';
        $alertTitle = 'Payment not recorded';
        $alertMessage = implode(' ', $errors);
        require VIEW_PATH . '/Components/Admin/alert.php';
    endif; ?>
    <?php if ($paymentRecordOrder['deleted_at'] === null && $paymentRecordOrder['recordable_amount'] !== '0.000'): require VIEW_PATH . '/Components/Admin/payment-record-form.php';
    else: ?><p>No new payment is available to record. This order is settled, closed or its remaining amount is awaiting review.</p><?php endif; ?>
  </section>
  <?php foreach ($orderDetail['payments'] as $payment): if ($payment['payment_status'] !== 'PENDING_VERIFICATION') {
      continue;
  } ?>
    <section class="admin-task-panel admin-task-panel--blocked">
      <div>
        <h2>Transfer awaiting review</h2>
        <p><?php echo $escape($payment['amount']); ?> TND · Review the transfer before counting it as paid.</p>
      </div><a class="admin-button" href="<?php echo $escape(BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $payment['id'], 'action' => 'verify', 'order_context' => '1'])); ?>">Review D17 transfer</a>
    </section>
  <?php endforeach; ?>
<?php else: ?><section class="admin-panel">
    <h2>Payment details unavailable</h2>
    <p>Please reload the order before recording a payment.</p>
  </section><?php endif; ?>