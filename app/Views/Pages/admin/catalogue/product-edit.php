<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;
use App\Services\AdminProductService;

$activeCatalogueTab = 'products';
$formatMoney = static fn (mixed $amount): string => Presentation::money($amount, 'Not set');
$humanize = Presentation::humanize(...);
$productNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$productForm ??= ['errors' => [], 'values' => [], 'success' => null];
$errors = $productForm['errors'];
$values = $productForm['values'];
$detail = $productShow['detail'];
if ($isNew) {
    require VIEW_PATH . '/Pages/admin/catalogue/product-draft.php';
    return;
}
?>
<?php require VIEW_PATH . '/Components/Admin/return-to-production.php'; ?>

<?php if (!$isNew && !is_array($detail)): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="product-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="product-error-title"><?php echo (int) $productShow['httpStatus'] === 404 ? 'Product not found' : 'Unable to show product'; ?></h1>
      <p><?php echo $escape($productShow['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($selfUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to products</a>
    </div>
  </section>
<?php else: ?>
<?php
  $product = $detail['product'];
  $isCandle = ($product['category_slug'] ?? null) === 'candles';
  $isSet = ($product['category_slug'] ?? null) === 'candle-sets';
  $isActive = (int) $product['is_active'] === 1;
  $readiness = $detail['readiness'];
  $readinessItems = $readiness['items'];
  $metCount = count(array_filter($readinessItems, static fn (array $item): bool => $item['met']));
  $productName = $detail['translations']['en']['name'] ?? '';
  $productName = trim((string) $productName) !== '' ? $productName : ($product['slug'] ?: 'Untitled product');
  $identityValues = ($values['action'] ?? '') === 'save_identity' ? $values : ($detail['translations']['en'] ?? []);
  $candlePrice = $isCandle ? ($detail['candle_pricing'] ?? null) : null;
  $pricingQuote = $detail['pricing'] ?? null;
  $moneyText = static fn (?int $millis): string => $millis === null ? 'Not set' : \App\Services\PricingService::formatMoney($millis) . ' TND';
?>

<header class="ps-head">
  <div>
    <a class="ps-back" href="<?php echo $escape($selfUrl); ?>">&larr; All products</a>
    <div class="ps-title">
      <h1><?php echo $escape($productName); ?></h1>
      <span class="ps-status<?php echo $isActive ? ' is-live' : ($readiness['ready'] ? ' is-ready' : ''); ?>"><?php echo $isActive ? 'Live' : ($readiness['ready'] ? 'Ready to publish' : 'Draft'); ?></span>
    </div>
  </div>
  <div class="ps-head-actions">
    <a class="ps-button ps-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => 'products', 'id' => $product['id']])); ?>">Status and availability</a>
    <a class="ps-button" href="#publish"><?php echo $readiness['ready'] ? 'Publish' : 'Finish setup'; ?></a>
  </div>
</header>

<?php if (is_string($productNotice['success'])): ?>
  <?php $alertTone = 'success';
    $alertTitle = 'Saved';
    $alertMessage = $productNotice['success'];
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>
<?php foreach (['form', 'main_image', 'color', 'color_image', 'scent', 'component', 'recipe', 'quantity_required', 'quantity_prices', 'slug', 'name', 'description'] as $errorKey): ?>
  <?php if (isset($errors[$errorKey])): ?>
    <?php $alertTone = 'error';
      $alertTitle = 'Not saved';
      $alertMessage = $errors[$errorKey];
      require VIEW_PATH . '/Components/Admin/alert.php'; ?>
  <?php endif; ?>
<?php endforeach; ?>

<div class="ps">
  <nav class="ps-steps" aria-label="Product steps">
    <ol>
      <li><a href="#describe"><span class="ps-num">1</span>Describe</a></li>
      <li><a href="#price"><span class="ps-num">2</span>Price</a></li>
      <li><a href="#colours"><span class="ps-num">3</span>Colours and scents</a></li>
      <li><a href="#product-languages"><span class="ps-num">4</span>Translations</a></li>
      <li><a href="#publish"><span class="ps-num">5</span>Publish</a></li>
    </ol>
  </nav>

  <div class="ps-layout">
    <div class="ps-main">

      <section class="ps-card" id="describe" aria-labelledby="describe-title">
        <p class="ps-eyebrow">Step 1</p>
        <h2 id="describe-title">Describe the product</h2>
        <p class="ps-hint">The English name and description are shown in the shop. French and Arabic are checked in step 4.</p>

        <form class="ps-form" method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="save_identity">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <div class="ps-field">
            <label for="primary-product-name">Name in English</label>
            <input id="primary-product-name" name="name" type="text" maxlength="180" value="<?php echo $escape($identityValues['name'] ?? ''); ?>" required>
            <?php if (isset($errors['name'])): ?><p class="ps-error"><?php echo $escape($errors['name']); ?></p><?php endif; ?>
          </div>
          <div class="ps-field">
            <label for="primary-product-description">Description in English</label>
            <textarea id="primary-product-description" name="description" rows="4" maxlength="20000"><?php echo $escape($identityValues['description'] ?? ''); ?></textarea>
            <?php if (isset($errors['description'])): ?><p class="ps-error"><?php echo $escape($errors['description']); ?></p><?php endif; ?>
          </div>
          <div class="ps-actions"><button class="ps-button" type="submit">Save name and description</button></div>
        </form>

        <form class="ps-form" method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="save_core">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <div class="ps-grid">
            <div class="ps-field">
              <label for="product-category">Category</label>
              <select id="product-category" name="category_id" required>
                <option value="">Select a category</option>
                <?php foreach ($detail['categories'] as $category): ?>
                  <?php $currentCategory = $values['category_id'] ?? ($product['category_id'] ?? null); ?>
                  <option value="<?php echo $escape($category['id']); ?>"<?php echo (string) $currentCategory === (string) $category['id'] ? ' selected' : ''; ?>><?php echo $escape($category['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['category_id'])): ?><p class="ps-error"><?php echo $escape($errors['category_id']); ?></p><?php endif; ?>
            </div>
            <?php if (!$isSet): ?>
            <div class="ps-field">
              <label for="product-type">Product type</label>
              <select id="product-type" name="product_type_id">
                <option value="">Select a type</option>
                <?php foreach ($detail['productTypes'] as $type): ?>
                  <?php $currentType = $values['product_type_id'] ?? ($product['product_type_id'] ?? null); ?>
                  <option value="<?php echo $escape($type['id']); ?>"<?php echo (string) $currentType === (string) $type['id'] ? ' selected' : ''; ?>><?php echo $escape($type['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['product_type_id'])): ?><p class="ps-error"><?php echo $escape($errors['product_type_id']); ?></p><?php endif; ?>
            </div>
            <?php else: ?>
              <input type="hidden" name="product_type_id" value="">
            <?php endif; ?>
          </div>

          <details class="ps-more"<?php echo (isset($errors['slug']) || isset($errors['stock_quantity']) || isset($errors['candle_count']) || isset($errors['minimum_quantity']) || isset($errors['purchase_mode']) || isset($errors['pricing_reference_product_id'])) ? ' open' : ''; ?>>
            <summary>Advanced settings</summary>
            <div class="ps-grid">
              <div class="ps-field">
                <label for="product-slug">Link name (web address)</label>
                <input id="product-slug" name="slug" type="text" maxlength="180" value="<?php echo $escape($values['slug'] ?? ($product['slug'] ?? '')); ?>" placeholder="lowercase-with-hyphens">
                <span class="ps-hint">Created from the English name. Change it only if you need another web address.</span>
                <?php if (isset($errors['slug'])): ?><p class="ps-error"><?php echo $escape($errors['slug']); ?></p><?php endif; ?>
              </div>
              <div class="ps-field">
                <label for="product-minimum">Minimum order quantity</label>
                <input id="product-minimum" name="minimum_quantity" type="text" inputmode="numeric" maxlength="6" value="<?php echo $escape($values['minimum_quantity'] ?? ($product['minimum_quantity'] ?? '1')); ?>">
                <?php if (isset($errors['minimum_quantity'])): ?><p class="ps-error"><?php echo $escape($errors['minimum_quantity']); ?></p><?php endif; ?>
              </div>
              <div class="ps-field">
                <label for="product-purchase-mode">Purchase mode</label>
                <select id="product-purchase-mode" name="purchase_mode">
                  <?php foreach (AdminProductService::PURCHASE_MODES as $mode): ?>
                    <?php $currentMode = $values['purchase_mode'] ?? ($product['purchase_mode'] ?? 'CART'); ?>
                    <option value="<?php echo $escape($mode); ?>"<?php echo $currentMode === $mode ? ' selected' : ''; ?>><?php echo $escape($humanize($mode)); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (isset($errors['purchase_mode'])): ?><p class="ps-error"><?php echo $escape($errors['purchase_mode']); ?></p><?php endif; ?>
              </div>
              <?php if (!$isCandle): ?>
              <div class="ps-field">
                <label for="product-stock">Accessory stock quantity</label>
                <input id="product-stock" name="stock_quantity" type="text" inputmode="numeric" maxlength="10" value="<?php echo $escape($values['stock_quantity'] ?? ($product['stock_quantity'] ?? '')); ?>">
                <input type="hidden" name="stock_quantity_original" value="<?php echo $escape($product['stock_quantity'] ?? ''); ?>">
                <span class="ps-hint">Only for accessories. A change is recorded as a stock adjustment.</span>
                <?php if (isset($errors['stock_quantity'])): ?><p class="ps-error"><?php echo $escape($errors['stock_quantity']); ?></p><?php endif; ?>
              </div>
              <div class="ps-field">
                <label for="product-candle-count">Candle count</label>
                <input id="product-candle-count" name="candle_count" type="text" inputmode="numeric" maxlength="6" value="<?php echo $escape($values['candle_count'] ?? ($product['candle_count'] ?? '')); ?>">
                <span class="ps-hint">For bouquets and event favors.</span>
                <?php if (isset($errors['candle_count'])): ?><p class="ps-error"><?php echo $escape($errors['candle_count']); ?></p><?php endif; ?>
              </div>
              <div class="ps-field">
                <label for="product-reference">Diffuser pricing reference</label>
                <select id="product-reference" name="pricing_reference_product_id">
                  <option value="">Not linked</option>
                  <?php foreach (($detail['diffuserCandidates'] ?? []) as $candidate): ?>
                    <?php $currentReference = $values['pricing_reference_product_id'] ?? ($product['pricing_reference_product_id'] ?? ''); ?>
                    <option value="<?php echo $escape($candidate['id']); ?>"<?php echo (string) $currentReference === (string) $candidate['id'] ? ' selected' : ''; ?>><?php echo $escape($candidate['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="ps-hint">Home diffusers only.</span>
              </div>
              <?php else: ?>
                <input type="hidden" name="stock_quantity" value="">
                <input type="hidden" name="stock_quantity_original" value="">
                <input type="hidden" name="candle_count" value="">
                <input type="hidden" name="pricing_reference_product_id" value="">
              <?php endif; ?>
            </div>
            <div class="ps-checks">
              <label><input type="checkbox" name="is_made_to_order" value="1"<?php echo (array_key_exists('is_made_to_order', $values) ? $values['is_made_to_order'] === '1' : ($product['is_made_to_order'] ?? true)) ? ' checked' : ''; ?>> Made to order</label>
              <label><input type="checkbox" name="can_purchase_alone" value="1"<?php echo (array_key_exists('can_purchase_alone', $values) ? $values['can_purchase_alone'] === '1' : ($product['can_purchase_alone'] ?? true)) ? ' checked' : ''; ?>> Can be bought on its own</label>
            </div>
          </details>

          <div class="ps-actions"><button class="ps-button ps-button--secondary" type="submit">Save product details</button></div>
        </form>

        <div class="ps-photo-block" id="product-media">
          <div class="ps-photo-main">
            <?php if (!empty($product['main_image_path'])): ?>
              <img src="<?php echo $escape(BASE_URL . $product['main_image_path']); ?>" alt="Main product photo">
            <?php else: ?>
              <span>No main photo yet</span>
            <?php endif; ?>
          </div>
          <form class="ps-photo-form" method="post" action="<?php echo $escape($selfUrl); ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="action" value="upload_main_image">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <label for="main-image-file">Main photo</label>
            <input id="main-image-file" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
            <span class="ps-hint">JPEG, PNG or WEBP, up to 5 MB. A new upload replaces the current photo.</span>
            <button class="ps-button ps-button--secondary ps-button--small" type="submit">Upload photo</button>
          </form>
        </div>
      </section>

        <?php
  // WHAT: the candle price card. The weight sets the recipe and the price. The suggested price
  //       is shown with one approve button, and the owner can type a price instead.
  //       Every action is its own form, posted with the shared CSRF token.
  $candle = $isCandle ? ($detail['candle_pricing'] ?? null) : null;
  $candleCalc = is_array($candle) ? ($candle['calc'] ?? null) : null;
  $candleMoney = static fn (?int $millis): string => $millis === null ? 'Not set' : \App\Services\PricingService::formatMoney($millis);
  $candleIssues = [
      'no_weight' => 'Enter the finished weight to calculate a price.',
      'no_recipe' => 'This candle has no recipe yet, so it cannot be costed.',
      'no_unit_cost' => 'A material in this recipe has no unit cost.',
  ];
  ?>
  <?php if (is_array($candle)): ?>
  <section class="ps-card" id="price" aria-labelledby="candle-price-title">
    <p class="ps-eyebrow">Price</p>
    <h2 id="candle-price-title">Set the price</h2>
    <p class="ps-hint">We suggest a price from the weight. Approve it, or type your own. The price is never set below the minimum.</p>

    <form class="ps-weight" method="post" action="<?php echo $escape($selfUrl); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
      <input type="hidden" name="action" value="save_candle_weight">
      <div class="ps-field">
        <label for="candle-weight">Finished weight (grams)</label>
        <input id="candle-weight" name="candle_weight_grams" type="text" inputmode="decimal" maxlength="8" value="<?php echo $escape($candle['weight_grams'] ?? ''); ?>" aria-describedby="candle-weight-help">
        <span class="ps-hint" id="candle-weight-help">The recipe is scaled to this weight.</span>
      </div>
      <button class="ps-button ps-button--secondary" type="submit">Save weight</button>
    </form>

    <?php if (!is_array($candleCalc)): ?>
      <p class="ps-alert"><?php echo $escape($candleIssues[$candle['issue']] ?? 'This candle cannot be priced yet.'); ?></p>
    <?php else: ?>
      <div class="ps-price-hero">
        <div>
          <p class="ps-label">Suggested price</p>
          <p class="ps-amount"><?php echo $escape(\App\Services\PricingService::formatMoney($candleCalc['retail_millis'])); ?><small>TND</small></p>
          <p class="ps-muted">Currently <?php echo $escape($candleMoney($candle['current_price_millis'])); ?><?php echo $candle['current_price_millis'] === null ? '' : ' TND'; ?>
            <?php if ($candle['selling_price_source'] === 'automatic_incorrect'): ?> · generated by the old rule, not valid<?php endif; ?></p>
        </div>
        <div class="ps-price-actions">
          <?php if ($candle['current_price_millis'] !== $candleCalc['retail_millis']): ?>
          <form method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <input type="hidden" name="action" value="apply_suggested_price">
            <button class="ps-button" type="submit">Approve <?php echo $escape(\App\Services\PricingService::formatMoney($candleCalc['retail_millis'])); ?> TND</button>
          </form>
          <?php else: ?>
            <p class="ps-muted">The suggested price is already set.</p>
          <?php endif; ?>
          <details class="ps-own">
            <summary>Type my own price</summary>
            <form method="post" action="<?php echo $escape($selfUrl); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
              <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
              <input type="hidden" name="action" value="set_manual_price">
              <div class="ps-field">
                <label for="candle-own-price">Your price (TND)</label>
                <input id="candle-own-price" name="selling_price" type="text" inputmode="decimal" maxlength="12" placeholder="For example 12.000">
                <span class="ps-hint">Lowest allowed: <?php echo $escape($candleMoney($candleCalc['minimum_millis'])); ?> TND</span>
              </div>
              <button class="ps-button ps-button--secondary" type="submit">Use this price</button>
            </form>
          </details>
        </div>
      </div>

      <div class="ps-facts">
        <div><span>Known cost</span><strong><?php echo $escape($candleMoney($candleCalc['known_unit_cost_millis'])); ?></strong></div>
        <div><span>Lowest allowed</span><strong><?php echo $escape($candleMoney($candleCalc['minimum_millis'])); ?></strong></div>
        <div><span>Current price</span><strong><?php echo $escape($candleMoney($candle['current_price_millis'])); ?></strong></div>
      </div>

      <p class="ps-label">Price per candle, by quantity</p>
      <div class="ps-qty">
        <?php foreach ($candle['quantity_table'] as $qtyRow): ?>
          <?php if (!is_array($qtyRow['calc'])) { continue; } ?>
          <div>
            <span><?php echo $escape($qtyRow['quantity']); ?> <?php echo $qtyRow['quantity'] === 1 ? 'candle' : 'candles'; ?></span>
            <strong><?php echo $escape($candleMoney($qtyRow['calc']['unit_price_millis'])); ?></strong>
            <small><?php echo $escape($candleMoney($qtyRow['calc']['order_total_millis'])); ?> in total</small>
          </div>
        <?php endforeach; ?>
      </div>

      <details class="ps-breakdown">
        <summary>How the known cost is worked out</summary>
        <table>
          <tbody>
            <?php foreach ($candleCalc['materials'] as $material): ?>
              <tr>
                <td><?php echo $escape($material['name']); ?> <?php echo $escape(rtrim(rtrim(number_format($material['milligrams'] / 1000, 2, '.', ''), '0'), '.')); ?> <?php echo $material['fragrance'] ? 'ml (estimate)' : 'g'; ?></td>
                <td><?php echo $escape(number_format($material['cost_nano'] / 1000000000, 4, '.', '')); ?> TND</td>
              </tr>
            <?php endforeach; ?>
            <tr><td>Packaging</td><td><?php echo $escape(number_format($candleCalc['packaging_millis'] / 1000, 4, '.', '')); ?> TND</td></tr>
            <tr class="ps-total"><td>Known cost</td><td><?php echo $escape(number_format($candleCalc['known_unit_cost_millis'] / 1000, 4, '.', '')); ?> TND</td></tr>
          </tbody>
        </table>
        <p class="ps-hint">Labour, overhead and wicks are not recorded yet. Fragrance is an estimate until its density is confirmed.</p>
      </details>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (!$isCandle): ?>
  <section class="ps-card" id="price" aria-labelledby="price-other-title">
    <p class="ps-eyebrow">Price</p>
    <h2 id="price-other-title">Selling price</h2>
    <?php if (is_array($pricingQuote) && $pricingQuote['selling_price_millis'] !== null): ?>
      <p class="ps-amount"><?php echo $escape(\App\Services\PricingService::formatMoney($pricingQuote['selling_price_millis'])); ?><small>TND</small></p>
      <p class="ps-hint"><?php echo $escape($pricingQuote['rule_label'] ?? ''); ?><?php echo !empty($pricingQuote['explanation']) ? ' · ' . $escape($pricingQuote['explanation']) : ''; ?></p>
    <?php else: ?>
      <p class="ps-alert"><?php echo $escape(is_array($pricingQuote) ? (string) $pricingQuote['issue'] : 'The price could not be calculated.'); ?></p>
    <?php endif; ?>
    <?php if (is_array($pricingQuote) && $pricingQuote['event_favor_tiers'] !== []): ?>
      <ul class="ps-list">
        <?php foreach ($pricingQuote['event_favor_tiers'] as $tier): ?>
          <li><span><?php echo $escape($tier['label']); ?> favors: <?php echo $escape(\App\Services\PricingService::formatMoney($tier['unit_price_millis'])); ?> TND each</span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

          <section class="ps-card" id="colours" aria-labelledby="colours-title">
      <?php
      // WHAT: each colour has its own photo. Choosing a colour makes it, then its photo is added on its card.
      $assignedColours = [];
      foreach ($detail['assignedColors'] as $assignedColour) {
          $assignedColours[(string) $assignedColour['id']] = $assignedColour;
      }
      $madeCount = count($assignedColours);
      $photoCount = count(array_filter($assignedColours, static fn (array $c): bool => !empty($c['image_path'])));
      ?>
      <p class="ps-eyebrow">Colours</p>
      <h2 id="colours-title">Colours and photos</h2>
      <p class="ps-count"><?php echo $escape($madeCount . ' made, ' . $photoCount . ' with a photo'); ?><?php echo $madeCount > $photoCount ? ' · ' . $escape(($madeCount - $photoCount) . ' still need a photo') : ''; ?></p>
      <p class="ps-hint">Each colour you make needs its own photo. Choose a colour to make it, then add its photo on its card.</p>

      <div class="ps-colour-grid">
        <?php foreach ($detail['colors'] as $color): ?>
          <?php
          $colourId = (string) $color['id'];
          $isMade = isset($assignedColours[$colourId]);
          $photoPath = $isMade ? ($assignedColours[$colourId]['image_path'] ?? null) : null;
          ?>
          <article class="ps-colour<?php echo $isMade ? ' is-made' : ''; ?><?php echo ($isMade && !$photoPath) ? ' needs-photo' : ''; ?>">
            <div class="ps-photo<?php echo $photoPath ? '' : ' is-empty'; ?>"<?php echo $photoPath ? ' style="background-image:url(\'' . $escape(BASE_URL . $photoPath) . '\')"' : ''; ?>>
              <?php if (!$photoPath): ?><span><?php echo $isMade ? 'No photo yet' : 'Not made'; ?></span><?php endif; ?>
            </div>
            <div class="ps-colour-top">
              <strong><?php echo $escape($color['name']); ?></strong>
              <form method="post" action="<?php echo $escape($selfUrl); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                <input type="hidden" name="color_id" value="<?php echo $escape($color['id']); ?>">
                <?php if ($isMade): ?>
                  <input type="hidden" name="action" value="remove_color">
                  <button class="ps-toggle is-on" type="submit" aria-pressed="true" aria-label="Stop making <?php echo $escape($color['name']); ?>">Made</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="add_color">
                  <button class="ps-toggle" type="submit" aria-pressed="false" aria-label="Make <?php echo $escape($color['name']); ?>">Not made</button>
                <?php endif; ?>
              </form>
            </div>
            <?php if ($isMade): ?>
              <form class="ps-photo-form" method="post" action="<?php echo $escape($selfUrl); ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="action" value="upload_color_image">
                <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                <input type="hidden" name="color_id" value="<?php echo $escape($color['id']); ?>">
                <label class="ps-visually-hidden" for="colour-photo-<?php echo $escape($color['id']); ?>">Photo for <?php echo $escape($color['name']); ?></label>
                <input id="colour-photo-<?php echo $escape($color['id']); ?>" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <button class="ps-button ps-button--secondary ps-button--small" type="submit"><?php echo $photoPath ? 'Replace photo' : 'Add photo'; ?></button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ps-card" id="scents" aria-labelledby="scents-title">
      <?php
      $assignedScents = [];
      foreach ($detail['assignedScents'] as $assignedScent) {
          $assignedScents[(string) $assignedScent['id']] = $assignedScent;
      }
      ?>
      <p class="ps-eyebrow">Scents</p>
      <h2 id="scents-title">Scents</h2>
      <p class="ps-hint">Scents don't need photos. Each scent is linked to its fragrance oil, which sets its cost.</p>
      <div class="ps-chips">
        <?php foreach ($detail['scents'] as $scent): ?>
          <?php $isScentOn = isset($assignedScents[(string) $scent['id']]); ?>
          <form method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <input type="hidden" name="scent_id" value="<?php echo $escape($scent['id']); ?>">
            <?php if ($isScentOn): ?>
              <input type="hidden" name="action" value="remove_scent">
              <button class="ps-chip is-on" type="submit" aria-pressed="true"><?php echo $escape($scent['name']); ?></button>
            <?php else: ?>
              <input type="hidden" name="action" value="add_scent">
              <button class="ps-chip" type="submit" aria-pressed="false"><?php echo $escape($scent['name']); ?></button>
            <?php endif; ?>
          </form>
        <?php endforeach; ?>
      </div>
    </section>

      <?php if ($isSet): ?>
      <section class="ps-card" id="components" aria-labelledby="components-title">
        <p class="ps-eyebrow">Set contents</p>
        <h2 id="components-title">Candles in this set</h2>
        <?php if ($detail['components'] === []): ?>
          <p class="ps-hint">An active candle set needs at least two candle components.</p>
        <?php else: ?>
          <ul class="ps-list">
            <?php foreach ($detail['components'] as $component): ?>
              <li>
                <span><?php echo $escape($component['name']); ?> &times; <?php echo $escape($component['quantity']); ?></span>
                <form method="post" action="<?php echo $escape($selfUrl); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                  <input type="hidden" name="action" value="remove_component">
                  <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                  <input type="hidden" name="component_product_id" value="<?php echo $escape($component['id']); ?>">
                  <button class="ps-link-button" type="submit">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (($detail['componentCandidates'] ?? []) !== []): ?>
          <form class="ps-inline-form" method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <div class="ps-field">
              <label for="add-component-select">Add a candle</label>
              <select id="add-component-select" name="component_product_id">
                <?php foreach ($detail['componentCandidates'] as $candidate): ?>
                  <option value="<?php echo $escape($candidate['id']); ?>"><?php echo $escape($candidate['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ps-field">
              <label for="add-component-quantity">Quantity</label>
              <input id="add-component-quantity" name="quantity" type="text" inputmode="numeric" maxlength="4" value="1">
            </div>
            <button class="ps-button ps-button--secondary" type="submit">Add to set</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if (!$isSet): ?>
      <section class="ps-card" id="quantity-pricing" aria-labelledby="quantity-pricing-title">
        <p class="ps-eyebrow">Price by quantity</p>
        <h2 id="quantity-pricing-title">Quantity pricing</h2>
        <?php if ($isCandle): ?><p class="ps-hint">Candles use the automatic quantity price above. Fixed tiers here apply only if you add them.</p><?php endif; ?>
        <?php if (isset($errors['quantity_prices'])): ?><p class="ps-error" role="alert"><?php echo $escape($errors['quantity_prices']); ?></p><?php endif; ?>
        <?php if (($product['price_rule'] ?? '') === 'EVENT_FAVOR_TIER'): ?>
          <p class="ps-hint">Event favors use the cost multipliers shown in the price above.</p>
        <?php else: ?>
          <?php if ($detail['quantityPrices'] === []): ?>
            <p class="ps-hint">No quantity prices. Orders use the base price.</p>
          <?php endif; ?>
          <ul class="ps-list">
            <?php foreach ($detail['quantityPrices'] ?? [] as $tier): ?>
              <li>
                <span><?php echo $escape($tier['minimum_quantity'] . ' to ' . ($tier['maximum_quantity'] ?? 'any higher quantity')); ?>: <?php echo $escape($tier['unit_price']); ?> TND each</span>
                <form method="post" action="<?php echo $escape($selfUrl); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                  <input type="hidden" name="action" value="remove_quantity_price">
                  <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                  <input type="hidden" name="tier_id" value="<?php echo $escape($tier['id']); ?>">
                  <button class="ps-link-button" type="submit">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <form class="ps-inline-form" method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="action" value="add_quantity_price">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <div class="ps-field"><label for="tier-minimum">From quantity</label><input id="tier-minimum" name="minimum_quantity" type="number" min="1" max="1000000" required></div>
            <div class="ps-field"><label for="tier-maximum">To quantity (optional)</label><input id="tier-maximum" name="maximum_quantity" type="number" min="1" max="1000000"></div>
            <div class="ps-field"><label for="tier-price">Price each (TND)</label><input id="tier-price" name="unit_price" inputmode="decimal" required></div>
            <button class="ps-button ps-button--secondary" type="submit">Add quantity price</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if (is_array($catalogueRecipeDetail ?? null)): ?>
      <section class="ps-card" id="recipe" aria-labelledby="catalogue-recipe-title">
        <p class="ps-eyebrow">Recipe and cost</p>
        <h2 id="catalogue-recipe-title">Production recipe</h2>
        <p class="ps-hint">Material quantities per unit. Candles use the standard recipe, scaled to their weight, unless this recipe overrides it.</p>
        <?php $recipeDetail = $catalogueRecipeDetail;
          $recipeProduct = $product;
          $recipeActionUrl = $selfUrl;
          $recipeTypes = ['ACTUAL' => 'Actual recipe per unit'];
          if ($product['category_slug'] === 'home-diffusers') {
              $recipeTypes['CANDLE_EQUIVALENT'] = 'Candle-equivalent recipe';
          }
          require VIEW_PATH . '/Components/Admin/product-recipe-lines.php'; ?>
      </section>
      <?php endif; ?>

      <section class="ps-card" id="product-languages" aria-labelledby="translations-title">
        <p class="ps-eyebrow">Step 4</p>
        <h2 id="translations-title">Translations</h2>
        <p class="ps-hint">Saving a language creates drafts for the others. A draft stays private until you approve it. You can also type a translation yourself.</p>
        <div class="ps-langs">
          <?php foreach (['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'] as $languageCode => $label): ?>
            <?php
              $review = $detail['translationReviews'][$languageCode] ?? null;
              $text = $detail['translations'][$languageCode] ?? [];
              $isPublished = trim((string) ($text['name'] ?? '')) !== '' && trim((string) ($text['description'] ?? '')) !== '';
            ?>
            <article class="ps-lang">
              <header class="ps-lang-head">
                <h3><?php echo $escape($label); ?></h3>
                <span class="ps-tag <?php echo $isPublished ? 'is-ok' : 'is-draft'; ?>"><?php echo $isPublished ? 'Published' : 'Missing'; ?></span>
              </header>

              <?php if ($review !== null && $review['status'] === 'PENDING'): ?>
                <div class="ps-draft" role="status">
                  <p><strong>Draft to review</strong></p>
                  <p><span dir="auto"><?php echo $escape($review['name']); ?></span></p>
                  <p dir="auto"><?php echo nl2br($escape($review['description'])); ?></p>
                  <div class="ps-inline-actions">
                    <?php foreach (['approve_translation' => 'Approve draft', 'reject_translation' => 'Reject draft'] as $reviewAction => $reviewLabel): ?>
                      <form method="post" action="<?php echo $escape($selfUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                        <input type="hidden" name="action" value="<?php echo $reviewAction; ?>">
                        <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                        <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
                        <button class="ps-button<?php echo $reviewAction === 'reject_translation' ? ' ps-button--secondary' : ''; ?>" type="submit"><?php echo $reviewLabel; ?></button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php elseif ($review !== null && $review['status'] === 'FAILED'): ?>
                <p class="ps-error" role="status">Generation failed: <?php echo $escape($review['failure_message'] ?? 'try again'); ?></p>
              <?php endif; ?>

              <form class="ps-form" method="post" action="<?php echo $escape($selfUrl); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="action" value="save_translation">
                <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
                <div class="ps-grid">
                  <div class="ps-field">
                    <label for="translation-name-<?php echo $languageCode; ?>"><?php echo $escape($label); ?> name</label>
                    <input id="translation-name-<?php echo $languageCode; ?>" name="name" type="text" maxlength="180" value="<?php echo $escape($text['name'] ?? ''); ?>"<?php echo $languageCode === 'ar' ? ' dir="rtl"' : ''; ?>>
                  </div>
                  <div class="ps-field">
                    <label for="translation-description-<?php echo $languageCode; ?>"><?php echo $escape($label); ?> description</label>
                    <textarea id="translation-description-<?php echo $languageCode; ?>" name="description" rows="3" maxlength="20000"<?php echo $languageCode === 'ar' ? ' dir="rtl"' : ''; ?>><?php echo $escape($text['description'] ?? ''); ?></textarea>
                  </div>
                </div>
                <div class="ps-actions">
                  <button class="ps-button ps-button--secondary" type="submit">Save <?php echo $escape($label); ?></button>
                </div>
              </form>

              <?php if ($isPublished): ?>
                <form method="post" action="<?php echo $escape($selfUrl); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                  <input type="hidden" name="action" value="generate_translations">
                  <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                  <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
                  <button class="ps-link-button" type="submit">Generate drafts again from <?php echo $escape($label); ?></button>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

          <section class="ps-card" id="publish" aria-labelledby="publish-title">
      <?php
      // WHAT: the publishing checklist. Each missing item links to the place where it is fixed.
      $publishAnchor = static function (string $key): string {
          return match (true) {
              str_starts_with($key, 'translation_') => 'product-languages',
              in_array($key, ['image', 'color_images'], true) => $key === 'image' ? 'product-media' : 'colours',
              in_array($key, ['colors'], true) => 'colours',
              in_array($key, ['scents'], true) => 'scents',
              in_array($key, ['set_candles', 'set_components_active'], true) => 'describe',
              in_array($key, ['price', 'actual_recipe', 'diffuser_pricing'], true) => 'price',
              default => 'describe',
          };
      };
      $readinessItems = $detail['readiness']['items'];
      $isReady = (bool) $detail['readiness']['ready'];
      ?>
      <p class="ps-eyebrow">Publish</p>
      <h2 id="publish-title"><?php echo $isReady ? 'Ready to publish' : 'Not ready yet'; ?></h2>
      <p class="ps-hint">Publishing makes this product available in the shop. Everything below must be complete.</p>
      <ul class="ps-checklist">
        <?php foreach ($readinessItems as $item): ?>
          <li class="<?php echo $item['met'] ? 'is-done' : ''; ?>">
            <span class="ps-check" aria-hidden="true"><?php echo $item['met'] ? '&#10003;' : ''; ?></span>
            <span>
              <?php if ($item['met']): ?>
                <?php echo $escape($item['label']); ?><span class="ps-visually-hidden"> (met)</span>
              <?php else: ?>
                <a href="#<?php echo $escape($publishAnchor($item['key'])); ?>"><?php echo $escape($item['label']); ?></a><span class="ps-visually-hidden"> (not met)</span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="<?php echo $escape($selfUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
        <input type="hidden" name="action" value="<?php echo ((int) $product['is_active'] === 1) ? 'deactivate' : 'activate'; ?>">
        <button class="ps-button<?php echo ((int) $product['is_active'] === 1) ? ' ps-button--secondary' : ''; ?>" type="submit"<?php echo ((int) $product['is_active'] !== 1 && !$isReady) ? ' disabled' : ''; ?>><?php echo ((int) $product['is_active'] === 1) ? 'Unpublish product' : 'Publish product'; ?></button>
      </form>
    </section>

    </div>

    <aside class="ps-summary" aria-label="Summary">
      <?php if (is_array($candlePrice)): ?>
        <p class="ps-eyebrow">Price</p>
        <dl class="ps-summary-rows">
          <div><dt>Selling now</dt><dd><?php echo $escape($moneyText($candlePrice['current_price_millis'])); ?></dd></div>
          <div><dt>Suggested</dt><dd><?php echo $escape($moneyText($candlePrice['suggested_price_millis'])); ?></dd></div>
          <div><dt>Lowest allowed</dt><dd><?php echo $escape($moneyText($candlePrice['calc']['minimum_millis'] ?? null)); ?></dd></div>
        </dl>
        <?php if ($candlePrice['suggested_price_millis'] !== null && $candlePrice['current_price_millis'] !== $candlePrice['suggested_price_millis']): ?>
          <a class="ps-button" href="#price">Review the price</a>
        <?php endif; ?>
      <?php elseif (is_array($pricingQuote) && $pricingQuote['selling_price_millis'] !== null): ?>
        <p class="ps-eyebrow">Price</p>
        <p class="ps-amount-small"><?php echo $escape(\App\Services\PricingService::formatMoney($pricingQuote['selling_price_millis'])); ?> TND</p>
      <?php endif; ?>
      <p class="ps-eyebrow">Publishing</p>
      <p class="ps-summary-count"><?php echo $escape($metCount . ' of ' . count($readinessItems) . ' done'); ?></p>
      <a class="ps-button ps-button--secondary" href="#publish">Go to publish</a>
    </aside>
  </div>
</div>
<?php endif; ?>
