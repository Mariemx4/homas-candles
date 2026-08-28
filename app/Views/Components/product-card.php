<?php
/**
 * Reusable catalogue product card.
 *
 * Required $productCard keys:
 * - slug
 * - name
 * - category_name
 * - main_image_path
 * - purchase_mode (CART or REQUEST_ONLY)
 *
 * Optional keys:
 * - selling_price
 * - description
 * - is_made_to_order
 * - stock_quantity
 */
// WHAT: the single card markup used everywhere a product is listed
//       (category pages, shop.php once it's wired up).
// WHY:  one place to change if the card design changes, instead of
//       duplicating this markup in every page that lists products.

// WHAT: refuse to render with missing/malformed data instead of guessing.
// WHY:  a silently blank/broken card is harder to debug than a loud error
//       pointing at exactly which field was missing.
if (!isset($productCard) || !is_array($productCard)) {
  throw new RuntimeException('Product card data is required.');
}

$requiredProductCardFields = [
  'slug',
  'name',
  'category_name',
  'main_image_path',
  'purchase_mode',
];

foreach ($requiredProductCardFields as $field) {
  if (!array_key_exists($field, $productCard)) {
    throw new RuntimeException("Missing product card field: {$field}");
  }
}

$productUrl = BASE_URL . 'product.php?slug=' . rawurlencode((string) $productCard['slug']);
$isRequestOnly = $productCard['purchase_mode'] === 'REQUEST_ONLY';
$isMadeToOrder = !empty($productCard['is_made_to_order']);
$isOutOfStock = array_key_exists('stock_quantity', $productCard)
  && $productCard['stock_quantity'] !== null
  && (int) $productCard['stock_quantity'] <= 0;
$description = trim((string) ($productCard['description'] ?? ''));
?>

<article class="product-card">
  <a href="<?php echo htmlspecialchars($productUrl); ?>" class="product-card__image-link"
    aria-label="View <?php echo htmlspecialchars((string) $productCard['name']); ?>">
    <img src="<?php echo BASE_URL . htmlspecialchars((string) $productCard['main_image_path']); ?>"
      alt="<?php echo htmlspecialchars((string) $productCard['name']); ?>"
      class="product-card__image" loading="lazy" decoding="async">

    <!-- WHAT: "Made to order" beats "Currently unavailable" if both would apply -->
    <div class="product-card__badges">
      <?php if ($isMadeToOrder): ?>
        <span>Made to order</span>
      <?php elseif ($isOutOfStock): ?>
        <span>Currently unavailable</span>
      <?php endif; ?>
    </div>

    <span class="product-card__view-icon" aria-hidden="true">
      <i data-lucide="arrow-up-right"></i>
    </span>
  </a>

  <div class="product-card__body">
    <p class="product-card__category"><?php echo htmlspecialchars((string) $productCard['category_name']); ?></p>
    <h3>
      <a href="<?php echo htmlspecialchars($productUrl); ?>">
        <?php echo htmlspecialchars((string) $productCard['name']); ?>
      </a>
    </h3>

    <?php if ($description !== ''): ?>
      <p class="product-card__description"><?php echo htmlspecialchars($description); ?></p>
    <?php endif; ?>

    <div class="product-card__footer">
      <?php // WHAT: request-only products show no fixed price, they get quoted instead. ?>
      <?php if ($isRequestOnly): ?>
        <p class="product-card__price">Price confirmed on request</p>
      <?php elseif (array_key_exists('selling_price', $productCard) && $productCard['selling_price'] !== null): ?>
        <p class="product-card__price">
          <?php echo number_format((float) $productCard['selling_price'], 3); ?> DT
        </p>
      <?php endif; ?>

      <a href="<?php echo htmlspecialchars($productUrl); ?>" class="product-card__link">
        View piece
        <i data-lucide="arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </div>
</article>
