<?php


?>
<?php $setComponents = is_array($components ?? null) ? $components : []; ?>
<?php if ($setComponents !== []): ?>
  <?php
  // WHAT: the "bought separately" total is shown only when every piece has a price.
  // WHY:  a partial sum would understate the value and mislead the customer.
  $separateTotals = array_column($setComponents, 'line_total_millis');
    $separateMillis = in_array(null, $separateTotals, true) ? null : array_sum($separateTotals);
    $formatMillis = static fn (int $millis): string => \App\Services\CheckoutService::formatMoney($millis) . ' DT';
    ?>
  <!-- SECTION PURPOSE: List the component products of a candle set with quantities, prices, and links (documented set rule). -->
  <section class="product-set" aria-labelledby="product-set-title">
    <h2 id="product-set-title"><?php echo site_text('product.set_contents'); ?></h2>
    <ul class="product-set__list">
      <?php foreach ($setComponents as $component): ?>
        <li class="product-set__item">
          <?php if ($component['image'] !== null): ?>
            <img src="<?php echo BASE_URL . htmlspecialchars($component['image']); ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="product-set__placeholder" aria-hidden="true"></span>
          <?php endif; ?>
          <div class="product-set__details">
            <?php // Only available components link; a retired piece would open a "not available" page.?>
            <?php if ($component['slug'] !== null): ?>
              <a href="<?php echo BASE_URL . 'product.php?slug=' . rawurlencode($component['slug']); ?>">
                <?php echo htmlspecialchars($component['name']); ?>
              </a>
            <?php else: ?>
              <strong><?php echo htmlspecialchars($component['name']); ?></strong>
            <?php endif; ?>
            <span>
              <?php echo htmlspecialchars($component['type_name'] ?? $component['category_name']); ?>
              <?php if (!$component['is_available']): ?> · <?php echo site_text('product.set_retired'); ?><?php endif; ?>
            </span>
          </div>
          <div class="product-set__price">
            <span class="product-set__quantity">× <?php echo (int) $component['quantity']; ?></span>
            <?php if ($component['unit_price_millis'] !== null): ?>
              <strong><?php echo htmlspecialchars($formatMillis($component['line_total_millis'])); ?></strong>
              <?php if ($component['quantity'] > 1): ?>
                <span><?php echo htmlspecialchars($formatMillis($component['unit_price_millis'])); ?> <?php echo site_text('product.each'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($separateMillis !== null): ?>
      <p class="product-set__total">
        <?php echo site_text('product.bought_separately'); ?>: <strong><?php echo htmlspecialchars($formatMillis($separateMillis)); ?></strong>
      </p>
    <?php endif; ?>
  </section>
<?php endif; ?>
