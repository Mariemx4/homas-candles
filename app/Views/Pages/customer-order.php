<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page container">
  <a class="account-back" href="<?php echo BASE_URL . 'account.php#orders'; ?>"><?php echo site_text('account.back'); ?></a>
  <?php if ($order === null): ?>
    <header class="account-page__header"><h1><?php echo site_text('account.not_found'); ?></h1></header>
  <?php else: ?>
    <header class="account-page__header">
      <p><?php echo site_text('account.order'); ?></p>
      <h1><?php echo $escape($order['order_number']); ?></h1>
      <p><?php echo site_text('account.status'); ?>: <?php echo site_text('account.status_' . strtolower((string) $order['order_status'])); ?></p>
    </header>
    <section class="account-card" aria-labelledby="order-details-title">
      <h2 id="order-details-title"><?php echo site_text('account.details'); ?></h2>
      <dl class="account-details">
        <div><dt><?php echo site_text('account.placed'); ?></dt><dd><?php echo $escape($order['created_at']); ?></dd></div>
        <div><dt><?php echo site_text('account.required'); ?></dt><dd><?php echo $escape($order['required_date']); ?></dd></div>
        <div><dt><?php echo site_text('checkout.name'); ?></dt><dd><?php echo $escape($order['customer_name_snapshot']); ?></dd></div>
        <?php if ($order['customer_email_snapshot'] !== null): ?><div><dt><?php echo site_text('footer.email'); ?></dt><dd><?php echo $escape($order['customer_email_snapshot']); ?></dd></div><?php endif; ?>
        <div><dt><?php echo site_text('footer.phone'); ?></dt><dd><?php echo $escape($order['customer_phone_snapshot']); ?></dd></div>
        <div><dt><?php echo site_text('account.delivery'); ?></dt><dd><?php echo site_text($order['delivery_method'] === 'PICKUP' ? 'checkout.pickup' : 'checkout.home_delivery'); ?></dd></div>
        <?php if ($order['delivery_method'] === 'DELIVERY'): ?>
          <div><dt><?php echo site_text('account.address'); ?></dt><dd><?php echo $escape($order['street_address_snapshot'] . ', ' . $order['delegation_snapshot'] . ', ' . $order['governorate_snapshot'] . ' ' . $order['postal_code_snapshot']); ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>
    <section class="account-card" aria-labelledby="order-items-title">
      <h2 id="order-items-title"><?php echo site_text('account.items'); ?></h2>
      <div class="account-table-wrap"><table class="account-orders">
        <thead><tr><th><?php echo site_text('account.item'); ?></th><th><?php echo site_text('account.quantity'); ?></th><th><?php echo site_text('account.total'); ?></th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr><td><?php echo $escape($item['product_name_snapshot']); ?><?php if ($item['color_name_snapshot']): ?> · <?php echo $escape($item['color_name_snapshot']); ?><?php endif; ?><?php if ($item['scent_name_snapshot']): ?> · <?php echo $escape($item['scent_name_snapshot']); ?><?php endif; ?></td><td><?php echo (int) $item['quantity']; ?></td><td><?php echo $escape($item['line_total_snapshot']); ?> DT</td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <dl class="account-totals">
        <div><dt><?php echo site_text('account.subtotal'); ?></dt><dd><?php echo $escape($order['subtotal']); ?> DT</dd></div>
        <div><dt><?php echo site_text('account.delivery_fee'); ?></dt><dd><?php echo $escape($order['delivery_fee']); ?> DT</dd></div>
        <div><dt><?php echo site_text('account.total'); ?></dt><dd><?php echo $escape($order['total_amount']); ?> DT</dd></div>
        <?php if ((float) $order['required_deposit_amount'] > 0): ?><div><dt><?php echo site_text('account.deposit'); ?></dt><dd><?php echo $escape($order['required_deposit_amount']); ?> DT</dd></div><?php endif; ?>
      </dl>
    </section>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../Layouts/footer.php'; ?>
