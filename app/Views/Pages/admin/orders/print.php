<?php

declare(strict_types=1);

use App\View\Presentation;

/**
 * @var array $orderDetail
 * @var callable $escape
 */

$formatMoney = Presentation::money(...);
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    try {
        return \App\Services\BusinessClock::displayTimestamp($value, 'd M Y');
    } catch (Throwable) {
        return 'Not set';
    }
};
$humanize = Presentation::humanize(...);
?>
<main class="admin-print-page">
  <?php if (!is_array($orderDetail['order'])): ?>
    <section class="admin-print-error">
      <h1>Order summary unavailable</h1>
      <p><?php echo $escape($orderDetail['loadError']); ?></p><a href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Back to orders</a>
    </section>
  <?php else: ?>
    <?php $order = $orderDetail['order']; ?>
    <header class="admin-print-header">
      <div><img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>" alt="Hom'As Candles"><span>Preparation summary</span></div>
      <button class="admin-print-button" type="button">Print</button>
    </header>
    <section class="admin-print-title">
      <div>
        <p>Order</p>
        <h1><?php echo $escape($order['order_number']); ?></h1>
      </div>
      <dl>
        <div>
          <dt>Status</dt>
          <dd><?php echo $escape($humanize($order['order_status'])); ?></dd>
        </div>
        <div>
          <dt>Required</dt>
          <dd><?php echo $escape($formatDate($order['required_date'])); ?></dd>
        </div>
        <div>
          <dt>Fulfilment</dt>
          <dd><?php echo $escape($humanize($order['delivery_method'])); ?></dd>
        </div>
      </dl>
    </section>
    <section class="admin-print-section">
      <h2>Customer</h2>
      <p><?php echo $escape($order['customer_name_snapshot']); ?></p>
    </section>
    <section class="admin-print-section">
      <h2>Items to prepare</h2>
      <?php if ($orderDetail['items'] === []): ?><p>No item rows are recorded.</p><?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Item snapshot</th>
              <th>Options</th>
              <th>Qty</th>
              <th>Customization</th>
              <th>Line total</th>
            </tr>
          </thead>
          <tbody>
            <?php // Array_filter removes empty option labels.
            ?>
            <?php foreach ($orderDetail['items'] as $item): ?><tr>
                <td><?php echo $escape($item['product_name_snapshot']); ?></td>
                <td><?php echo $escape(implode(' · ', array_filter([$item['color_name_snapshot'], $item['scent_name_snapshot']])) ?: '—'); ?></td>
                <td><?php echo $escape($item['quantity']); ?></td>
                <td><?php echo $escape($item['customization_notes'] ?: '—'); ?></td>
                <td><?php echo $escape($formatMoney($item['line_total_snapshot'])); ?></td>
              </tr><?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
    <section class="admin-print-totals">
      <dl>
        <div>
          <dt>Subtotal</dt>
          <dd><?php echo $escape($formatMoney($order['subtotal'])); ?></dd>
        </div>
        <?php if ($order['promo_code_snapshot'] ?? null): ?><div><dt>Promo <?php echo $escape($order['promo_code_snapshot']); ?></dt><dd>-<?php echo $escape($formatMoney($order['promo_discount_amount'])); ?></dd></div><?php endif; ?>
        <?php if ($order['manual_discount_type'] ?? null): ?><div><dt>Manual discount</dt><dd>-<?php echo $escape($formatMoney($order['manual_discount_amount'])); ?></dd></div><?php endif; ?>
        <div>
          <dt>Delivery</dt>
          <dd><?php echo $escape($formatMoney($order['delivery_fee'])); ?></dd>
        </div>
        <div>
          <dt>Total</dt>
          <dd><?php echo $escape($formatMoney($order['total_amount'])); ?></dd>
        </div>
        <div>
          <dt>Verified paid</dt>
          <dd><?php echo $escape($formatMoney($order['amount_paid'])); ?></dd>
        </div>
        <div>
          <dt>Balance</dt>
          <dd><?php echo $escape($formatMoney($order['remaining_balance'])); ?></dd>
        </div>
      </dl>
    </section>
    <footer class="admin-print-footer">Internal preparation copy · Contact details, private notes, payment evidence, and authentication data intentionally omitted.</footer>
  <?php endif; ?>
</main>