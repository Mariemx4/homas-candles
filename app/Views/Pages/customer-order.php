<?php

declare(strict_types=1);

use App\View\Presentation;

$escape = Presentation::escape(...);
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page container">
  <a class="account-back" href="<?php echo BASE_URL . 'account.php#orders'; ?>"><?php echo site_text('account.back'); ?></a>
  <?php if ($order === null): ?>
    <header class="account-page__header">
      <h1><?php echo site_text('account.not_found'); ?></h1>
    </header>
  <?php else: ?>
    <header class="account-page__header">
      <p><?php echo site_text('account.order'); ?></p>
      <h1><?php echo $escape($order['order_number']); ?></h1>
      <p><a href="<?php echo $escape(BASE_URL . 'order-payment.php?' . http_build_query(['order_id' => $order['id']])); ?>">Choose payment method / View payment summary</a></p>
      <p aria-live="polite"><?php echo site_text('account.status'); ?>: <strong data-order-status><?php echo site_text('account.status_' . strtolower((string) $order['order_status'])); ?></strong></p>
    </header>
    <?php if ($orderFlash['success'] || $orderFlash['errors']): ?><p class="account-card" role="status"><?php echo $escape($orderFlash['success'] ?? implode(' ', $orderFlash['errors'])); ?></p><?php endif; ?>
    <section class="account-card" data-order-tracking data-url="<?php echo $escape(BASE_URL . 'account-order.php?id=' . (int)$order['id'] . '&format=json'); ?>" data-version="<?php echo $escape($version); ?>">
      <h2><?php echo site_text('account.tracking'); ?></h2><p><?php echo site_text('account.tracking_help'); ?></p>
      <ol class="account-timeline" data-order-history><?php foreach ($history as $event): ?><li><strong><?php echo site_text('account.status_' . strtolower($event['new_status'])); ?></strong> <time><?php echo $escape($event['changed_at']); ?></time></li><?php endforeach; ?></ol>
      <p data-tracking-error hidden role="status"><?php echo site_text('account.tracking_error'); ?></p>
      <p data-order-changed hidden role="status"><?php echo site_text('account.order_changed'); ?> <a href="<?php echo $escape(BASE_URL . 'account-order.php?id=' . (int)$order['id']); ?>"><?php echo site_text('account.refresh'); ?></a></p>
    </section>
    <?php if ($canEdit): ?>
      <section class="account-card" data-order-actions><h2><?php echo site_text('account.manage_order'); ?></h2><p><?php echo site_text('account.edit_rules'); ?></p>
        <a class="account-button" href="<?php echo $escape(BASE_URL . 'account-order-edit.php?id=' . (int)$order['id']); ?>"><?php echo site_text('account.edit_order'); ?></a>
        <details class="account-cancel"><summary><?php echo site_text('account.cancel_order'); ?></summary><p><?php echo site_text('account.cancel_help'); ?></p><form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="version" value="<?php echo $escape($version); ?>"><button class="account-button account-button--secondary" name="action" value="cancel"><?php echo site_text('account.confirm_cancel'); ?></button>
        </form></details>
      </section>
    <?php else: ?><p><?php echo site_text('account.edit_closed'); ?></p><?php endif; ?>
    <section class="account-card" aria-labelledby="order-details-title">
      <h2 id="order-details-title"><?php echo site_text('account.details'); ?></h2>
      <dl class="account-details">
        <div>
          <dt><?php echo site_text('account.placed'); ?></dt>
          <dd><?php echo $escape($order['created_at']); ?></dd>
        </div>
        <div>
          <dt><?php echo site_text('account.required'); ?></dt>
          <dd><?php echo $escape($order['required_date']); ?></dd>
        </div>
        <div>
          <dt><?php echo site_text('checkout.name'); ?></dt>
          <dd><?php echo $escape($order['customer_name_snapshot']); ?></dd>
        </div>
        <?php if ($order['customer_email_snapshot'] !== null): ?><div>
            <dt><?php echo site_text('footer.email'); ?></dt>
            <dd><?php echo $escape($order['customer_email_snapshot']); ?></dd>
          </div><?php endif; ?>
        <div>
          <dt><?php echo site_text('footer.phone'); ?></dt>
          <dd><?php echo $escape($order['customer_phone_snapshot']); ?></dd>
        </div>
        <div>
          <dt><?php echo site_text('account.delivery'); ?></dt>
          <dd><?php echo site_text($order['delivery_method'] === 'PICKUP' ? 'checkout.pickup' : 'checkout.home_delivery'); ?></dd>
        </div>
        <?php if ($order['delivery_method'] === 'DELIVERY'): ?>
          <div>
            <dt><?php echo site_text('account.address'); ?></dt>
            <dd><?php echo $escape($order['street_address_snapshot'] . ', ' . $order['delegation_snapshot'] . ', ' . $order['governorate_snapshot'] . ' ' . $order['postal_code_snapshot']); ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>
    <section class="account-card" aria-labelledby="order-items-title">
      <h2 id="order-items-title"><?php echo site_text('account.items'); ?></h2>
      <div class="account-table-wrap">
        <table class="account-orders">
          <thead>
            <tr>
              <th><?php echo site_text('account.item'); ?></th>
              <th><?php echo site_text('account.quantity'); ?></th>
              <th><?php echo site_text('account.total'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?php echo $escape($item['product_name_snapshot']); ?><?php if ($item['color_name_snapshot']): ?> · <?php echo $escape($item['color_name_snapshot']); ?><?php endif; ?><?php if ($item['scent_name_snapshot']): ?> · <?php echo $escape($item['scent_name_snapshot']); ?><?php endif; ?></td>
                <td><?php echo (int) $item['quantity']; ?></td>
                <td><?php echo $escape($item['line_total_snapshot']); ?> DT</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <dl class="account-totals">
        <div>
          <dt><?php echo site_text('account.subtotal'); ?></dt>
          <dd><?php echo $escape($order['subtotal']); ?> DT</dd>
        </div>
        <?php if ($order['promo_code_snapshot'] ?? null): ?><div>
            <dt><?php echo $escape($order['promo_code_snapshot']); ?></dt>
            <dd>-<?php echo $escape($order['promo_discount_amount']); ?> DT</dd>
          </div><?php endif; ?>
        <?php if ($order['manual_discount_type'] ?? null): ?><div>
            <dt>Discount</dt>
            <dd>-<?php echo $escape($order['manual_discount_amount']); ?> DT</dd>
          </div><?php endif; ?>
        <div>
          <dt><?php echo site_text('account.delivery_fee'); ?></dt>
          <dd><?php echo $escape($order['delivery_fee']); ?> DT</dd>
        </div>
        <div>
          <dt><?php echo site_text('account.total'); ?></dt>
          <dd><span data-order-figure="total"><?php echo $escape($order['total_amount']); ?></span> DT</dd>
        </div>
        <div>
            <dt><?php echo site_text('account.deposit'); ?></dt>
            <dd><span data-order-figure="deposit"><?php echo $escape($order['required_deposit_amount']); ?></span> DT</dd>
          </div>
        <?php foreach ($paymentFigures as $key => $amount): ?><div><dt><?php echo site_text('account.' . $key); ?></dt><dd><span data-order-figure="<?php echo $key; ?>"><?php echo $escape($amount); ?></span> DT</dd></div><?php endforeach; ?>
      </dl>
      <p><?php echo site_text('account.payment_edit_help'); ?></p>
    </section>
  <?php endif; ?>
</main>
<?php if ($order !== null): ?><script src="<?php echo $escape(BASE_URL . 'assets/js/customer-order.js'); ?>" defer></script><?php endif; ?>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
