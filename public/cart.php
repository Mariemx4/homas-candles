<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Cart.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */
// Cart changes use POST/Redirect/GET; the display is rebuilt from current products.
// The checkout bootstrap also wires the cart, so totals use the same rules as checkout.
require_once __DIR__ . '/../app/Bootstrap/checkout.php';

use App\Services\CheckoutService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  try {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    if (!$csrf->validate($token)) {
      throw new DomainException('Your form expired. Please reload and try again.');
    }
    match ($action) {
      'add' => $cartService->add($_POST),
      'update' => $cartService->update($_POST),
      'remove' => $cartService->remove($_POST),
      default => throw new DomainException('Choose a valid cart action.'),
    };
    $_SESSION['cart_flash'] = ['success' => $action === 'add' ? 'Piece added to your cart.' : 'Cart updated.'];
  } catch (DomainException $error) {
    $_SESSION['cart_flash'] = ['error' => $error->getMessage()];
  } catch (Throwable) {
    error_log('Cart operation failed.');
    $_SESSION['cart_flash'] = ['error' => 'The cart is temporarily unavailable. Please try again.'];
  }
  $slug = $_POST['slug'] ?? null;
  $backToProduct = $action === 'add' && isset($_SESSION['cart_flash']['error'])
    && is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
  $destination = $backToProduct ? 'product.php?slug=' . rawurlencode($slug) : 'cart.php';
  header('Location: ' . BASE_URL . $destination, true, 303);
  exit;
}

