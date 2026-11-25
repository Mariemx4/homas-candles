<?php

declare(strict_types=1);



$viewVariables = get_defined_vars();
if (
    !array_key_exists('product', $viewVariables)
    || !array_key_exists('productLoadError', $viewVariables)
    || !isset($colors, $scents, $galleryImages, $pageTitle)
) {
    throw new RuntimeException('Product view data is required.');
}
unset($viewVariables);

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="product-page" data-product-available="<?php echo $product !== null ? 'true' : 'false'; ?>"
  data-product-state="<?php echo $productLoadError !== null ? 'error' : ($product === null ? 'empty' : 'success'); ?>">
  <?php if ($productLoadError !== null): ?>
    <!-- WHAT: shown when the query above threw (DB connection issue, etc.) -->
    <section class="product-unavailable" aria-labelledby="product-error-title" role="alert">
      <div class="product-unavailable__icon" aria-hidden="true">
        <i data-lucide="x"></i>
      </div>
      <p class="product-eyebrow">Something went wrong</p>
      <h1 id="product-error-title">This piece could not be loaded.</h1>
      <p><?php echo htmlspecialchars($productLoadError); ?></p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('product.return'); ?><i data-lucide="arrow-right" aria-hidden="true"></i></a>
    </section>
  <?php elseif ($product === null): ?>
    <!-- WHAT: shown when the slug didn't match any active product -->
    <section class="product-unavailable" aria-labelledby="product-unavailable-title">
      <div class="product-unavailable__icon" aria-hidden="true">
        <i data-lucide="sparkles"></i>
      </div>
      <p class="product-eyebrow">Hom'As collection</p>
      <h1 id="product-unavailable-title"><?php echo site_text('product.not_found'); ?></h1>
      <p>This piece may still be in preparation. Explore the collection to discover what is currently available.</p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">
        <?php echo site_text('product.return'); ?>
        <i data-lucide="arrow-right" aria-hidden="true"></i>
      </a>
    </section>
  <?php else: ?>
    <nav class="product-breadcrumb" aria-label="<?php echo site_text('category.breadcrumb'); ?>">
      <a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('nav.shop'); ?></a>
      <span aria-hidden="true">/</span>
      <span><?php echo htmlspecialchars($product['category_name']); ?></span>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?php echo htmlspecialchars($product['name']); ?></span>
    </nav>

    <article class="product-layout">
      <section class="product-gallery" aria-label="Product images">
        <div class="product-gallery__main">
          <img src="<?php echo BASE_URL . $product['main_image_path']; ?>"
            alt="<?php echo htmlspecialchars($product['name']); ?>" data-product-main-image>
          <span><?php echo site_text($product['is_made_to_order'] ? 'product.made_to_order' : 'product.ready'); ?></span>
        </div>

        <?php if (count($galleryImages) > 1): ?>
          <div class="product-thumbnails">
            <?php foreach ($galleryImages as $index => $image): ?>
              <button type="button" class="product-thumbnail <?php echo $index === 0 ? 'active' : ''; ?>"
                data-gallery-image="<?php echo BASE_URL . $image; ?>"
                aria-label="View image <?php echo $index + 1; ?>"
                aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                <img src="<?php echo BASE_URL . $image; ?>" alt="">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="product-information" aria-labelledby="product-title">
        <p class="product-eyebrow"><?php echo htmlspecialchars($product['category_name']); ?></p>
        <h1 id="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
        <p class="product-price"><?php echo $product['purchase_mode'] === 'REQUEST_ONLY'
                                    ? site_text('product.request_price')
                                    : number_format((float) $product['selling_price'], 3) . ' DT'; ?></p>
        <p class="product-description"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>

        <?php // Candle sets list their components (app/Views/Components/product-set-components.php).
        ?>
        <?php require VIEW_PATH . '/Components/product-set-components.php'; ?>

        <?php if (is_array($cartFlash ?? null) && ($cartFlash['error'] ?? null)): ?>
          <p role="alert"><?php echo htmlspecialchars((string) $cartFlash['error']); ?></p>
        <?php endif; ?>

        <!-- WHAT: CART products get color/scent pickers + a quantity stepper;
             REQUEST_ONLY products (bouquets, event favors) just link to Contact. -->
        <?php
        $isAccessory = $product['category_slug'] === 'accessories';
$minimumQuantity = max(1, (int) $product['minimum_quantity']);
$maximumQuantity = $isAccessory
  ? min(\App\Services\CartService::MAX_QUANTITY, (int) $product['stock_quantity'])
  : \App\Services\CartService::MAX_QUANTITY;
// A scented product whose every scent has run out of fragrance cannot be added.
$hasAvailableScent = $scents === [] || array_filter(
    $scents,
    static fn (array $scent): bool => ($scent['is_available'] ?? true) !== false
) !== [];
$canAddToCart = $product['purchase_mode'] === 'CART'
  && $maximumQuantity >= $minimumQuantity
  && $hasAvailableScent;
