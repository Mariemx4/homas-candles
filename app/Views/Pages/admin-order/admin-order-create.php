<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Order Create; it turns prepared application data into HTML.
 *
 * DATA FLOW: public/admin/orders/create.php prepares the flash values and the
 * orderable catalogue/materials, then the admin layout requires this view.
 *
 * @var array $manualOrderFlash
 * @var array $manualOrderValues
 * @var array{products: array<int, array<string, mixed>>, materials: array<int, array<string, mixed>>} $manualOrderOptions
 * @var object $csrf
 * @var callable $escape
 */


$fieldError = static fn (string $key): string => isset($manualOrderFlash['errors'][$key]) ? (string) $manualOrderFlash['errors'][$key] : '';
$blankItem = ['product_id' => '', 'color_id' => '', 'scent_id' => '', 'product_name' => '', 'quantity' => '1', 'unit_price' => '', 'customization_notes' => '', 'recipe' => []];
$items = isset($manualOrderValues['items']) && is_array($manualOrderValues['items']) && $manualOrderValues['items'] !== []
    ? array_values($manualOrderValues['items'])
    : [$blankItem];
$catalogue = $manualOrderOptions['products'];
$materials = $manualOrderOptions['materials'];
$value = static fn (mixed $item, string $key, string $default = ''): string => is_array($item) && is_string($item[$key] ?? null) ? $item[$key] : $default;

/**
 * WHAT: renders one item block. With $index = null it renders the unnamed
 *       <template> copy; admin-manual-order.js assigns every name.
 * WHY:  the server-rendered items (including redisplayed invalid ones) and the
 *       "Add another item" template must stay identical.
 */
