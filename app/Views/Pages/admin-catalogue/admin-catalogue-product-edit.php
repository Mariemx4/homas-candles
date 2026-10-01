<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Catalogue Product Edit; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\AdminProductService;

$activeCatalogueTab = 'products';
$formatMoney = static fn (mixed $amount): string => $amount === null ? 'Not set' : number_format((float) $amount, 3) . ' TND';
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$productNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$productForm ??= ['errors' => [], 'values' => [], 'success' => null];
$errors = $productForm['errors'];
$values = $productForm['values'];
$detail = $productShow['detail'];
?>  
<?php require __DIR__ . '/../../Components/Admin/catalogue-tabs.php'; ?>

<?php if (!$isNew && !is_array($detail)): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="product-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="product-error-title"><?php echo (int) $productShow['httpStatus'] === 404 ? 'Product not found' : 'Unable to show product'; ?></h1>
      <p><?php echo $escape($productShow['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($selfUrl); ?>">Back to products</a>
    </div>
  </section>
<?php else: ?>
  <?php $product = $detail['product']; ?>
  <header class="admin-order-header">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($selfUrl); ?>">← Back to products</a>
      <?php if (!$isNew): ?><p class="admin-eyebrow">Product record</p><?php endif; ?>
      <div class="admin-order-header__title">
        <h1><?php echo $isNew ? 'New product' : $escape($detail['translations']['en']['name'] ?: ($product['slug'] ?: 'Untitled product')); ?></h1>
        <?php if (!$isNew): ?><span class="admin-badge admin-badge--<?php echo ((int) $product['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $product['is_active'] === 1) ? 'Active' : 'Draft'; ?></span><?php endif; ?>
      </div>
    </div>
    <?php if (!$isNew): ?>
      <div class="admin-detail-actions">
        <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
        <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/inventory/recipe.php?' . http_build_query(['product_id' => $product['id']])); ?>">Edit recipe &amp; cost</a>
        <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
        <form method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <input type="hidden" name="action" value="<?php echo ((int) $product['is_active'] === 1) ? 'deactivate' : 'activate'; ?>">
          <button class="admin-button admin-button--secondary" type="submit"<?php echo ((int) $product['is_active'] !== 1 && !$detail['readiness']['ready']) ? ' disabled' : ''; ?>><?php echo ((int) $product['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?></button>
        </form>
      </div>
    <?php endif; ?>
  </header>

  <?php if (is_string($productNotice['success'])): ?>
    <?php $alertTone = 'success'; $alertTitle = 'Saved'; $alertMessage = $productNotice['success']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>
  <?php foreach (['form', 'main_image', 'color', 'color_image', 'scent', 'component'] as $errorKey): ?>
    <?php if (isset($errors[$errorKey])): ?>
      <?php $alertTone = 'error'; $alertTitle = 'Not saved'; $alertMessage = $errors[$errorKey]; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if (!$isNew): ?>
    <section class="admin-panel admin-readiness-panel" aria-labelledby="readiness-title">
      <header class="admin-panel__header"><div><h2 id="readiness-title"><?php echo $detail['readiness']['ready'] ? 'Ready to activate' : 'Not yet ready to activate'; ?></h2></div></header>
      <ul class="admin-readiness-list">
        <?php foreach ($detail['readiness']['items'] as $check): ?>
          <li class="admin-readiness-item admin-readiness-item--<?php echo $check['met'] ? 'met' : 'unmet'; ?>">
            <span aria-hidden="true"><?php echo $check['met'] ? '✓' : '•'; ?></span>
            <span><?php echo $escape($check['label']); ?><span class="admin-visually-hidden"><?php echo $check['met'] ? ' (met)' : ' (not met)'; ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="core-form-title">
    <header class="admin-panel__header"><h2 id="core-form-title">Core details</h2></header>
    <!-- FORM PURPOSE: Submit status form with POST; the receiving PHP route must validate the values before using them. -->
    <form class="admin-status-form admin-product-core-form" method="post" action="<?php echo $escape($selfUrl); ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="action" value="save_core">
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>"><?php endif; ?>

      <div class="admin-form-grid">
        <div class="admin-field">
          <label for="product-category">Category</label>
          <select id="product-category" name="category_id" required>
            <option value="">Select a category</option>
            <?php foreach ($detail['categories'] as $category): ?>
              <?php $currentCategory = $values['category_id'] ?? ($product['category_id'] ?? null); ?>
              <option value="<?php echo $escape($category['id']); ?>"<?php echo (string) $currentCategory === (string) $category['id'] ? ' selected' : ''; ?>><?php echo $escape($category['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['category_id'])): ?><p class="admin-field-error"><?php echo $escape($errors['category_id']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-type">Product type <span>(not used by candle sets)</span></label>
          <select id="product-type" name="product_type_id">
            <option value="">Select a type</option>
            <?php foreach ($detail['productTypes'] as $type): ?>
              <?php $currentType = $values['product_type_id'] ?? ($product['product_type_id'] ?? null); ?>
              <option value="<?php echo $escape($type['id']); ?>"<?php echo (string) $currentType === (string) $type['id'] ? ' selected' : ''; ?>><?php echo $escape($type['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['product_type_id'])): ?><p class="admin-field-error"><?php echo $escape($errors['product_type_id']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-slug">Slug <span>(required to activate)</span></label>
          <input id="product-slug" name="slug" type="text" maxlength="180" value="<?php echo $escape($values['slug'] ?? ($product['slug'] ?? '')); ?>" placeholder="lowercase-with-hyphens">
          <?php if (isset($errors['slug'])): ?><p class="admin-field-error"><?php echo $escape($errors['slug']); ?></p><?php endif; ?>
        </div>
        <?php
        // WHAT: the selling price is read-only — PricingService calculates it from
        //       recipe and material costs using the documented rules.
        // WHY:  a hand-typed price drifted whenever a material cost changed.
        $pricingQuote = $detail['pricing'] ?? null;
        $pricingRecipeUrl = $product === null ? null : BASE_URL . 'admin/inventory/recipe.php?' . http_build_query(['id' => $product['id']]);
        ?>
        <div class="admin-field admin-price-field" role="group" aria-labelledby="product-price-label">
          <label id="product-price-label">Selling price (TND) <span>(calculated automatically)</span></label>
          <?php if ($product === null): ?>
            <small>Calculated once the product is saved and its recipe or components are added.</small>
          <?php elseif (is_array($pricingQuote) && $pricingQuote['selling_price_millis'] !== null): ?>
            <output class="admin-price-field__value"><?php echo $escape(\App\Services\PricingService::formatMoney($pricingQuote['selling_price_millis'])); ?> TND</output>
            <small><?php echo $escape($pricingQuote['rule_label']); ?> — <?php echo $escape($pricingQuote['explanation']); ?></small>
          <?php else: ?>
            <output class="admin-price-field__value">
              <?php echo $product['selling_price'] !== null ? $escape(number_format((float) $product['selling_price'], 3) . ' TND (previous manual price)') : 'Not calculated yet'; ?>
            </output>
            <p class="admin-field-error"><?php echo $escape(is_array($pricingQuote) ? (string) $pricingQuote['issue'] : 'The price could not be calculated.'); ?></p>
          <?php endif; ?>
          <?php if (is_array($pricingQuote) && $pricingQuote['event_favor_tiers'] !== []): ?>
            <ul class="admin-price-field__tiers">
              <?php foreach ($pricingQuote['event_favor_tiers'] as $tier): ?>
                <li><?php echo $escape($tier['label']); ?> favors: <?php echo $escape(\App\Services\PricingService::formatMoney($tier['unit_price_millis'])); ?> TND each (cost × <?php echo $escape($tier['multiplier']); ?>)</li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($pricingRecipeUrl !== null): ?>
            <small><a href="<?php echo $escape($pricingRecipeUrl); ?>">Edit the recipe that drives this price</a></small>
          <?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-stock">Accessory stock quantity</label>
          <input id="product-stock" name="stock_quantity" type="text" inputmode="numeric" maxlength="10" value="<?php echo $escape($values['stock_quantity'] ?? ($product['stock_quantity'] ?? '')); ?>">
          <?php // The stock shown when the form opened lets the server detect order movements made meanwhile. ?>
          <input type="hidden" name="stock_quantity_original" value="<?php echo $escape($product['stock_quantity'] ?? ''); ?>">
          <small>Orders take accessories (also inside sets) when they become Prepared and return them if cancelled. A change here is recorded as a stock adjustment.</small>
          <?php if (isset($errors['stock_quantity'])): ?><p class="admin-field-error"><?php echo $escape($errors['stock_quantity']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-candle-count">Candle count <span>(bouquets, event favors)</span></label>
          <input id="product-candle-count" name="candle_count" type="text" inputmode="numeric" maxlength="6" value="<?php echo $escape($values['candle_count'] ?? ($product['candle_count'] ?? '')); ?>">
          <?php if (isset($errors['candle_count'])): ?><p class="admin-field-error"><?php echo $escape($errors['candle_count']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-minimum">Minimum order quantity</label>
          <input id="product-minimum" name="minimum_quantity" type="text" inputmode="numeric" maxlength="6" value="<?php echo $escape($values['minimum_quantity'] ?? ($product['minimum_quantity'] ?? '1')); ?>">
          <?php if (isset($errors['minimum_quantity'])): ?><p class="admin-field-error"><?php echo $escape($errors['minimum_quantity']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-purchase-mode">Purchase mode</label>
          <select id="product-purchase-mode" name="purchase_mode">
            <?php foreach (AdminProductService::PURCHASE_MODES as $mode): ?>
              <?php $currentMode = $values['purchase_mode'] ?? ($product['purchase_mode'] ?? 'CART'); ?>
              <option value="<?php echo $escape($mode); ?>"<?php echo $currentMode === $mode ? ' selected' : ''; ?>><?php echo $escape($humanize($mode)); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['purchase_mode'])): ?><p class="admin-field-error"><?php echo $escape($errors['purchase_mode']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="product-reference">Diffuser pricing reference <span>(home diffusers only)</span></label>
          <select id="product-reference" name="pricing_reference_product_id">
            <option value="">Not linked</option>
            <?php foreach (($detail['diffuserCandidates'] ?? []) as $candidate): ?>
              <?php $currentReference = $values['pricing_reference_product_id'] ?? ($product['pricing_reference_product_id'] ?? ''); ?>
              <option value="<?php echo $escape($candidate['id']); ?>"<?php echo (string) $currentReference === (string) $candidate['id'] ? ' selected' : ''; ?>><?php echo $escape($candidate['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['pricing_reference_product_id'])): ?><p class="admin-field-error"><?php echo $escape($errors['pricing_reference_product_id']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field admin-taxonomy-form__checkbox">
          <label><input type="checkbox" name="is_made_to_order" value="1"<?php echo (array_key_exists('is_made_to_order', $values) ? $values['is_made_to_order'] === '1' : ($product['is_made_to_order'] ?? true)) ? ' checked' : ''; ?>> <span>Made to order</span></label>
        </div>
        <div class="admin-field admin-taxonomy-form__checkbox">
          <label><input type="checkbox" name="can_purchase_alone" value="1"<?php echo (array_key_exists('can_purchase_alone', $values) ? $values['can_purchase_alone'] === '1' : ($product['can_purchase_alone'] ?? true)) ? ' checked' : ''; ?>> <span>Can be purchased alone</span></label>
        </div>
      </div>

      <button class="admin-button" type="submit"><?php echo $isNew ? 'Create product' : 'Save core details'; ?></button>
    </form>
  </section>

  <?php if ($isNew): ?>
    <section class="admin-panel admin-panel--table">
      <div class="admin-empty-state" role="status">
        <span class="admin-empty-state__mark" aria-hidden="true">i</span>
        <h3>Save the core details first</h3>
        <p>Translations, photos, colors, scents, and set components unlock once this product has been created.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="admin-panel" aria-labelledby="image-title">
      <header class="admin-panel__header"><h2 id="image-title">Main image</h2></header>
      <div class="admin-image-panel">
        <?php if ($product['main_image_path']): ?>
          <img class="admin-image-preview" src="<?php echo $escape(BASE_URL . $product['main_image_path']); ?>" alt="Current main product image">
        <?php else: ?>
          <p class="admin-muted">No main image uploaded yet.</p>
        <?php endif; ?>
        <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
        <form method="post" action="<?php echo $escape($selfUrl); ?>" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="upload_main_image">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <label for="main-image-file" class="admin-visually-hidden">Choose a main image file</label>
          <input id="main-image-file" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
          <button class="admin-button admin-button--secondary" type="submit">Upload</button>
        </form>
      </div>
      <p class="admin-panel__intro">JPEG, PNG, or WEBP, up to 5&nbsp;MB. A new upload replaces the current image.</p>
    </section>

    <section class="admin-panel" aria-labelledby="translations-title">
      <header class="admin-panel__header"><h2 id="translations-title">Translations</h2></header>
      <p class="admin-panel__intro">Saving a language generates drafts for the other two. Generated text stays private until an administrator approves it. You can also save a translation manually.</p>
      <div class="admin-translation-grid">
        <?php foreach (['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'] as $languageCode => $label): ?>
          <?php $review = $detail['translationReviews'][$languageCode] ?? null; ?>
          <div class="admin-translation-card">
          <p><strong><?php echo $escape($label); ?> published text:</strong> <?php echo trim((string) ($detail['translations'][$languageCode]['name'] ?? '')) !== '' && trim((string) ($detail['translations'][$languageCode]['description'] ?? '')) !== '' ? 'Approved' : 'Missing'; ?></p>
          <?php if ($review !== null): ?>
            <p role="status"><strong>Generated draft:</strong> <?php echo $escape(ucfirst(strtolower((string) $review['status']))); ?><?php echo $review['status'] === 'FAILED' ? ' — ' . $escape($review['failure_message'] ?? 'Generation failed.') : ''; ?></p>
            <?php if ($review['status'] === 'PENDING'): ?>
              <p><strong>Suggested name:</strong> <span dir="auto"><?php echo $escape($review['name']); ?></span></p>
              <p><strong>Suggested description:</strong> <span dir="auto"><?php echo nl2br($escape($review['description'])); ?></span></p>
              <p class="admin-muted">Review both fields before approval. To edit the wording, save it manually in the form below.</p>
              <?php foreach (['approve_translation' => 'Approve draft', 'reject_translation' => 'Reject draft'] as $reviewAction => $reviewLabel): ?>
                <form method="post" action="<?php echo $escape($selfUrl); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                  <input type="hidden" name="action" value="<?php echo $reviewAction; ?>">
                  <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                  <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
                  <button class="admin-button admin-button--secondary" type="submit"><?php echo $reviewLabel; ?></button>
                </form>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endif; ?>
          <!-- FORM PURPOSE: Submit translation form with POST; the receiving PHP route must validate the values before using them. -->
          <form class="admin-translation-form" method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="action" value="save_translation">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
            <h3><?php echo $escape($label); ?> (required to activate)</h3>
            <label for="translation-name-<?php echo $languageCode; ?>">Name</label>
            <input id="translation-name-<?php echo $languageCode; ?>" name="name" type="text" maxlength="180" value="<?php echo $escape($detail['translations'][$languageCode]['name'] ?? ''); ?>">
            <label for="translation-description-<?php echo $languageCode; ?>">Description</label>
            <textarea id="translation-description-<?php echo $languageCode; ?>" name="description" rows="4" maxlength="20000"><?php echo $escape($detail['translations'][$languageCode]['description'] ?? ''); ?></textarea>
            <button class="admin-button admin-button--secondary" type="submit">Save <?php echo $escape($label); ?></button>
          </form>
          <?php if (trim((string) ($detail['translations'][$languageCode]['name'] ?? '')) !== '' && trim((string) ($detail['translations'][$languageCode]['description'] ?? '')) !== ''): ?>
            <form method="post" action="<?php echo $escape($selfUrl); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
              <input type="hidden" name="action" value="generate_translations">
              <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
              <input type="hidden" name="language_code" value="<?php echo $languageCode; ?>">
              <button class="admin-button admin-button--secondary" type="submit">Generate again from <?php echo $escape($label); ?></button>
            </form>
          <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="colors-title">
      <header class="admin-panel__header"><div><h2 id="colors-title">Colors</h2></div></header>
      <?php if ($detail['assignedColors'] === []): ?>
        <?php $emptyTitle = 'No colors assigned'; $emptyMessage = 'Assign at least one color if this product is sold in multiple colors.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
      <?php else: ?>
        <ul class="admin-variant-list">
          <?php foreach ($detail['assignedColors'] as $color): ?>
            <li class="admin-variant-item">
              <?php if ($color['image_path']): ?>
                <img class="admin-variant-item__image" src="<?php echo $escape(BASE_URL . $color['image_path']); ?>" alt="Photo for <?php echo $escape($color['name']); ?>">
              <?php else: ?>
                <span class="admin-variant-item__image admin-variant-item__image--empty" aria-hidden="true"></span>
              <?php endif; ?>
              <div class="admin-variant-item__body">
                <strong><?php echo $escape($color['name']); ?></strong>
                <?php if (!$color['image_path']): ?><span class="admin-badge admin-badge--warning">Photo required to activate</span><?php endif; ?>
                <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
                <form method="post" action="<?php echo $escape($selfUrl); ?>" enctype="multipart/form-data">
                  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                  <input type="hidden" name="action" value="upload_color_image">
                  <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                  <input type="hidden" name="color_id" value="<?php echo $escape($color['id']); ?>">
                  <label class="admin-visually-hidden" for="color-image-<?php echo $escape($color['id']); ?>">Photo for <?php echo $escape($color['name']); ?></label>
                  <input id="color-image-<?php echo $escape($color['id']); ?>" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                  <button class="admin-button admin-button--secondary" type="submit">Upload photo</button>
                </form>
              </div>
              <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
              <form method="post" action="<?php echo $escape($selfUrl); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="action" value="remove_color">
                <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                <input type="hidden" name="color_id" value="<?php echo $escape($color['id']); ?>">
                <button class="admin-button admin-button--secondary" type="submit">Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $assignedColorIds = array_map(static fn (array $color): string => (string) $color['id'], $detail['assignedColors']);
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        $availableColors = array_filter($detail['colors'], static fn (array $color) => !in_array((string) $color['id'], $assignedColorIds, true));
      ?>
      <?php if ($availableColors !== []): ?>
        <!-- FORM PURPOSE: Submit assign form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="admin-assign-form" method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="add_color">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <label class="admin-visually-hidden" for="add-color-select">Color to assign</label>
          <select id="add-color-select" name="color_id">
            <?php foreach ($availableColors as $color): ?>
              <option value="<?php echo $escape($color['id']); ?>"><?php echo $escape($color['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="admin-button" type="submit">Assign color</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-panel" aria-labelledby="scents-title">
      <header class="admin-panel__header"><div><h2 id="scents-title">Scents</h2></div></header>
      <?php if ($detail['assignedScents'] === []): ?>
        <?php $emptyTitle = 'No scents assigned'; $emptyMessage = 'Assign a scent if this product is sold in multiple fragrances.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
      <?php else: ?>
        <ul class="admin-chip-list">
          <?php foreach ($detail['assignedScents'] as $scent): ?>
            <li class="admin-chip">
              <span><?php echo $escape($scent['name']); ?></span>
              <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
              <form method="post" action="<?php echo $escape($selfUrl); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="action" value="remove_scent">
                <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                <input type="hidden" name="scent_id" value="<?php echo $escape($scent['id']); ?>">
                <button type="submit" aria-label="Remove <?php echo $escape($scent['name']); ?>">&times;</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $assignedScentIds = array_map(static fn (array $scent): string => (string) $scent['id'], $detail['assignedScents']);
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        $availableScents = array_filter($detail['scents'], static fn (array $scent) => !in_array((string) $scent['id'], $assignedScentIds, true));
      ?>
      <?php if ($availableScents !== []): ?>
        <!-- FORM PURPOSE: Submit assign form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="admin-assign-form" method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="add_scent">
          <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
          <label class="admin-visually-hidden" for="add-scent-select">Scent to assign</label>
          <select id="add-scent-select" name="scent_id">
            <?php foreach ($availableScents as $scent): ?>
              <option value="<?php echo $escape($scent['id']); ?>"><?php echo $escape($scent['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="admin-button" type="submit">Assign scent</button>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($product['category_slug'] === 'candle-sets'): ?>
      <section class="admin-panel admin-panel--table" aria-labelledby="components-title">
        <header class="admin-panel__header"><div><h2 id="components-title">Set components</h2></div></header>
        <?php if ($detail['components'] === []): ?>
          <?php $emptyTitle = 'No components yet'; $emptyMessage = 'An active candle set needs at least two candle components.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
        <?php else: ?>
          <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable set components table">
            <table class="admin-table">
              <caption class="admin-visually-hidden">Set components</caption>
              <thead><tr><th scope="col">Component</th><th scope="col">Category</th><th scope="col">Quantity</th><th scope="col">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($detail['components'] as $component): ?>
                  <tr>
                    <td><?php echo $escape($component['name']); ?></td>
                    <td><?php echo $escape($component['category_slug'] ? $humanize($component['category_slug']) : 'Uncategorized'); ?></td>
                    <td><?php echo $escape($component['quantity']); ?></td>
                    <td>
                      <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
                      <form method="post" action="<?php echo $escape($selfUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                        <input type="hidden" name="action" value="remove_component">
                        <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
                        <input type="hidden" name="component_product_id" value="<?php echo $escape($component['id']); ?>">
                        <button class="admin-button admin-button--secondary" type="submit">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (($detail['componentCandidates'] ?? []) !== []): ?>
          <!-- FORM PURPOSE: Submit assign form with POST; the receiving PHP route must validate the values before using them. -->
          <form class="admin-assign-form" method="post" action="<?php echo $escape($selfUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="id" value="<?php echo $escape($product['id']); ?>">
            <label class="admin-visually-hidden" for="add-component-select">Component product</label>
            <select id="add-component-select" name="component_product_id">
              <?php foreach ($detail['componentCandidates'] as $candidate): ?>
                <option value="<?php echo $escape($candidate['id']); ?>"><?php echo $escape($candidate['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="admin-visually-hidden" for="add-component-quantity">Quantity</label>
            <input id="add-component-quantity" name="quantity" class="admin-quantity-input" type="text" inputmode="numeric" maxlength="4" value="1">
            <button class="admin-button" type="submit">Add component</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
