<?php
declare(strict_types=1);

use App\View\Presentation;

$escape = Presentation::escape(...);
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page container">
  <?php if ($customerPayment['detail'] === null): ?>
    <header class="account-page__header"><h1><?php echo $customerPayment['httpStatus'] === 503 ? 'Payment details unavailable' : 'Order not found'; ?></h1></header>
    <p>Please return to your order or try again later.</p>
  <?php else: ?>
    <?php $detail = $customerPayment['detail'];
      $order = $detail['order'];
      $d17 = $detail['d17']; ?>
    <header class="account-page__header"><p>HOM’AS Candles</p><h1>Payment for <?php echo $escape($order['order_number']); ?></h1><p>Payment status: <?php echo $escape($order['payment_status_label']); ?></p></header>
    <?php if ($paymentNotice['success'] !== null): ?><p role="status"><?php echo $escape($paymentNotice['success']); ?></p><?php endif; ?>
    <?php foreach ($paymentNotice['errors'] as $error): ?><p role="alert"><?php echo $escape($error); ?></p><?php endforeach; ?>
    <section class="account-card" aria-labelledby="customer-payment-summary">
      <h2 id="customer-payment-summary">Payment summary</h2>
      <dl class="account-totals">
        <div><dt>Order total</dt><dd><?php echo $escape($order['total_amount']); ?> TND</dd></div>
        <div><dt>Required deposit</dt><dd><?php echo $escape($order['required_deposit_amount']); ?> TND</dd></div>
        <div><dt>Verified amount</dt><dd><?php echo $escape($order['verified_amount']); ?> TND</dd></div>
        <div><dt>Pending verification</dt><dd><?php echo $escape($order['pending_amount']); ?> TND</dd></div>
        <div><dt>Remaining amount to pay</dt><dd><?php echo $escape($order['remaining_balance']); ?> TND</dd></div>
        <?php if ($order['credit_balance'] !== '0.000'): ?><div><dt>Credit / refund due</dt><dd><?php echo $escape($order['credit_balance']); ?> TND</dd></div><?php endif; ?>
      </dl>
      <p>Only verified payments reduce your remaining balance. Preparation, delivery and pickup do not confirm payment.</p>
    </section>
    <section class="account-card" aria-labelledby="customer-payment-method">
      <h2 id="customer-payment-method">Choose how to pay</h2>
      <nav class="customer-payment-methods" aria-label="Payment method">
        <a href="<?php echo $escape($paymentUrl); ?>"<?php echo $paymentMethod === 'CASH_ON_DELIVERY' ? ' aria-current="page"' : ''; ?>>Pay on delivery / pickup</a>
        <a href="<?php echo $escape($paymentUrl . '&method=D17'); ?>"<?php echo $paymentMethod === 'D17' ? ' aria-current="page"' : ''; ?>>Pay manually using D17</a>
      </nav>
      <?php if ($order['order_status'] === 'CANCELLED'): ?>
        <p>This order is cancelled. No further payment is requested. Contact HOM’AS to review recorded payments and any refund.</p>
      <?php elseif ($paymentMethod === 'CASH_ON_DELIVERY'): ?>
        <p><?php echo $order['delivery_method'] === 'PICKUP' ? 'Pay when you collect your order.' : 'Pay when your order is handed to you.'; ?> HOM’AS will confirm receipt separately.</p>
        <?php if ($order['required_deposit_amount'] !== '0.000'): ?><p>This order requires a deposit before confirmation. Contact HOM’AS to arrange it, or use D17. The existing deposit requirement still applies.</p><?php endif; ?>
      <?php elseif (!($d17['available'] ?? false)): ?>
        <p>D17 payment details are currently unavailable. Contact HOM’AS before making a transfer.</p>
      <?php else: ?>
        <h3>HOM’AS D17 Payment</h3>
        <dl class="account-details">
          <div><dt>Account holder</dt><dd><?php echo $escape($d17['account_holder']); ?></dd></div>
          <div><dt>D17 number</dt><dd><?php echo $escape($d17['number']); ?></dd></div>
          <?php if ($d17['account_information'] !== ''): ?><div><dt>Card / account information</dt><dd><?php echo $escape($d17['account_information']); ?></dd></div><?php endif; ?>
          <div><dt>Order</dt><dd><?php echo $escape($order['order_number']); ?></dd></div>
          <div><dt>Amount to pay now</dt><dd><?php echo $escape($order['next_d17_amount']); ?> TND</dd></div>
        </dl>
        <?php if ($order['pending_amount'] !== '0.000'): ?>
          <p>Your transfer is awaiting manual verification. Do not submit the same transfer again.</p>
        <?php elseif ($order['next_d17_amount'] === '0.000'): ?>
          <p>No further payment is due.</p>
        <?php else: ?>
          <ol><li>Open D17.</li><li>Transfer the displayed amount to the configured HOM’AS recipient.</li><li>Take a screenshot or photo of the successful transfer.</li><li>Upload the payment proof below.</li><li>Submit it for manual verification.</li></ol>
          <p>D17 is a manual transfer. This website does not confirm transfers automatically.</p>
          <form class="customer-payment-proof" action="<?php echo $escape($paymentUrl . '&method=D17'); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="order_id" value="<?php echo $escape($order['id']); ?>">
            <label for="customer-payment-proof">Payment proof (JPEG, PNG, WEBP or existing supported PDF; maximum 5 MB)</label>
            <input id="customer-payment-proof" type="file" name="evidence" accept="image/jpeg,image/png,image/webp,application/pdf" required>
            <button type="submit">Submit proof for verification</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="account-card" aria-labelledby="customer-payment-history">
      <h2 id="customer-payment-history">Payment history</h2>
      <?php if ($detail['payments'] === []): ?><p>No payments recorded yet.</p><?php endif; ?>
      <?php foreach ($detail['payments'] as $payment): ?>
        <p><?php echo $escape($payment['amount']); ?> TND · <?php echo $escape($payment['payment_method'] === 'D17' ? 'D17' : 'Pay on delivery / pickup'); ?> · <?php echo $escape(ucwords(strtolower(str_replace('_', ' ', $payment['payment_status'])))); ?></p>
        <?php if ($payment['rejection_reason']): ?><p>Rejection reason: <?php echo $escape($payment['rejection_reason']); ?>. You may submit new proof for a new transfer or corrected evidence.</p><?php endif; ?>
        <?php if ((bool) $payment['has_evidence']): ?><p><a href="<?php echo $escape($paymentUrl . '&evidence=' . $payment['id']); ?>" target="_blank" rel="noopener">View your payment proof</a></p><?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