$cartFlash = $_SESSION['cart_flash'] ?? null;
unset($_SESSION['cart_flash']);
// WHAT: CheckoutService::summary() prices the cart in integer thousandths.
// WHY:  the cart must show exactly the totals checkout will charge, including
//       the delivery fee from app/config/checkout.php and the 40 DT minimum.
$cartSummary = $checkoutService->summary('DELIVERY');
$cartItems = $cartSummary['items'];
$itemCount = $cartSummary['item_count'];
$money = static fn (int $millis): string => CheckoutService::formatMoney($millis) . ' DT';
$pageTitle = "Cart | Hom'As Candles";

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="cart-page">
  <header class="cart-hero">
    <a href="<?php echo BASE_URL . 'shop.php'; ?>" class="cart-back-link">
      <i data-lucide="arrow-left" aria-hidden="true"></i>
      Continue shopping
    </a>
    <p class="cart-eyebrow">Your Hom'As selection</p>
    <div class="cart-hero__heading">
      <h1>Your cart</h1>
      <p class="cart-count" aria-live="polite">
        <span data-cart-count><?php echo $itemCount; ?></span>
        <span data-cart-count-label><?php echo $itemCount === 1 ? 'piece' : 'pieces'; ?></span>
      </p>
    </div>
  </header>

  <?php if (is_array($cartFlash) && isset($cartFlash['error'])): ?>
    <p class="cart-feedback" role="alert"><?php echo htmlspecialchars((string) $cartFlash['error']); ?></p>
  <?php elseif (is_array($cartFlash) && isset($cartFlash['success'])): ?>
    <p class="cart-feedback" role="status"><?php echo htmlspecialchars((string) $cartFlash['success']); ?></p>
  <?php endif; ?>

  <div class="cart-layout">
    <!-- SECTION PURPOSE: Group the Selected pieces content, states, and controls as one accessible page region. -->
    <section class="cart-items" aria-labelledby="cart-items-title">
      <div class="cart-panel-heading">
        <h2 id="cart-items-title">Selected pieces</h2>
        <span>Handmade to order</span>
      </div>

      <div class="cart-list" data-cart-list>
        <?php foreach ($cartItems as $item): ?>
          <article class="cart-item" data-cart-item>
            <a href="<?php echo BASE_URL . 'product.php?slug=' . rawurlencode($item['slug']); ?>" class="cart-item__image-link"
              aria-label="View <?php echo htmlspecialchars($item['name']); ?>">
              <img src="<?php echo BASE_URL . htmlspecialchars($item['image']); ?>"
                alt="<?php echo htmlspecialchars($item['name']); ?>" class="cart-item__image">
            </a>

            <div class="cart-item__details">
              <p class="cart-item__category"><?php echo htmlspecialchars($item['category']); ?></p>
              <h3><?php echo htmlspecialchars($item['name']); ?></h3>
              <dl class="cart-item__options">
                <div><dt>Colour</dt><dd><?php echo htmlspecialchars($item['color']); ?></dd></div>
                <div><dt>Scent</dt><dd><?php echo htmlspecialchars($item['scent']); ?></dd></div>
              </dl>
              <form action="<?php echo BASE_URL . 'cart.php'; ?>" method="POST">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="line_id" value="<?php echo htmlspecialchars($item['line_id']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="cart-remove" type="submit"
                  aria-label="Remove <?php echo htmlspecialchars($item['name']); ?> from cart">Remove</button>
              </form>
            </div>

            <div class="cart-item__purchase">
              <p class="cart-item__price" data-line-total>
                <?php echo $money($item['line_total_millis']); ?>
              </p>
              <form action="<?php echo BASE_URL . 'cart.php'; ?>" method="POST" data-cart-update-form>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="line_id" value="<?php echo htmlspecialchars($item['line_id']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="cart-quantity" aria-label="Quantity for <?php echo htmlspecialchars($item['name']); ?>">
                  <button type="button" data-action="decrease" aria-label="Decrease quantity">−</button>
                  <input type="number" name="quantity" value="<?php echo (int) $item['quantity']; ?>"
                    min="<?php echo (int) $item['minimum_quantity']; ?>" max="<?php echo (int) $item['max_quantity']; ?>"
                    inputmode="numeric" required aria-label="Quantity" data-quantity>
                  <button type="button" data-action="increase" aria-label="Increase quantity">+</button>
                </div>
                <button type="submit" class="cart-update">Update</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="cart-empty" data-cart-empty <?php echo $itemCount > 0 ? 'hidden' : ''; ?>>
        <i data-lucide="shopping-cart" aria-hidden="true"></i>
        <h2>Your cart is waiting</h2>
        <p>Discover a handmade piece that brings a little more warmth to your day.</p>
        <a href="<?php echo BASE_URL . 'shop.php'; ?>">Explore the collection</a>
      </div>
    </section>

    <aside class="cart-summary" aria-labelledby="cart-summary-title">
      <p class="cart-eyebrow">Order details</p>
      <h2 id="cart-summary-title">Your summary</h2>

      <dl class="cart-totals">
        <div>
          <dt>Subtotal</dt>
          <dd data-cart-subtotal><?php echo $money($cartSummary['subtotal_millis']); ?></dd>
        </div>
        <div>
          <dt>Delivery across Tunisia</dt>
          <dd data-cart-delivery><?php echo $money($cartSummary['delivery_fee_millis']); ?></dd>
        </div>
        <div class="cart-totals__grand">
          <dt>Total</dt>
          <dd data-cart-total><?php echo $money($cartSummary['total_millis']); ?></dd>
        </div>
      </dl>
      <p class="cart-minimum">Pickup is free — choose it at checkout. A gift note can be added there too.</p>

      <?php // WHAT: the checkout link appears only for a cart that can become an order. ?>
      <?php // WHY:  the 40 DT minimum applies to the pieces, so the customer is told exactly how much is missing. ?>
      <?php if ($cartSummary['meets_minimum']): ?>
        <a href="<?php echo BASE_URL . 'checkout.php'; ?>" class="cart-checkout" data-cart-checkout>
          Proceed with your order
          <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>
      <?php else: ?>
        <?php if ($itemCount > 0): ?>
          <p class="cart-minimum cart-minimum--warning" role="status">
            The minimum order is <?php echo $money($cartSummary['minimum_millis']); ?> of pieces.
            Add <?php echo $money($cartSummary['shortfall_millis']); ?> more to check out.
          </p>
        <?php endif; ?>
        <span class="cart-checkout" aria-disabled="true">Proceed with your order</span>
      <?php endif; ?>

      <ul class="cart-reassurance">
        <li><i data-lucide="sparkles" aria-hidden="true"></i>Carefully handmade and wrapped</li>
        <li><i data-lucide="shopping-cart" aria-hidden="true"></i>Payment by cash on delivery or D17</li>
      </ul>

      <div class="ui-state-group ui-state-group--compact cart-state-group" data-cart-states
        aria-live="polite" aria-atomic="true" hidden>
        <div class="ui-state" data-ui-state="loading" hidden>
          <span class="ui-state__spinner" aria-hidden="true"></span>
          <div><h3>Updating your cart…</h3><p>Please wait a moment.</p></div>
        </div>
        <div class="ui-state ui-state--error" data-ui-state="error" role="alert" hidden>
          <i data-lucide="x" aria-hidden="true"></i>
          <div><h3>Your cart could not be updated.</h3><p data-ui-state-message>Please try again.</p></div>
        </div>
        <div class="ui-state ui-state--success" data-ui-state="success" hidden>
          <i data-lucide="check" aria-hidden="true"></i>
          <div><h3>Cart updated.</h3><p data-ui-state-message>Your changes have been applied.</p></div>
        </div>
      </div>
    </aside>
  </div>
</main>

<?php
require_once __DIR__ . '/../app/Views/Layouts/footer.php';
?>
