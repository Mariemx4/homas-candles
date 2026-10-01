<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Checkout.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */
// Checkout uses POST/Redirect/GET: a failed submission stores its errors and
// values in the session and redirects back here, so refreshing never resends.
require_once __DIR__ . '/../app/Bootstrap/checkout.php';

use App\Services\CheckoutService;

$customerId = $customerSession->customerId();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    if (!$csrf->validate($token)) {
      throw new DomainException('Your form expired. Please review your order and try again.');
    }
    $result = $checkoutService->place($_POST, $customerId);
  } catch (DomainException $error) {
    $result = ['success' => false, 'errors' => ['form' => $error->getMessage()], 'values' => []];
  } catch (Throwable) {
    error_log('Checkout failed.');
    $result = ['success' => false, 'errors' => ['form' => 'Your order could not be placed right now. Nothing was charged; please try again.'], 'values' => []];
  }

  if ($result['success']) {
    // WHAT: remember which orders this browser session placed.
    // WHY:  order-confirmation.php shows personal details, so it only opens
    //       orders listed here — guessing another order number shows nothing.
    //       The list is capped so the session cannot grow without limit.
    $placed = is_array($_SESSION['checkout_placed_orders'] ?? null) ? $_SESSION['checkout_placed_orders'] : [];
    $placed[$result['orderNumber']] = $result['orderId'];
    $_SESSION['checkout_placed_orders'] = array_slice($placed, -10, null, true);
    unset($_SESSION['checkout_form']);
    header('Location: ' . BASE_URL . 'order-confirmation.php?order=' . rawurlencode($result['orderNumber']), true, 303);
    exit;
  }

  $_SESSION['checkout_form'] = ['errors' => $result['errors'], 'values' => $result['values']];
  header('Location: ' . BASE_URL . 'checkout.php', true, 303);
  exit;
}

$flash = is_array($_SESSION['checkout_form'] ?? null) ? $_SESSION['checkout_form'] : [];
unset($_SESSION['checkout_form']);
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$values = array_merge($checkoutService->defaultsFor($customerId), is_array($flash['values'] ?? null) ? $flash['values'] : []);

try {
  $summary = $checkoutService->summary($values['delivery_method']);
} catch (Throwable) {
  error_log('Checkout summary failed.');
  $_SESSION['cart_flash'] = ['error' => 'Checkout is temporarily unavailable. Please try again.'];
  header('Location: ' . BASE_URL . 'cart.php', true, 303);
  exit;
}

// WHAT: checkout only opens for a cart that can actually become an order.
// WHY:  the cart page explains the 40 DT minimum; sending the customer back
//       there keeps one place responsible for editing the selection.
if ($summary['items'] === [] || !$summary['meets_minimum']) {
  $_SESSION['cart_flash'] = ['error' => $summary['items'] === []
    ? 'Your cart is empty. Add a piece before checking out.'
    : sprintf('The minimum order is %s DT of pieces. Add %s DT more to continue.',
      CheckoutService::formatMoney($summary['minimum_millis']),
      CheckoutService::formatMoney($summary['shortfall_millis']))];
  header('Location: ' . BASE_URL . 'cart.php', true, 303);
  exit;
}

