<?php

declare(strict_types=1);

use App\Services\PaymentService;

?>
<form class="admin-status-form admin-payment-form" data-payment-record-form action="<?php echo BASE_URL . 'admin/payments/order.php'; ?>" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
  <input type="hidden" name="order_context" value="<?php echo !empty($recordInOrder) ? '1' : '0'; ?>">
  <input type="hidden" name="order_id" value="<?php echo $escape($paymentRecordOrder['id']); ?>">

  <label for="payment-method">Method</label>
  <select id="payment-method" name="payment_method">
    <?php foreach (PaymentService::METHODS as $methodOption): ?>
      <option value="<?php echo $escape($methodOption); ?>" <?php echo ($values['payment_method'] ?? 'CASH_ON_DELIVERY') === $methodOption ? ' selected' : ''; ?>><?php echo $escape($methodOption === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $methodOption); ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (isset($errors['payment_method'])): ?><p class="admin-field-error"><?php echo $escape($errors['payment_method']); ?></p><?php endif; ?>

  <label for="payment-type">Type</label>
  <select id="payment-type" name="payment_type">
    <?php foreach (PaymentService::TYPES as $typeOption): ?>
      <option value="<?php echo $escape($typeOption); ?>" <?php echo ($values['payment_type'] ?? '') === $typeOption ? ' selected' : ''; ?>><?php echo $escape($humanize($typeOption)); ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (isset($errors['payment_type'])): ?><p class="admin-field-error"><?php echo $escape($errors['payment_type']); ?></p><?php endif; ?>

  <label for="payment-amount">Amount (TND)</label>
  <input id="payment-amount" name="amount" type="text" inputmode="decimal" maxlength="13" required value="<?php echo $escape($values['amount'] ?? $paymentRecordOrder['recordable_amount']); ?>" aria-describedby="payment-amount-help" placeholder="0.000">
  <p id="payment-amount-help">Remaining balance: <?php echo $escape($formatMoney($paymentRecordOrder['remaining_balance'])); ?>. Available to record after pending payments: <?php echo $escape($formatMoney($paymentRecordOrder['recordable_amount'])); ?>. Enter the amount actually received.</p>
  <?php if (isset($errors['amount'])): ?><p class="admin-field-error"><?php echo $escape($errors['amount']); ?></p><?php endif; ?>

  <div class="admin-payment-d17-fields" data-payment-d17-fields>
    <label for="payment-reference">D17 reference <span>(D17 only, optional)</span></label>
    <input id="payment-reference" name="d17_reference" type="text" maxlength="100" value="<?php echo $escape($values['d17_reference'] ?? ''); ?>">
    <?php if (isset($errors['d17_reference'])): ?><p class="admin-field-error"><?php echo $escape($errors['d17_reference']); ?></p><?php endif; ?>

    <label for="payment-declared-at">Customer-declared transfer time <span>(Tunisia time; D17 only, optional)</span></label>
    <input id="payment-declared-at" name="customer_declared_paid_at" type="datetime-local" value="<?php echo $escape($values['customer_declared_paid_at'] ?? ''); ?>">
    <?php if (isset($errors['customer_declared_paid_at'])): ?><p class="admin-field-error"><?php echo $escape($errors['customer_declared_paid_at']); ?></p><?php endif; ?>

    <label for="payment-evidence">Evidence file <span>(D17 only, optional, JPEG/PNG/WEBP/PDF, max 5&nbsp;MB)</span></label>
    <input id="payment-evidence" name="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
    <?php if (isset($errors['evidence'])): ?><p class="admin-field-error"><?php echo $escape($errors['evidence']); ?></p><?php endif; ?>
  </div>
  <label class="admin-payment-confirmation" data-payment-cash-confirmation><input type="checkbox" name="received_confirmation" value="1"> I confirm that the entered amount was actually received on delivery or pickup.</label>
  <p>Confirming a receipt stores your administrator identity and the current time. A D17 record stays pending until it is reviewed. Changing order status never confirms payment.</p>

  <button class="admin-button" data-payment-record-button type="submit">Mark payment as received</button>
</form>