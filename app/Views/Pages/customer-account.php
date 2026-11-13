<?php

declare(strict_types=1);

use App\View\CustomerAccountView;
use App\View\Presentation;

$escape = Presentation::escape(...);
$accountNavActive = 'overview';
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page account-space container">
  <div class="account-layout">
    <?php require VIEW_PATH . '/Components/customer-account-navigation.php'; ?>
    <div class="account-content">
      <header class="account-welcome">
        <div><p class="account-eyebrow">HOM’AS · <?php echo site_text('nav.your_space'); ?></p>
          <h1><?php echo site_text('account.greeting'); ?> <?php echo $escape($account['first_name']); ?>.</h1>
          <p><?php echo site_text('account.overview_intro'); ?></p>
        </div>
        <a class="account-button account-button--secondary" href="<?php echo $escape(BASE_URL . 'shop.php'); ?>"><?php echo site_text('account.shop_link'); ?><?php echo CustomerAccountView::icon('arrow'); ?></a>
      </header>
      <div class="account-stat-grid" aria-label="<?php echo site_text('account.overview'); ?>">
        <?php foreach ([['active', 'bag', 'account.active_orders'], ['completed', 'check', 'account.completed_orders']] as [$key, $icon, $label]): ?>
          <a class="account-stat" href="<?php echo $escape(BASE_URL . 'account.php?' . http_build_query(['filter' => $key]) . '#orders'); ?>">
            <span class="account-stat__icon"><?php echo CustomerAccountView::icon($icon); ?></span><span><strong><?php echo (int) $orderSummary[$key]; ?></strong><span><?php echo site_text($label); ?></span></span>
          </a>
        <?php endforeach; ?>
        <a class="account-stat" href="<?php echo $escape(BASE_URL . 'profile.php#addresses'); ?>">
          <span class="account-stat__icon"><?php echo CustomerAccountView::icon('pin'); ?></span><span><strong><?php echo count($addresses); ?></strong><span><?php echo site_text('account.addresses'); ?></span></span>
        </a>
      </div>
      <?php if ($nextOrder !== null): $nextState = CustomerAccountView::orderState($nextOrder); ?>
        <section class="account-spotlight" aria-labelledby="next-order-title">
          <div class="account-spotlight__top"><p class="account-eyebrow"><?php echo site_text('account.next_order'); ?></p><span class="account-status account-status--<?php echo $escape($nextState['tone']); ?>"><?php echo site_text('account.status_' . strtolower($nextOrder['order_status'])); ?></span></div>
          <h2 id="next-order-title"><?php echo $escape($nextOrder['order_number']); ?></h2>
          <p><?php echo site_text($nextState['help']); ?></p>
          <ol class="account-progress" aria-label="<?php echo site_text('account.tracking'); ?>">
            <?php foreach (['requested', 'confirmed', 'preparing', 'ready', 'complete'] as $step => $label): ?>
              <li<?php echo $step <= $nextState['stage'] ? ' class="is-reached"' : ''; ?><?php echo $step === $nextState['stage'] ? ' aria-current="step"' : ''; ?>>
                <span aria-hidden="true"><?php echo $step < $nextState['stage'] ? CustomerAccountView::icon('check') : ($step + 1); ?></span><span><?php echo site_text('account.step_' . $label); ?></span>
              </li>
            <?php endforeach; ?>
          </ol>
          <div class="account-spotlight__bottom"><span><?php echo CustomerAccountView::icon('clock'); ?><?php echo site_text('account.required'); ?>: <strong><?php echo $escape(CustomerAccountView::date($nextOrder['required_date'])); ?></strong></span>
            <a class="account-button" href="<?php echo $escape(BASE_URL . $nextState['href']); ?>"><?php echo site_text($nextState['action']); ?><?php echo CustomerAccountView::icon('arrow'); ?></a>
          </div>
        </section>
      <?php else: ?>
        <section class="account-spotlight account-spotlight--quiet" aria-labelledby="account-caught-up-title">
          <span class="account-empty-icon"><?php echo CustomerAccountView::icon('bag'); ?></span>
          <div><h2 id="account-caught-up-title"><?php echo site_text($orderSummary['total'] === 0 ? 'account.first_order_title' : 'account.caught_up'); ?></h2>
            <p><?php echo site_text($orderSummary['total'] === 0 ? 'account.first_order_intro' : 'account.no_active_orders'); ?></p>
            <a class="account-text-link" href="<?php echo $escape(BASE_URL . 'shop.php'); ?>"><?php echo site_text('account.shop_link'); ?><?php echo CustomerAccountView::icon('arrow'); ?></a>
          </div>
        </section>
      <?php endif; ?>
      <section class="account-card account-history" id="orders" aria-labelledby="account-orders-title">
        <header class="account-section-heading"><div><p class="account-eyebrow"><?php echo site_text('account.history'); ?></p><h2 id="account-orders-title"><?php echo site_text('account.orders'); ?></h2><p><?php echo site_text('account.orders_intro'); ?></p></div><span class="account-count"><?php echo (int) $orderSummary['total']; ?></span></header>
        <nav class="account-filters" aria-label="<?php echo site_text('account.filter_orders'); ?>">
          <?php foreach (['all', 'active', 'completed', 'cancelled'] as $option): ?>
            <a href="<?php echo $escape(BASE_URL . 'account.php?' . http_build_query(['filter' => $option]) . '#orders'); ?>"<?php echo $filter === $option ? ' aria-current="page"' : ''; ?>><?php echo site_text('account.filter_' . $option); ?><span><?php echo (int) $orderSummary[$option === 'all' ? 'total' : $option]; ?></span></a>
          <?php endforeach; ?>
        </nav>
        <?php if ($orders === []): ?>
          <div class="account-empty"><span class="account-empty-icon"><?php echo CustomerAccountView::icon('bag'); ?></span><h3><?php echo site_text($orderSummary['total'] === 0 ? 'account.no_orders' : 'account.filtered_empty'); ?></h3><p><?php echo site_text($orderSummary['total'] === 0 ? 'account.first_order_intro' : 'account.try_filter'); ?></p>
            <a class="account-text-link" href="<?php echo $escape(BASE_URL . ($orderSummary['total'] === 0 ? 'shop.php' : 'account.php#orders')); ?>"><?php echo site_text($orderSummary['total'] === 0 ? 'account.shop_link' : 'account.show_all'); ?><?php echo CustomerAccountView::icon('arrow'); ?></a>
          </div>
        <?php else: ?>
          <div class="account-order-list">
            <?php foreach ($orders as $listed): $state = CustomerAccountView::orderState($listed);
                $orderUrl = BASE_URL . 'account-order.php?id=' . (int) $listed['id']; ?>
              <article class="account-order-card" aria-labelledby="order-title-<?php echo (int) $listed['id']; ?>">
                <div class="account-order-card__heading"><div><a id="order-title-<?php echo (int) $listed['id']; ?>" class="account-order-number" href="<?php echo $escape($orderUrl); ?>"><?php echo $escape($listed['order_number']); ?></a><p><?php echo site_text('account.placed'); ?> <?php echo $escape(CustomerAccountView::date($listed['created_at'])); ?></p></div><span class="account-status account-status--<?php echo $escape($state['tone']); ?>"><?php echo site_text('account.status_' . strtolower($listed['order_status'])); ?></span></div>
                <div class="account-order-card__body"><span class="account-order-card__icon"><?php echo CustomerAccountView::icon('bag'); ?></span><div><strong><?php echo $escape($listed['first_item_name'] ?: site_text('account.made_for_you')); ?></strong><p><?php echo (int) $listed['item_quantity']; ?> <?php echo site_text('account.items'); ?> · <?php echo site_text($listed['delivery_method'] === 'PICKUP' ? 'checkout.pickup' : 'checkout.home_delivery'); ?></p></div><strong class="account-order-price"><?php echo $escape(Presentation::money($listed['total_amount'])); ?></strong></div>
                <div class="account-order-card__footer"><p><?php echo site_text($state['help']); ?></p><a class="account-text-link" href="<?php echo $escape(BASE_URL . $state['href']); ?>"><?php echo site_text($state['action']); ?><?php echo CustomerAccountView::icon('arrow'); ?></a></div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($pages > 1): ?>
            <nav class="account-pagination" aria-label="<?php echo site_text('account.orders'); ?>">
              <?php if ($page > 1): ?><a class="account-button account-button--secondary" href="<?php echo $escape(BASE_URL . 'account.php?' . http_build_query(['filter' => $filter, 'page' => $page - 1]) . '#orders'); ?>"><?php echo site_text('account.previous'); ?></a><?php endif; ?>
              <span><?php echo (int) $page . ' / ' . (int) $pages; ?></span>
              <?php if ($page < $pages): ?><a class="account-button account-button--secondary" href="<?php echo $escape(BASE_URL . 'account.php?' . http_build_query(['filter' => $filter, 'page' => $page + 1]) . '#orders'); ?>"><?php echo site_text('account.next'); ?></a><?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </section>
      <div class="account-bottom-grid">
        <section class="account-card"><header class="account-section-heading"><h2><?php echo site_text('account.profile_nav'); ?></h2><a class="account-icon-link" href="<?php echo $escape(BASE_URL . 'profile.php'); ?>" aria-label="<?php echo site_text('account.edit_profile'); ?>"><?php echo CustomerAccountView::icon('edit'); ?></a></header><dl class="account-details"><div><dt><?php echo site_text('footer.email'); ?></dt><dd><?php echo $escape($account['email']); ?></dd></div><div><dt><?php echo site_text('footer.phone'); ?></dt><dd><?php echo $escape($account['phone_number']); ?></dd></div></dl></section>
        <section class="account-card"><header class="account-section-heading"><h2><?php echo site_text('account.default_address'); ?></h2><a class="account-icon-link" href="<?php echo $escape(BASE_URL . 'profile.php#addresses'); ?>" aria-label="<?php echo site_text('account.addresses'); ?>"><?php echo CustomerAccountView::icon('edit'); ?></a></header>
          <?php if ($addresses === []): ?><p><?php echo site_text('account.no_addresses'); ?></p><?php else: $address = $addresses[0]; ?><address class="account-default-address"><strong><?php echo $escape($address['address_label'] ?: site_text('account.address')); ?></strong><span><?php echo $escape($address['street_address']); ?></span><span><?php echo $escape($address['delegation'] . ', ' . $address['governorate'] . ' ' . $address['postal_code']); ?></span></address><?php endif; ?>
        </section>
      </div>
    </div>
  </div>
</main>
<script src="<?php echo $escape(BASE_URL . 'assets/js/customer-account.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/customer-account.js')); ?>" defer></script>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