?>
        <?php if ($canAddToCart): ?>
          <form class="product-options" action="<?php echo BASE_URL . 'cart.php'; ?>" method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="slug" value="<?php echo htmlspecialchars((string) $product['slug']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($colors !== []): ?>
              <fieldset class="product-option-group">
                <legend><?php echo site_text('product.choose_color'); ?></legend>
                <div class="product-colors">
                  <?php foreach ($colors as $index => $color): ?>
                    <label>
                      <input type="radio" name="color" value="<?php echo (int) $color['id']; ?>"
                        <?php echo $index === 0 ? 'checked' : ''; ?>
                        <?php echo $color['image_path'] !== null ? 'data-color-image="' . BASE_URL . htmlspecialchars($color['image_path']) . '"' : ''; ?>>
                      <span style="--swatch: <?php echo htmlspecialchars($color['hex_code'] ?? '#e8d8c4'); ?>"></span>
                      <?php echo htmlspecialchars($color['name']); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endif; ?>

            <?php if ($scents !== []): ?>
              <div class="product-option-group">
                <label for="product-scent"><?php echo site_text('product.choose_scent'); ?></label>
                <select id="product-scent" name="scent">
                  <?php // A scent whose fragrance oil has run out stays visible but cannot be chosen.
          ?>
                  <?php foreach ($scents as $scent): ?>
                    <?php $scentAvailable = ($scent['is_available'] ?? true) !== false; ?>
                    <option value="<?php echo (int) $scent['id']; ?>" <?php echo $scentAvailable ? '' : ' disabled'; ?>><?php echo htmlspecialchars($scent['name'] . ($scentAvailable ? '' : ' (' . \App\Services\Locale::text('product.scent_unavailable') . ')')); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div class="product-order-row">
              <div class="product-quantity" aria-label="<?php echo site_text('product.quantity'); ?>">
                <button type="button" data-product-action="decrease" aria-label="Decrease quantity">−</button>
                <input type="number" name="quantity" value="<?php echo $minimumQuantity; ?>"
                  min="<?php echo $minimumQuantity; ?>" max="<?php echo $maximumQuantity; ?>" required data-product-quantity>
                <button type="button" data-product-action="increase" aria-label="Increase quantity">+</button>
              </div>
              <?php if ($isAccessory && !$cartHasAnchor): ?>
                <a href="<?php echo BASE_URL . \App\Services\CatalogueCategory::path('candles'); ?>" class="product-order-button"><?php echo site_text('product.choose_candle'); ?></a>
              <?php else: ?>
                <button type="submit" class="product-order-button"><?php echo site_text('product.add'); ?> <i data-lucide="arrow-right" aria-hidden="true"></i></button>
              <?php endif; ?>
            </div>
          </form>
        <?php else: ?>
          <div class="product-request">
            <?php if ($product['purchase_mode'] === 'CART' && !$hasAvailableScent): ?>
              <p><?php echo site_text('product.all_scents_out'); ?></p>
            <?php else: ?>
              <p><?php echo site_text('product.personalised'); ?></p>
            <?php endif; ?>
            <?php // WHAT: ?product= carries this piece into the contact form.
    ?>
            <?php // WHY:  the request is saved against this product and its category, so nobody has to retype it.
    ?>
            <a href="<?php echo BASE_URL . 'contact.php?product=' . rawurlencode($product['slug']); ?>">
              <?php echo site_text('product.request'); ?>
              <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
            <?php if (isset($contactSettings)): ?>
              <?php
      // WHAT: the documented social-contact options for request-only products.
      // WHY:  some customers prefer to chat directly; channels without a
      //       configured URL (null) are skipped rather than guessed.
      $socialLabels = ['WHATSAPP' => 'WhatsApp', 'INSTAGRAM' => 'Instagram', 'FACEBOOK' => 'Facebook', 'TIKTOK' => 'TikTok'];
                ?>
              <ul class="product-request__socials" aria-label="Or message us directly">
                <?php foreach ($socialLabels as $channel => $label): ?>
                  <?php $url = $contactSettings['channels'][$channel] ?? null; ?>
                  <?php if ($url === null) {
                      continue;
                  } ?>
                  <?php if ($channel === 'WHATSAPP') {
                      $url .= '?text=' . rawurlencode("Hello Hom'As, I'm interested in \"" . $product['name'] . '".');
                  } ?>
                  <li><a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $label; ?></a></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="ui-state-group ui-state-group--compact product-state-group" data-product-states
          aria-live="polite" aria-atomic="true" hidden>
          <div class="ui-state" data-ui-state="loading" hidden>
            <span class="ui-state__spinner" aria-hidden="true"></span>
            <div>
              <h3>Updating your selection…</h3>
              <p>Preparing the chosen option.</p>
            </div>
          </div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" hidden>
            <i data-lucide="x" aria-hidden="true"></i>
            <div>
              <h3>The option could not be displayed.</h3>
              <p data-ui-state-message>Please try another option.</p>
            </div>
          </div>
          <div class="ui-state ui-state--success" data-ui-state="success" hidden>
            <i data-lucide="check" aria-hidden="true"></i>
            <div>
              <h3>Selection updated.</h3>
              <p data-ui-state-message>Your chosen options are ready.</p>
            </div>
          </div>
        </div>

        <div class="product-details">
          <details open>
            <summary><?php echo site_text('product.details_handmade'); ?></summary>
            <p>Small variations are part of the beauty of a piece shaped and finished by hand.</p>
          </details>
          <details>
            <summary><?php echo site_text('product.details_delivery'); ?></summary>
            <p>Preparation timing is confirmed with you before the order is finalised. Delivery is available across Tunisia.</p>
          </details>
          <details>
            <summary><?php echo site_text('product.details_care'); ?></summary>
            <p>Keep the piece away from direct heat and never leave a burning candle unattended.</p>
          </details>
        </div>
      </section>
    </article>
  <?php endif; ?>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>