$renderItem = static function (?int $index, mixed $item) use ($escape, $value, $catalogue, $materials, $fieldError): void {
    $name = static fn (string $field): string => $index === null ? '' : ' name="items[' . $index . '][' . $field . ']"';
    $productId = $value($item, 'product_id');
    $product = ctype_digit($productId) ? ($catalogue[(int) $productId] ?? null) : null;
    $recipe = is_array($item) && is_array($item['recipe'] ?? null) ? array_values($item['recipe']) : [];
    if ($recipe === []) {
        // One empty row so the recipe section is ready to fill.
        $recipe = [['material_id' => '', 'quantity' => '']];
    }
    ?>
    <section class="admin-manual-item" data-order-item>
      <div class="admin-manual-item__heading">
        <h3>Item <span data-item-number><?php echo $escape($index === null ? '' : $index + 1); ?></span></h3>
        <button class="admin-button admin-button--secondary" type="button" data-remove-item>Remove</button>
      </div>
      <div class="admin-form-grid">
        <div class="admin-field admin-field--wide">
          <label>Product</label>
          <select data-item-field="product_id"<?php echo $name('product_id'); ?> data-product-select>
            <option value="">Custom item (explicit production recipe)</option>
            <?php foreach ($catalogue as $option): ?>
              <option value="<?php echo $escape($option['id']); ?>"<?php echo $productId === (string) $option['id'] ? ' selected' : ''; ?>><?php echo $escape($option['name'] . ($option['selling_price'] === null ? '' : ' — ' . $option['selling_price'] . ' TND')); ?></option>
            <?php endforeach; ?>
          </select>
          <small>Catalogue items use the product's recipe, options and cost. Custom items need a recipe below.</small>
        </div>
        <div class="admin-field" data-catalogue-only<?php echo $product === null ? ' hidden' : ''; ?>>
          <label>Color</label>
          <select data-item-field="color_id"<?php echo $name('color_id'); ?> data-option-select="colors">
            <option value="">—</option>
            <?php foreach ($product['colors'] ?? [] as $optionId => $optionName): ?>
              <option value="<?php echo $escape($optionId); ?>"<?php echo $value($item, 'color_id') === (string) $optionId ? ' selected' : ''; ?>><?php echo $escape($optionName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field" data-catalogue-only<?php echo $product === null ? ' hidden' : ''; ?>>
          <label>Scent</label>
          <select data-item-field="scent_id"<?php echo $name('scent_id'); ?> data-option-select="scents">
            <option value="">—</option>
            <?php foreach ($product['scents'] ?? [] as $optionId => $optionName): ?>
              <option value="<?php echo $escape($optionId); ?>"<?php echo $value($item, 'scent_id') === (string) $optionId ? ' selected' : ''; ?>><?php echo $escape($optionName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field admin-field--wide" data-custom-only<?php echo $product === null ? '' : ' hidden'; ?>>
          <label>Custom product name</label>
          <input data-item-field="product_name"<?php echo $name('product_name'); ?> maxlength="180" value="<?php echo $escape($value($item, 'product_name')); ?>">
        </div>
        <div class="admin-field">
          <label>Quantity</label>
          <input data-item-field="quantity"<?php echo $name('quantity'); ?> type="number" min="1" max="1000000" step="1" required value="<?php echo $escape($value($item, 'quantity', '1')); ?>">
        </div>
        <div class="admin-field">
          <label>Agreed unit price (TND)</label>
          <input data-item-field="unit_price"<?php echo $name('unit_price'); ?> inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" value="<?php echo $escape($value($item, 'unit_price')); ?>">
          <small>Leave blank on a catalogue item to use its calculated price.</small>
        </div>
        <div class="admin-field admin-field--wide">
          <label>Customization notes</label>
          <textarea data-item-field="customization_notes"<?php echo $name('customization_notes'); ?> maxlength="10000" rows="3"><?php echo $escape($value($item, 'customization_notes')); ?></textarea>
        </div>
      </div>
      <fieldset class="admin-manual-recipe" data-recipe>
        <legend>Production recipe <span data-catalogue-only<?php echo $product === null ? ' hidden' : ''; ?>>(optional — replaces the catalogue recipe)</span></legend>
        <div data-recipe-lines>
          <?php foreach ($recipe as $lineIndex => $line): ?>
            <div class="admin-manual-recipe__line" data-recipe-line>
              <div class="admin-field">
                <label>Material</label>
                <select data-recipe-field="material_id"<?php echo $index === null ? '' : ' name="items[' . $index . '][recipe][' . $lineIndex . '][material_id]"'; ?>>
                  <option value="">—</option>
                  <?php foreach ($materials as $material): ?>
                    <option value="<?php echo $escape($material['id']); ?>"<?php echo $value($line, 'material_id') === (string) $material['id'] ? ' selected' : ''; ?>><?php echo $escape($material['name'] . ' (' . $material['unit'] . ', ' . $material['unit_cost'] . ' TND)'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="admin-field">
                <label>Quantity per unit</label>
                <input data-recipe-field="quantity"<?php echo $index === null ? '' : ' name="items[' . $index . '][recipe][' . $lineIndex . '][quantity]"'; ?> inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" value="<?php echo $escape($value($line, 'quantity')); ?>">
              </div>
              <button class="admin-button admin-button--secondary" type="button" data-remove-recipe-line>Remove</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="admin-button admin-button--secondary" type="button" data-add-recipe-line>Add material</button>
      </fieldset>
      <?php if ($index !== null && $fieldError('items_' . $index) !== ''): ?><small class="admin-field-error"><?php echo $escape($fieldError('items_' . $index)); ?></small><?php endif; ?>
    </section>
    <?php
};

// The browser only needs names and option lists to narrow the color/scent selects.
$catalogueJson = [];
foreach ($catalogue as $option) {
    $catalogueJson[(string) $option['id']] = [
        // COLLECTION IDIOM: array_map pairs each option ID with its name, keeping the server's order.
        'colors' => array_map(null, array_keys($option['colors']), array_values($option['colors'])),
        'scents' => array_map(null, array_keys($option['scents']), array_values($option['scents'])),
    ];
}
?>
<!-- This page only presents values. Validation, totals, and persistence live in the service layer. -->
<header class="admin-page-header admin-page-header--detail">
  <div>
    <h1>Create manual order</h1>
    <p class="admin-page-header__summary">Record a phone, social-media, or in-person order with frozen customer, item, option, cost, and recipe details.</p>
  </div>
  <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Cancel</a>
</header>

<?php if ($manualOrderFlash['errors'] !== []): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Order not created';
    $alertMessage = implode(' ', array_unique(array_values($manualOrderFlash['errors'])));
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<form class="admin-panel admin-conversion-form" action="<?php echo BASE_URL . 'admin/orders/create.php'; ?>" method="post" data-manual-order-form>
  <!-- CSRF binds this state-changing submission to the administrator session. -->
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php $customerPickerId = $manualOrderValues['customer_id'] ?? ''; require __DIR__ . '/../../Components/Admin/order-customer-picker.php'; ?>
    <div class="admin-form-grid">
      <div class="admin-field admin-field--wide">
        <label for="customer-name">Full name</label>
        <input id="customer-name" name="customer_name" maxlength="201" required value="<?php echo $escape($manualOrderValues['customer_name'] ?? ''); ?>">
          <?php if ($fieldError('customer_name') !== ''): ?>
        <small class="admin-field-error">
          <?php echo $escape($fieldError('customer_name')); ?>
        </small><?php endif; ?>
      </div>
      <div class="admin-field">
        <label for="customer-phone">Phone</label>
        <input id="customer-phone" name="customer_phone" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required value="<?php echo $escape($manualOrderValues['customer_phone'] ?? ''); ?>">
          <?php if ($fieldError('customer_phone') !== ''): ?>
        <small class="admin-field-error">
          <?php echo $escape($fieldError('customer_phone')); ?>
        </small><?php endif; ?>
      </div>
      <div class="admin-field">
        <label for="customer-email">Email <span>(optional)</span></label>
        <input id="customer-email" name="customer_email" type="email" maxlength="255" value="<?php echo $escape($manualOrderValues['customer_email'] ?? ''); ?>">
          <?php if ($fieldError('customer_email') !== ''): ?>
          <small class="admin-field-error">
            <?php echo $escape($fieldError('customer_email')); ?>
          </small><?php endif; ?>
        </div>
      <div class="admin-field admin-field--wide">
        <label for="customer-notes">Customer notes</label>
        <textarea id="customer-notes" name="customer_notes" maxlength="10000" rows="4"><?php echo $escape($manualOrderValues['customer_notes'] ?? ''); ?></textarea>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Items</legend>
    <div class="admin-manual-items" data-order-items>
      <?php foreach ($items as $index => $item): ?>
        <?php $renderItem($index, $item); ?>
      <?php endforeach; ?>
    </div>
    <button class="admin-button admin-button--secondary" type="button" data-add-item>Add another item</button>
  </fieldset>

  <fieldset>
    <legend>Fulfilment</legend>
    <div class="admin-form-grid">
      <div class="admin-field">
        <label for="delivery-method">Method</label>
        <select id="delivery-method" name="delivery_method" required>
          <option value="PICKUP"<?php echo ($manualOrderValues['delivery_method'] ?? '') === 'PICKUP' ? ' selected' : ''; ?>>Pickup</option>
          <option value="DELIVERY"<?php echo ($manualOrderValues['delivery_method'] ?? '') === 'DELIVERY' ? ' selected' : ''; ?>>Delivery</option>
        </select>
      </div>
      <div class="admin-field">
        <label for="required-date">Required date</label>
        <input id="required-date" name="required_date" type="date" required value="<?php echo $escape($manualOrderValues['required_date'] ?? ''); ?>">
      </div>
      <div class="admin-field">
        <label for="delivery-fee">Delivery fee (TND)</label>
        <input id="delivery-fee" name="delivery_fee" inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" required value="<?php echo $escape($manualOrderValues['delivery_fee'] ?? '0.000'); ?>">
        <small>Pickup: 0.000. Delivery: at least 9.000.</small>
      </div>
      <div class="admin-field admin-field--wide">
        <label for="street-address">Street address</label>
        <input id="street-address" name="street_address" maxlength="255" value="<?php echo $escape($manualOrderValues['street_address'] ?? ''); ?>">
      </div>
      <div class="admin-field">
        <label for="delegation">Delegation</label>
        <input id="delegation" name="delegation" maxlength="100" value="<?php echo $escape($manualOrderValues['delegation'] ?? ''); ?>">
      </div>
      <div class="admin-field">
        <label for="governorate">Governorate</label>
        <input id="governorate" name="governorate" maxlength="100" value="<?php echo $escape($manualOrderValues['governorate'] ?? ''); ?>">
      </div>
      <div class="admin-field">
        <label for="postal-code">Postal code</label>
        <input id="postal-code" name="postal_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?php echo $escape($manualOrderValues['postal_code'] ?? ''); ?>">
      </div>
    </div>
  </fieldset>

  <fieldset><legend>Internal handoff</legend><div class="admin-field"><label for="administrator-notes">Administrator notes</label><textarea id="administrator-notes" name="administrator_notes" maxlength="10000" rows="5"><?php echo $escape($manualOrderValues['administrator_notes'] ?? ''); ?></textarea><small>Visible only to administrators.</small></div></fieldset>
  <div class="admin-conversion-form__actions"><p>Totals, any required 40% deposit, and each item's production cost are calculated on the server. The order, all items, their recipes, and the initial audit entry are saved together.</p><button class="admin-button" type="submit">Create manual order</button></div>
</form>
<script src="<?php echo BASE_URL . 'assets/js/admin-order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-order-details.js'); ?>" defer></script>

<template id="manual-order-item-template">
  <?php $renderItem(null, $blankItem); ?>
</template>
<?php // JSON_HEX_* flags keep the data inert inside the HTML document. ?>
<script type="application/json" id="manual-order-catalogue"><?php echo json_encode($catalogueJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo BASE_URL . 'assets/js/admin-manual-order.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-manual-order.js'); ?>" defer></script>