$preparation = $checkoutService->preparationInfo();
$money = static fn (int $millis): string => CheckoutService::formatMoney($millis) . ' DT';
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field]) ? $errors[$field] : null;
// WHAT: these helpers keep each field's accessible server error markup consistent.
// WHY:  matching message IDs let aria-describedby announce the exact problem.
$serverError = static function (string $field) use ($fieldError, $escape): void {
  $message = $fieldError($field);
  if ($message !== null) {
    echo '<p id="' . $escape($field) . '-validation-message" class="field-validation-message" role="alert">'
      . $escape($message) . '</p>';
  }
};
$errorAttributes = static function (string $field) use ($fieldError, $escape): string {
  return $fieldError($field) === null ? '' : ' aria-invalid="true" aria-describedby="' . $escape($field) . '-validation-message"';
};
$isPickup = $values['delivery_method'] === 'PICKUP';
$pageTitle = \App\Services\Locale::text('checkout.title') . " | Hom'As Candles";

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="cart-page checkout-page">
  <header class="cart-hero">
    <a href="<?php echo BASE_URL . 'cart.php'; ?>" class="cart-back-link">
      <i data-lucide="arrow-left" aria-hidden="true"></i>
      <?php echo site_text('checkout.back'); ?>
    </a>
    <p class="cart-eyebrow"><?php echo site_text('checkout.almost'); ?></p>
    <div class="cart-hero__heading">
      <h1><?php echo site_text('checkout.title'); ?></h1>
      <p class="cart-count"><?php echo (int) $summary['item_count']; ?> <?php echo site_text($summary['item_count'] === 1 ? 'cart.piece' : 'cart.pieces'); ?></p>
    </div>
  </header>

  <?php if ($fieldError('form') !== null): ?>
    <p class="cart-feedback checkout-feedback--error" role="alert"><?php echo $escape($fieldError('form')); ?></p>
  <?php elseif ($errors !== []): ?>
    <p class="cart-feedback checkout-feedback--error" role="alert"><?php echo site_text('checkout.correct'); ?></p>
  <?php endif; ?>

  <!-- FORM PURPOSE: Submit checkout form with POST; CheckoutService re-validates every value and recalculates every price. -->
  <form class="cart-layout checkout-form" action="<?php echo BASE_URL . 'checkout.php'; ?>" method="POST" data-checkout-form novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
    <?php // WHAT: fingerprint of the items and prices rendered below. ?>
    <?php // WHY:  if the cart changes before submit, the server refuses and asks for a fresh review. ?>
    <input type="hidden" name="cart_signature" value="<?php echo $escape($summary['signature']); ?>">

    <div class="checkout-details">
      <!-- SECTION PURPOSE: Collect the contact details the business uses to confirm the order. -->
      <section class="checkout-section" aria-labelledby="checkout-contact-title">
        <div class="cart-panel-heading">
          <h2 id="checkout-contact-title"><?php echo site_text('checkout.contact'); ?></h2>
          <span><?php echo site_text('checkout.personal'); ?></span>
        </div>
        <div class="checkout-grid">
          <div class="checkout-field checkout-field--wide">
            <label for="customer_name"><?php echo site_text('checkout.name'); ?></label>
            <input type="text" id="customer_name" name="customer_name" value="<?php echo $escape($values['customer_name']); ?>" autocomplete="name" minlength="2" maxlength="201" required<?php echo $errorAttributes('customer_name'); ?>>
            <?php $serverError('customer_name'); ?>
          </div>
          <div class="checkout-field">
            <label for="customer_phone"><?php echo site_text('checkout.phone'); ?></label>
            <input type="tel" id="customer_phone" name="customer_phone" value="<?php echo $escape($values['customer_phone'] !== '' ? $values['customer_phone'] : '+216 '); ?>" placeholder="+216 XX XXX XXX" autocomplete="tel" inputmode="tel" maxlength="15"
              pattern="(?:\+216|00216)?[\s-]*(?:\d[\s-]*){8}" required<?php echo $errorAttributes('customer_phone'); ?>>
            <?php $serverError('customer_phone'); ?>
          </div>
          <div class="checkout-field">
            <label for="customer_email"><?php echo site_text('checkout.email'); ?> <span>(<?php echo site_text('checkout.optional'); ?>)</span></label>
            <input type="email" id="customer_email" name="customer_email" value="<?php echo $escape($values['customer_email']); ?>" placeholder="you@example.com" autocomplete="email" maxlength="255"<?php echo $errorAttributes('customer_email'); ?>>
            <?php $serverError('customer_email'); ?>
          </div>
        </div>
        <?php if ($customerId === null): ?>
          <p class="checkout-hint">
            <?php echo site_text('checkout.account_1'); ?> <a href="<?php echo BASE_URL . 'login.php'; ?>"><?php echo site_text('nav.sign_in'); ?></a> <?php echo site_text('checkout.account_2'); ?>
          </p>
        <?php endif; ?>
      </section>

      <!-- SECTION PURPOSE: Choose delivery or pickup and, for delivery, the address frozen on the order. -->
      <section class="checkout-section" aria-labelledby="checkout-delivery-title">
        <div class="cart-panel-heading">
          <h2 id="checkout-delivery-title"><?php echo site_text('checkout.delivery'); ?></h2>
          <span><?php echo site_text('checkout.delivery_choices'); ?></span>
        </div>
        <fieldset class="checkout-methods"<?php echo $errorAttributes('delivery_method'); ?>>
          <legend class="checkout-legend"><?php echo site_text('checkout.delivery_method'); ?></legend>
          <label class="checkout-method">
            <input type="radio" name="delivery_method" value="DELIVERY" <?php echo $isPickup ? '' : 'checked'; ?> data-delivery-method>
            <span><strong><?php echo site_text('checkout.home_delivery'); ?></strong> <?php echo $escape($money($checkoutSettings['delivery_fee_millis'])); ?></span>
          </label>
          <label class="checkout-method">
            <input type="radio" name="delivery_method" value="PICKUP" <?php echo $isPickup ? 'checked' : ''; ?> data-delivery-method>
            <span><strong><?php echo site_text('checkout.pickup'); ?></strong> <?php echo site_text('checkout.pickup_note'); ?></span>
          </label>
          <?php $serverError('delivery_method'); ?>
        </fieldset>

        <?php // WHAT: the address block is disabled (not just hidden) for pickup. ?>
        <?php // WHY:  disabled inputs are not submitted or validated by the browser. ?>
        <div class="checkout-grid" data-address-fields <?php echo $isPickup ? 'hidden' : ''; ?>>
          <div class="checkout-field checkout-field--wide">
            <label for="street_address"><?php echo site_text('checkout.street'); ?></label>
            <input type="text" id="street_address" name="street_address" value="<?php echo $escape($values['street_address']); ?>" autocomplete="street-address" maxlength="255" required<?php echo $isPickup ? ' disabled' : ''; ?><?php echo $errorAttributes('street_address'); ?>>
            <?php $serverError('street_address'); ?>
          </div>
          <div class="checkout-field">
            <label for="governorate"><?php echo site_text('checkout.governorate'); ?></label>
            <select id="governorate" name="governorate" autocomplete="address-level1" required<?php echo $isPickup ? ' disabled' : ''; ?><?php echo $errorAttributes('governorate'); ?>>
              <option value=""><?php echo site_text('checkout.choose_governorate'); ?></option>
              <?php foreach ($governorates as $governorate): ?>
                <option value="<?php echo $escape($governorate); ?>" <?php echo $values['governorate'] === $governorate ? 'selected' : ''; ?>><?php echo $escape($governorate); ?></option>
              <?php endforeach; ?>
            </select>
            <?php $serverError('governorate'); ?>
          </div>
          <div class="checkout-field">
            <label for="delegation"><?php echo site_text('checkout.delegation'); ?></label>
            <input type="text" id="delegation" name="delegation" value="<?php echo $escape($values['delegation']); ?>" autocomplete="address-level2" maxlength="100" required<?php echo $isPickup ? ' disabled' : ''; ?><?php echo $errorAttributes('delegation'); ?>>
            <?php $serverError('delegation'); ?>
          </div>
          <div class="checkout-field">
            <label for="postal_code"><?php echo site_text('checkout.postal'); ?></label>
            <input type="text" id="postal_code" name="postal_code" value="<?php echo $escape($values['postal_code']); ?>" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required<?php echo $isPickup ? ' disabled' : ''; ?><?php echo $errorAttributes('postal_code'); ?>>
            <?php $serverError('postal_code'); ?>
          </div>
        </div>
      </section>

      <!-- SECTION PURPOSE: Ask for the required date and show preparation time before the customer confirms. -->
      <section class="checkout-section" aria-labelledby="checkout-date-title">
        <div class="cart-panel-heading">
          <h2 id="checkout-date-title"><?php echo site_text('checkout.when'); ?></h2>
          <span><?php echo site_text('cart.handmade'); ?></span>
        </div>
        <div class="checkout-preparation" id="preparation-info">
          <i data-lucide="sparkles" aria-hidden="true"></i>
          <div>
            <h3><?php echo site_text('checkout.preparation'); ?></h3>
            <p>
              <?php echo site_text('checkout.preparation_1'); ?>
              <strong><?php echo (int) $preparation['preparation_days']; ?> <?php echo site_text('checkout.days'); ?></strong>
              <?php echo site_text('checkout.preparation_2'); ?> <strong><?php echo $escape($preparation['earliest']); ?></strong>.
              <?php echo site_text('checkout.preparation_3'); ?>
            </p>
          </div>
        </div>
        <div class="checkout-grid">
          <div class="checkout-field">
            <label for="required_date"><?php echo site_text('checkout.required_date'); ?></label>
            <input type="date" id="required_date" name="required_date" value="<?php echo $escape($values['required_date']); ?>"
              min="<?php echo $escape($preparation['earliest']); ?>" max="<?php echo $escape($preparation['latest']); ?>" required<?php echo $errorAttributes('required_date'); ?>>
            <?php $serverError('required_date'); ?>
          </div>
        </div>
        <div class="cart-note">
          <div class="cart-note__heading">
            <label for="customer_notes"><?php echo site_text('checkout.notes'); ?> <span>(<?php echo site_text('checkout.optional'); ?>)</span></label>
            <span><span data-note-count><?php echo function_exists('mb_strlen') ? mb_strlen($values['customer_notes']) : strlen($values['customer_notes']); ?></span>/<?php echo (int) $checkoutSettings['customer_notes_max_length']; ?></span>
          </div>
          <textarea id="customer_notes" name="customer_notes" rows="3" maxlength="<?php echo (int) $checkoutSettings['customer_notes_max_length']; ?>"
            placeholder="<?php echo site_text('checkout.notes_placeholder'); ?>"<?php echo $errorAttributes('customer_notes'); ?>><?php echo $escape($values['customer_notes']); ?></textarea>
          <?php $serverError('customer_notes'); ?>
        </div>
      </section>
    </div>

    <!-- SECTION PURPOSE: Show the authoritative, server-calculated totals the customer is confirming. -->
    <aside class="cart-summary" aria-labelledby="checkout-summary-title"
      data-checkout-summary
      data-subtotal-millis="<?php echo (int) $summary['subtotal_millis']; ?>"
      data-delivery-fee-millis="<?php echo (int) $checkoutSettings['delivery_fee_millis']; ?>"
      data-deposit-threshold-millis="<?php echo (int) $preparation['deposit_threshold_millis']; ?>"
      data-deposit-percent="<?php echo (int) $preparation['deposit_percent']; ?>">
      <p class="cart-eyebrow"><?php echo site_text('checkout.review'); ?></p>
      <h2 id="checkout-summary-title"><?php echo site_text('checkout.your_order'); ?></h2>

      <ul class="checkout-lines">
        <?php foreach ($summary['items'] as $item): ?>
          <li>
            <div>
              <strong><?php echo $escape($item['name']); ?></strong>
              <span>
                <?php echo (int) $item['quantity']; ?> × <?php echo $escape($money($item['unit_price_millis'])); ?>
                <?php if ($item['color_id'] !== null): ?> · <?php echo $escape($item['color']); ?><?php endif; ?>
                <?php if ($item['scent_id'] !== null): ?> · <?php echo $escape($item['scent']); ?><?php endif; ?>
              </span>
            </div>
            <span><?php echo $escape($money($item['line_total_millis'])); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>

      <dl class="cart-totals">
        <div>
          <dt><?php echo site_text('cart.subtotal'); ?></dt>
          <dd><?php echo $escape($money($summary['subtotal_millis'])); ?></dd>
        </div>
        <div>
          <dt><?php echo site_text('checkout.delivery'); ?></dt>
          <dd data-checkout-delivery><?php echo $escape($money($summary['delivery_fee_millis'])); ?></dd>
        </div>
        <div class="cart-totals__grand">
          <dt><?php echo site_text('cart.total'); ?></dt>
          <dd data-checkout-total><?php echo $escape($money($summary['total_millis'])); ?></dd>
        </div>
        <div class="checkout-deposit" data-checkout-deposit-row <?php echo $summary['deposit_millis'] > 0 ? '' : 'hidden'; ?>>
          <dt><?php echo site_text('checkout.deposit'); ?> (<?php echo (int) $preparation['deposit_percent']; ?>%)</dt>
          <dd data-checkout-deposit><?php echo $escape($money($summary['deposit_millis'])); ?></dd>
        </div>
      </dl>

      <p class="checkout-payment-note" data-deposit-note-small <?php echo $summary['deposit_millis'] > 0 ? 'hidden' : ''; ?>>
        <?php echo site_text('checkout.payment_small'); ?>
      </p>
      <p class="checkout-payment-note" data-deposit-note-large <?php echo $summary['deposit_millis'] > 0 ? '' : 'hidden'; ?>>
        <?php echo site_text('checkout.payment_large_1'); ?> <?php echo $escape($money($preparation['deposit_threshold_millis'])); ?> <?php echo site_text('checkout.payment_large_2'); ?>
        <?php echo (int) $preparation['deposit_percent']; ?>% <?php echo site_text('checkout.payment_large_3'); ?>
      </p>

      <div class="checkout-confirm">
        <label>
          <input type="checkbox" name="accept_terms" value="1" required aria-describedby="preparation-info"<?php echo $errorAttributes('accept_terms'); ?>>
          <span><?php echo site_text('checkout.confirm'); ?></span>
        </label>
        <?php $serverError('accept_terms'); ?>
      </div>

      <button type="submit" class="cart-checkout" data-checkout-submit>
        <?php echo site_text('checkout.place'); ?>
        <i data-lucide="arrow-right" aria-hidden="true"></i>
      </button>

      <ul class="cart-reassurance">
        <li><i data-lucide="sparkles" aria-hidden="true"></i><?php echo site_text('checkout.prices_checked'); ?></li>
        <li><i data-lucide="shopping-cart" aria-hidden="true"></i><?php echo site_text('checkout.no_charge'); ?></li>
      </ul>
    </aside>
  </form>
</main>

<?php
require_once __DIR__ . '/../app/Views/Layouts/footer.php';
?>
