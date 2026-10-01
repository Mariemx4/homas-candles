<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page container">
  <header class="account-page__header">
    <p><?php echo site_text('nav.your_space'); ?></p>
    <h1><?php echo site_text('account.title'); ?></h1>
    <p><?php echo site_text('account.intro'); ?></p>
  </header>

  <section class="account-card" aria-labelledby="account-details-title">
    <h2 id="account-details-title"><?php echo site_text('account.details'); ?></h2>
    <dl class="account-details">
      <div><dt><?php echo site_text('checkout.name'); ?></dt><dd><?php echo $escape($account['first_name'] . ' ' . $account['last_name']); ?></dd></div>
      <div><dt><?php echo site_text('footer.email'); ?></dt><dd><?php echo $escape($account['email']); ?></dd></div>
      <div><dt><?php echo site_text('footer.phone'); ?></dt><dd><?php echo $escape($account['phone_number']); ?></dd></div>
    </dl>
  </section>

  <section class="account-card" aria-labelledby="account-addresses-title">
    <h2 id="account-addresses-title"><?php echo site_text('account.addresses'); ?></h2>
    <?php if ($addresses === []): ?>
      <p><?php echo site_text('account.no_addresses'); ?></p>
    <?php else: ?>
      <ul class="account-addresses">
        <?php foreach ($addresses as $address): ?>
          <li>
            <strong><?php echo $escape($address['address_label'] ?: site_text('account.address')); ?></strong>
            <?php if ((int) $address['is_primary'] === 1): ?><span><?php echo site_text('account.primary'); ?></span><?php endif; ?>
            <p><?php echo $escape($address['street_address'] . ', ' . $address['delegation'] . ', ' . $address['governorate'] . ' ' . $address['postal_code']); ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="account-card" id="orders" aria-labelledby="account-orders-title">
    <h2 id="account-orders-title"><?php echo site_text('account.orders'); ?> <small>(<?php echo $totalOrders; ?>)</small></h2>
    <?php if ($orders === []): ?>
      <p><?php echo site_text('account.no_orders'); ?></p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('nav.shop'); ?></a>
    <?php else: ?>
      <div class="account-table-wrap">
        <table class="account-orders">
          <thead><tr><th><?php echo site_text('account.order'); ?></th><th><?php echo site_text('account.placed'); ?></th><th><?php echo site_text('account.required'); ?></th><th><?php echo site_text('account.status'); ?></th><th><?php echo site_text('account.total'); ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($orders as $listed): ?>
            <tr>
              <td><?php echo $escape($listed['order_number']); ?></td>
              <td><?php echo $escape(substr((string) $listed['created_at'], 0, 10)); ?></td>
              <td><?php echo $escape($listed['required_date']); ?></td>
              <td><?php echo site_text('account.status_' . strtolower((string) $listed['order_status'])); ?></td>
              <td><?php echo $escape($listed['total_amount']); ?> DT</td>
              <td><a href="<?php echo BASE_URL . 'account-order.php?id=' . (int) $listed['id']; ?>"><?php echo site_text('account.view'); ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
        <nav class="account-pagination" aria-label="<?php echo site_text('account.orders'); ?>">
          <?php if ($page > 1): ?><a href="<?php echo BASE_URL . 'account.php?page=' . ($page - 1) . '#orders'; ?>"><?php echo site_text('account.previous'); ?></a><?php endif; ?>
          <span><?php echo $page . ' / ' . $pages; ?></span>
          <?php if ($page < $pages): ?><a href="<?php echo BASE_URL . 'account.php?page=' . ($page + 1) . '#orders'; ?>"><?php echo site_text('account.next'); ?></a><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<?php require __DIR__ . '/../Layouts/footer.php'; ?>
