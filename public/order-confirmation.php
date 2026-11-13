<?php

declare(strict_types=1);


// WHAT: the page shown right after checkout succeeds.
// WHY:  it reads the saved order back from the database, so the customer sees
//       the frozen snapshots that were actually stored, not the session cart.
require_once __DIR__ . '/../app/Bootstrap/checkout.php';

$orderNumber = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
$placed = is_array($_SESSION['checkout_placed_orders'] ?? null) ? $_SESSION['checkout_placed_orders'] : [];

// WHAT: only orders placed from this browser session can be viewed here.
// WHY:  the page shows a name, phone number and address; an order number in
//       the URL alone must not be enough to read someone else's details.
$order = null;
$items = [];
if (preg_match('/^[A-Z0-9-]{8,30}$/', $orderNumber) === 1 && is_int($placed[$orderNumber] ?? null)) {
    try {
        $order = $orderRepository->findSummary($placed[$orderNumber]);
        $items = $order === null ? [] : $orderRepository->findItems((int) $order['id']);
    } catch (Throwable) {
        error_log('Order confirmation lookup failed.');
        $order = null;
    }
}

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
// Stored DECIMAL strings are displayed as-is; they are already three-decimal values.
$money = static fn (string $amount): string => $amount . ' DT';
$pageTitle = \App\Services\Locale::text('confirmation.received') . " | Hom'As Candles";

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="cart-page checkout-page">
  <?php if ($order === null): ?>
    <header class="cart-hero">
      <p class="cart-eyebrow"><?php echo site_text('confirmation.title'); ?></p>
      <div class="cart-hero__heading">
        <h1><?php echo site_text('confirmation.not_found'); ?></h1>
      </div>
    </header>
    <div class="cart-empty checkout-confirmation-missing">
      <i data-lucide="shopping-cart" aria-hidden="true"></i>
      <h2><?php echo site_text('confirmation.cannot_show'); ?></h2>
      <p><?php echo site_text('confirmation.cannot_show_body'); ?></p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('cart.explore'); ?></a>
    </div>
  <?php else: ?>
    <?php $depositRequired = (float) $order['required_deposit_amount'] > 0; ?>
    <header class="cart-hero">
      <p class="cart-eyebrow"><?php echo site_text('confirmation.thanks'); ?></p>
      <div class="cart-hero__heading">
        <h1><?php echo site_text('confirmation.received'); ?></h1>
        <p class="cart-count"><?php echo site_text('confirmation.order'); ?> <?php echo $escape($order['order_number']); ?></p>
        <p><a href="<?php echo $escape(BASE_URL . 'order-payment.php?' . http_build_query(['order_id' => $order['id']])); ?>">Choose payment method / Submit D17 proof</a></p>
      </div>
    </header>

    <div class="cart-layout">
      <!-- SECTION PURPOSE: Explain what happens next and repeat the contact/delivery details that were saved. -->
      <section class="checkout-details" aria-labelledby="confirmation-next-title">
        <div class="checkout-section">
          <div class="cart-panel-heading">
            <h2 id="confirmation-next-title"><?php echo site_text('confirmation.next'); ?></h2>
            <span><?php echo site_text('confirmation.status'); ?></span>
          </div>
          <ol class="checkout-steps">
            <li><?php echo site_text('confirmation.call_1'); ?> <strong><?php echo $escape($order['customer_phone_snapshot']); ?></strong> <?php echo site_text('confirmation.call_2'); ?></li>
            <?php if ($depositRequired): ?>
              <li><?php echo site_text('confirmation.deposit_1'); ?> <strong><?php echo $escape($money($order['required_deposit_amount'])); ?></strong> <?php echo site_text('confirmation.deposit_2'); ?></li>
            <?php endif; ?>
            <li><?php echo site_text('confirmation.make_1'); ?> <strong><?php echo $escape($order['required_date']); ?></strong>.</li>
            <li>
              <?php if ($order['delivery_method'] === 'PICKUP'): ?>
                <?php echo site_text('confirmation.pickup'); ?>
              <?php else: ?>
                <?php echo site_text('confirmation.delivery'); ?> <?php echo site_text($depositRequired ? 'confirmation.balance' : 'confirmation.pay_delivery'); ?>
              <?php endif; ?>
            </li>
          </ol>
        </div>

        <div class="checkout-section">
          <div class="cart-panel-heading">
            <h2><?php echo site_text('confirmation.details'); ?></h2>
            <span><?php echo site_text($order['delivery_method'] === 'PICKUP' ? 'checkout.pickup' : 'checkout.home_delivery'); ?></span>
          </div>
          <dl class="checkout-recap">
            <div>
              <dt><?php echo site_text('checkout.name'); ?></dt>
              <dd><?php echo $escape($order['customer_name_snapshot']); ?></dd>
            </div>
            <div>
              <dt><?php echo site_text('footer.phone'); ?></dt>
              <dd><?php echo $escape($order['customer_phone_snapshot']); ?></dd>
            </div>
            <?php if ($order['customer_email_snapshot'] !== null): ?>
              <div>
                <dt><?php echo site_text('footer.email'); ?></dt>
                <dd><?php echo $escape($order['customer_email_snapshot']); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($order['delivery_method'] === 'DELIVERY'): ?>
              <div>
                <dt><?php echo site_text('checkout.street'); ?></dt>
                <dd>
                  <?php echo $escape($order['street_address_snapshot']); ?>,
                  <?php echo $escape($order['delegation_snapshot']); ?>,
                  <?php echo $escape($order['governorate_snapshot']); ?>
                  <?php echo $escape($order['postal_code_snapshot']); ?>
                </dd>
              </div>
            <?php endif; ?>
            <div>
              <dt><?php echo site_text('checkout.required_date'); ?></dt>
              <dd><?php echo $escape($order['required_date']); ?></dd>
            </div>
            <?php if ($order['customer_notes'] !== null): ?>
              <div>
                <dt><?php echo site_text('confirmation.notes'); ?></dt>
                <dd><?php echo nl2br($escape($order['customer_notes'])); ?></dd>
              </div>
            <?php endif; ?>
          </dl>
        </div>
      </section>

      <!-- SECTION PURPOSE: Show the frozen item snapshots and totals exactly as stored on the order. -->
      <aside class="cart-summary" aria-labelledby="confirmation-summary-title">
        <p class="cart-eyebrow"><?php echo site_text('cart.summary'); ?></p>
        <h2 id="confirmation-summary-title"><?php echo site_text('checkout.your_order'); ?></h2>
        <ul class="checkout-lines">
          <?php foreach ($items as $item): ?>
            <li>
              <div>
                <strong><?php echo $escape($item['product_name_snapshot']); ?></strong>
                <span>
                  <?php echo (int) $item['quantity']; ?> × <?php echo $escape($money($item['unit_price_snapshot'])); ?>
                  <?php if ($item['color_name_snapshot'] !== null): ?> · <?php echo $escape($item['color_name_snapshot']); ?><?php endif; ?>
                    <?php if ($item['scent_name_snapshot'] !== null): ?> · <?php echo $escape($item['scent_name_snapshot']); ?><?php endif; ?>
                </span>
              </div>
              <span><?php echo $escape($money($item['line_total_snapshot'])); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <dl class="cart-totals">
          <div>
            <dt><?php echo site_text('cart.subtotal'); ?></dt>
            <dd><?php echo $escape($money($order['subtotal'])); ?></dd>
          </div>
          <?php if ($order['promo_code_snapshot'] ?? null): ?><div><dt><?php echo $escape($order['promo_code_snapshot']); ?></dt><dd>-<?php echo $escape($money($order['promo_discount_amount'])); ?></dd></div><?php endif; ?>
          <?php if ($order['manual_discount_type'] ?? null): ?><div><dt>Discount</dt><dd>-<?php echo $escape($money($order['manual_discount_amount'])); ?></dd></div><?php endif; ?>
          <div>
            <dt><?php echo site_text('checkout.delivery'); ?></dt>
            <dd><?php echo $escape($money($order['delivery_fee'])); ?></dd>
          </div>
          <div class="cart-totals__grand">
            <dt><?php echo site_text('cart.total'); ?></dt>
            <dd><?php echo $escape($money($order['total_amount'])); ?></dd>
          </div>
          <?php if ($depositRequired): ?>
            <div class="checkout-deposit">
              <dt><?php echo site_text('confirmation.deposit_label'); ?></dt>
              <dd><?php echo $escape($money($order['required_deposit_amount'])); ?></dd>
            </div>
          <?php endif; ?>
        </dl>
        <a href="<?php echo BASE_URL . 'shop.php'; ?>" class="cart-checkout">
          <?php echo site_text('cart.continue'); ?>
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>
      </aside>
    </div>
  <?php endif; ?>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>
