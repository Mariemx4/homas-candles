<?php

declare(strict_types=1);

$viewVariables = get_defined_vars();
if (!array_key_exists('product', $viewVariables)
    || !array_key_exists('productLoadError', $viewVariables)
    || !isset($colors, $scents, $galleryImages, $pageTitle)
) {
    throw new RuntimeException('Product view data is required.');
}
unset($viewVariables);

require_once __DIR__ . '/../Layouts/header.php';
?>

<main class="product-page" data-product-available="<?php echo $product !== null ? 'true' : 'false'; ?>"
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
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">Return to the shop<i data-lucide="arrow-right" aria-hidden="true"></i></a>
    </section>
  <?php elseif ($product === null): ?>
    <!-- WHAT: shown when the slug didn't match any active product -->
    <section class="product-unavailable" aria-labelledby="product-unavailable-title">
      <div class="product-unavailable__icon" aria-hidden="true">
        <i data-lucide="sparkles"></i>
      </div>
      <p class="product-eyebrow">Hom'As collection</p>
      <h1 id="product-unavailable-title">This piece is not available yet.</h1>
      <p>This piece may still be in preparation. Explore the collection to discover what is currently available.</p>
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">
        Return to the shop
        <i data-lucide="arrow-right" aria-hidden="true"></i>
      </a>
    </section>
  <?php else: ?>
    <nav class="product-breadcrumb" aria-label="Breadcrumb">
      <a href="<?php echo BASE_URL . 'shop.php'; ?>">Shop</a>
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
          <span><?php echo $product['is_made_to_order'] ? 'Made to order' : 'Ready to order'; ?></span>
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
        <p class="product-price"><?php echo number_format((float) $product['selling_price'], 3); ?> DT</p>
        <p class="product-description"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>

        <!-- WHAT: CART products get color/scent pickers + a quantity stepper;
             REQUEST_ONLY products (bouquets, event favors) just link to Contact. -->
        <?php if ($product['purchase_mode'] === 'CART'): ?>
          <div class="product-options">
            <?php if ($colors !== []): ?>
              <fieldset class="product-option-group">
                <legend>Choose a colour</legend>
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
                <label for="product-scent">Choose a scent</label>
                <select id="product-scent" name="scent">
                  <?php foreach ($scents as $scent): ?>
                    <option value="<?php echo (int) $scent['id']; ?>"><?php echo htmlspecialchars($scent['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div class="product-order-row">
              <div class="product-quantity" aria-label="Quantity">
                <button type="button" data-product-action="decrease" aria-label="Decrease quantity">−</button>
                <input type="number" value="<?php echo max(1, (int) $product['minimum_quantity']); ?>"
                  min="<?php echo max(1, (int) $product['minimum_quantity']); ?>" max="20" data-product-quantity>
                <button type="button" data-product-action="increase" aria-label="Increase quantity">+</button>
              </div>
              <a href="<?php echo BASE_URL . 'contact.php'; ?>" class="product-order-button">
                Order this piece
                <i data-lucide="arrow-right" aria-hidden="true"></i>
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="product-request">
            <p>This piece is personalised and confirmed directly with Hom'As.</p>
            <a href="<?php echo BASE_URL . 'contact.php'; ?>">
              Request this piece
              <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
          </div>
        <?php endif; ?>

        <div class="ui-state-group ui-state-group--compact product-state-group" data-product-states
          aria-live="polite" aria-atomic="true" hidden>
          <div class="ui-state" data-ui-state="loading" hidden>
            <span class="ui-state__spinner" aria-hidden="true"></span>
            <div><h3>Updating your selection…</h3><p>Preparing the chosen option.</p></div>
          </div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" hidden>
            <i data-lucide="x" aria-hidden="true"></i>
            <div><h3>The option could not be displayed.</h3><p data-ui-state-message>Please try another option.</p></div>
          </div>
          <div class="ui-state ui-state--success" data-ui-state="success" hidden>
            <i data-lucide="check" aria-hidden="true"></i>
            <div><h3>Selection updated.</h3><p data-ui-state-message>Your chosen options are ready.</p></div>
          </div>
        </div>

        <div class="product-details">
          <details open>
            <summary>Handmade character</summary>
            <p>Small variations are part of the beauty of a piece shaped and finished by hand.</p>
          </details>
          <details>
            <summary>Preparation and delivery</summary>
            <p>Preparation timing is confirmed with you before the order is finalised. Delivery is available across Tunisia.</p>
          </details>
          <details>
            <summary>Care</summary>
            <p>Keep the piece away from direct heat and never leave a burning candle unattended.</p>
          </details>
        </div>
      </section>
    </article>
  <?php endif; ?>
</main>

<?php
require_once __DIR__ . '/../Layouts/footer.php';
?>
