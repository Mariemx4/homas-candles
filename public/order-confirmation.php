<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Order Confirmation.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */
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
$pageTitle = "Order received | Hom'As Candles";

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="cart-page checkout-page">
  <?php if ($order === null): ?>
    <header class="cart-hero">
      <p class="cart-eyebrow">Order confirmation</p>
      <div class="cart-hero__heading">
        <h1>Order not found</h1>
      </div>
    </header>
    <div class="cart-empty checkout-confirmation-missing">
      <i data-lucide="shopping-cart" aria-hidden="true"></i>
      <h2>We could not show this order</h2>
      <p>Confirmation pages are available only in the browser session that placed the order. If you placed an order, we will contact you by phone.</p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">Explore the collection</a>
    </div>
  <?php else: ?>
    <?php $depositRequired = (float) $order['required_deposit_amount'] > 0; ?>
    <header class="cart-hero">
      <p class="cart-eyebrow">Thank you</p>
      <div class="cart-hero__heading">
        <h1>Order received</h1>
        <p class="cart-count">Order <?php echo $escape($order['order_number']); ?></p>
      </div>
    </header>

    <div class="cart-layout">
      <!-- SECTION PURPOSE: Explain what happens next and repeat the contact/delivery details that were saved. -->
      <section class="checkout-details" aria-labelledby="confirmation-next-title">
        <div class="checkout-section">
          <div class="cart-panel-heading">
            <h2 id="confirmation-next-title">What happens next</h2>
            <span>Status: requested</span>
          </div>
          <ol class="checkout-steps">
            <li>We will call <strong><?php echo $escape($order['customer_phone_snapshot']); ?></strong> to confirm your order and required date.</li>
            <?php if ($depositRequired): ?>
              <li>Your order needs a deposit of <strong><?php echo $escape($money($order['required_deposit_amount'])); ?></strong> by D17 before we confirm it. We will send you the payment details.</li>
            <?php endif; ?>
            <li>Once confirmed, we hand-make your pieces in time for <strong><?php echo $escape($order['required_date']); ?></strong>.</li>
            <li>
              <?php if ($order['delivery_method'] === 'PICKUP'): ?>
                We will tell you when your order is ready for pickup.
              <?php else: ?>
                Your order will be delivered to the address below<?php echo $depositRequired ? '; the balance is paid on delivery.' : ' — pay by cash on delivery or D17.'; ?>
              <?php endif; ?>
            </li>
          </ol>
        </div>

        <div class="checkout-section">
          <div class="cart-panel-heading">
            <h2>Your details</h2>
            <span><?php echo $order['delivery_method'] === 'PICKUP' ? 'Pickup' : 'Home delivery'; ?></span>
          </div>
          <dl class="checkout-recap">
            <div><dt>Name</dt><dd><?php echo $escape($order['customer_name_snapshot']); ?></dd></div>
            <div><dt>Phone</dt><dd><?php echo $escape($order['customer_phone_snapshot']); ?></dd></div>
            <?php if ($order['customer_email_snapshot'] !== null): ?>
              <div><dt>Email</dt><dd><?php echo $escape($order['customer_email_snapshot']); ?></dd></div>
            <?php endif; ?>
            <?php if ($order['delivery_method'] === 'DELIVERY'): ?>
              <div><dt>Address</dt><dd>
                <?php echo $escape($order['street_address_snapshot']); ?>,
                <?php echo $escape($order['delegation_snapshot']); ?>,
                <?php echo $escape($order['governorate_snapshot']); ?>
                <?php echo $escape($order['postal_code_snapshot']); ?>
              </dd></div>
            <?php endif; ?>
            <div><dt>Required date</dt><dd><?php echo $escape($order['required_date']); ?></dd></div>
            <?php if ($order['customer_notes'] !== null): ?>
              <div><dt>Notes</dt><dd><?php echo nl2br($escape($order['customer_notes'])); ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>
      </section>

      <!-- SECTION PURPOSE: Show the frozen item snapshots and totals exactly as stored on the order. -->
      <aside class="cart-summary" aria-labelledby="confirmation-summary-title">
        <p class="cart-eyebrow">Summary</p>
        <h2 id="confirmation-summary-title">Your order</h2>
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
          <div><dt>Subtotal</dt><dd><?php echo $escape($money($order['subtotal'])); ?></dd></div>
          <div><dt>Delivery</dt><dd><?php echo $escape($money($order['delivery_fee'])); ?></dd></div>
          <div class="cart-totals__grand"><dt>Total</dt><dd><?php echo $escape($money($order['total_amount'])); ?></dd></div>
          <?php if ($depositRequired): ?>
            <div class="checkout-deposit"><dt>Deposit required</dt><dd><?php echo $escape($money($order['required_deposit_amount'])); ?></dd></div>
          <?php endif; ?>
        </dl>
        <a href="<?php echo BASE_URL . 'shop.php'; ?>" class="cart-checkout">
          Continue shopping
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>
      </aside>
    </div>
  <?php endif; ?>
</main>

<?php
require_once __DIR__ . '/../app/Views/Layouts/footer.php';
?>
