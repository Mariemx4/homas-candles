<?php declare(strict_types=1); $order = $orderDetail['order']; ?>
<header class="admin-page-header"><div><h1>Delete order</h1></div></header>
<section class="admin-panel admin-detail-card">
  <header class="admin-panel__header"><h2><?php echo $escape($order['order_number']); ?></h2></header>
  <form class="admin-status-form" method="post" action="<?php echo BASE_URL . 'admin/orders/delete.php'; ?>">
    <p>Move this order for <?php echo $escape($order['customer_name_snapshot']); ?> to Trash? It will leave the active orders and production lists. Its payments, stock records, and history will be kept, and you can restore it.</p>
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
    <input type="hidden" name="id" value="<?php echo $escape($order['id']); ?>">
    <input type="hidden" name="version" value="<?php echo $escape(\App\Services\OrderEditService::version($order)); ?>">
    <div class="admin-status-form__actions">
      <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?id=' . $order['id']); ?>">Cancel</a>
      <button class="admin-button" type="submit">Move to Trash</button>
    </div>
  </form>
</section>
