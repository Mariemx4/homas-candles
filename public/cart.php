<?php
// WHAT: the cart page — currently rendering a hardcoded empty cart.
// WHY:  $cartItems isn't wired to a session or database yet, so every
//       number below (subtotal, delivery fee, item count) computes
//       correctly but always over an empty list. This is the page to
//       revisit once "add to cart" actually stores something somewhere.
// Replace this empty array with the customer's session/database cart items
// when the shop's add-to-cart flow is implemented.
$cartItems = [];

$deliveryFee = 9.000;
$subtotal = array_reduce(
  $cartItems,
  fn(float $total, array $item): float => $total + ($item['price'] * $item['quantity']),
  0.0
);
$itemCount = array_sum(array_column($cartItems, 'quantity'));
$appliedDeliveryFee = $itemCount > 0 ? $deliveryFee : 0.0;
$pageTitle = "Cart | Hom'As Candles";

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="cart-page" data-delivery-fee="<?php echo $deliveryFee; ?>">
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

  <div class="cart-layout">
    <section class="cart-items" aria-labelledby="cart-items-title">
      <div class="cart-panel-heading">
        <h2 id="cart-items-title">Selected pieces</h2>
        <span>Handmade to order</span>
      </div>

      <div class="cart-list" data-cart-list>
        <?php foreach ($cartItems as $item): ?>
          <!-- WHAT: price/quantity below are echoed raw, unlike name/category/color/scent
               further down which go through htmlspecialchars(). Harmless while $cartItems
               is always empty, but worth fixing to htmlspecialchars()/casting once real
               (user-influenced) cart data flows through here. -->
          <article class="cart-item" data-cart-item data-price="<?php echo $item['price']; ?>">
            <a href="<?php echo BASE_URL . 'shop.php'; ?>" class="cart-item__image-link"
              aria-label="View <?php echo htmlspecialchars($item['name']); ?>">
              <img src="<?php echo BASE_URL . $item['image']; ?>"
                alt="<?php echo htmlspecialchars($item['name']); ?>" class="cart-item__image">
            </a>

            <div class="cart-item__details">
              <p class="cart-item__category"><?php echo htmlspecialchars($item['category']); ?></p>
              <h3><?php echo htmlspecialchars($item['name']); ?></h3>
              <dl class="cart-item__options">
                <div><dt>Colour</dt><dd><?php echo htmlspecialchars($item['color']); ?></dd></div>
                <div><dt>Scent</dt><dd><?php echo htmlspecialchars($item['scent']); ?></dd></div>
              </dl>
              <button class="cart-remove" type="button" data-action="remove"
                aria-label="Remove <?php echo htmlspecialchars($item['name']); ?> from cart">Remove</button>
            </div>

            <div class="cart-item__purchase">
              <p class="cart-item__price" data-line-total>
                <?php echo number_format($item['price'] * $item['quantity'], 3); ?> DT
              </p>
              <div class="cart-quantity" aria-label="Quantity for <?php echo htmlspecialchars($item['name']); ?>">
                <button type="button" data-action="decrease" aria-label="Decrease quantity">−</button>
                <input type="number" value="<?php echo $item['quantity']; ?>" min="1" max="20"
                  inputmode="numeric" aria-label="Quantity" data-quantity>
                <button type="button" data-action="increase" aria-label="Increase quantity">+</button>
              </div>
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
          <dd data-cart-subtotal><?php echo number_format($subtotal, 3); ?> DT</dd>
        </div>
        <div>
          <dt>Delivery across Tunisia</dt>
          <dd data-cart-delivery><?php echo number_format($appliedDeliveryFee, 3); ?> DT</dd>
        </div>
        <div class="cart-totals__grand">
          <dt>Total</dt>
          <dd data-cart-total><?php echo number_format($subtotal + $appliedDeliveryFee, 3); ?> DT</dd>
        </div>
      </dl>

      <div class="cart-note">
        <div class="cart-note__heading">
          <label for="gift-note">Add a gift note</label>
          <span><span data-note-count>0</span>/180</span>
        </div>
        <textarea id="gift-note" name="gift_note" rows="3" maxlength="180"
          placeholder="Write a short message for someone special..."></textarea>
      </div>

      <a href="<?php echo BASE_URL . 'contact.php'; ?>" class="cart-checkout" data-cart-checkout
        <?php echo $itemCount === 0 ? 'aria-disabled="true"' : ''; ?>>
        Proceed with your order
        <i data-lucide="arrow-right" aria-hidden="true"></i>
      </a>

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
