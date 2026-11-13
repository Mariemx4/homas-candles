<?php
declare(strict_types=1);

use App\View\AdminIcons;

// Shared rendering only; services validate all selected IDs and explicit custom values.
$renderRequestOption = static function (string $prefix, string $id, string $kind, array $row, array $choices, bool $customAllowed, string $attribute) use ($escape): void {
    $choice = (string)($row[$kind . '_choice'] ?? '');
    $custom = (string)($row[$kind . '_custom'] ?? $row[$kind . '_preferences'] ?? '');
    if ($choice === '' && $custom !== '') {
        $choice = '__custom__';
    }
    ?>
<div class="admin-field" data-request-option="<?php echo $escape($kind); ?>"><label for="<?php echo $escape($id . '-' . $kind); ?>"><?php echo ucfirst($kind); ?></label>
<select id="<?php echo $escape($id . '-' . $kind); ?>" name="<?php echo $escape($prefix . '[' . $kind . '_choice]'); ?>" <?php echo $attribute; ?>="<?php echo $escape($kind . '_choice'); ?>" data-request-choice="<?php echo $escape($kind); ?>" data-searchable data-placeholder="Search or select <?php echo $kind; ?>...">
<option value=""><?php echo $choices === [] && !$customAllowed ? 'No configured ' . $kind . ' choices' : 'Select ' . $kind . ' (optional for custom definitions)'; ?></option>
<?php foreach ($choices as $optionId => $name): ?><option value="<?php echo $escape($optionId); ?>"<?php echo $choice === (string)$optionId ? ' selected' : ''; ?>><?php echo $escape($name); ?></option><?php endforeach; ?>
<?php if ($customAllowed): ?><option value="__custom__"<?php echo $choice === '__custom__' ? ' selected' : ''; ?>>Other / Custom requested value</option><?php endif; ?></select>
</div>
<div class="admin-field" data-request-custom="<?php echo $escape($kind); ?>"<?php echo $choice === '__custom__' ? '' : ' hidden'; ?>><label for="<?php echo $escape($id . '-' . $kind . '-custom'); ?>">Custom requested <?php echo $kind; ?></label><input id="<?php echo $escape($id . '-' . $kind . '-custom'); ?>" name="<?php echo $escape($prefix . '[' . $kind . '_custom]'); ?>" <?php echo $attribute; ?>="<?php echo $escape($kind . '_custom'); ?>" maxlength="500" value="<?php echo $escape($custom); ?>"<?php echo $choice === '__custom__' ? ' required' : ' disabled'; ?>></div>
<?php };
$renderComponent = static function (int|string $parent, int|string $index, array $row) use ($creationOptions, $escape, $renderRequestOption): void {
    $prefix = 'custom_products[' . $parent . '][components][' . $index . ']';
    $id = 'component-' . $parent . '-' . $index;
    $source = (string)($row['source'] ?? 'PRODUCT');
    $record = $creationOptions['components'][(int)($row['product_id'] ?? 0)] ?? null;
    ?>
<section class="admin-request-component" data-request-component><div class="admin-manual-item__heading"><h4>Component <span data-component-number><?php echo $escape(is_int($index) ? $index + 1 : ''); ?></span></h4><button type="button" class="admin-button admin-button--secondary" data-remove-component><?php echo AdminIcons::render('trash'); ?>Remove component</button></div><div class="admin-form-grid">
<div class="admin-field"><label for="<?php echo $escape($id . '-source'); ?>">Source</label><select id="<?php echo $escape($id . '-source'); ?>" name="<?php echo $escape($prefix . '[source]'); ?>" data-component-field="source" data-component-source><?php foreach (['PRODUCT' => 'Existing Product','ITEM' => 'Existing Item','CUSTOM' => 'Custom / New component'] as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $source === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="admin-field" data-existing-component<?php echo $source === 'CUSTOM' ? ' hidden' : ''; ?>><label for="<?php echo $escape($id . '-record'); ?>" data-component-label><?php echo $source === 'ITEM' ? 'Item' : 'Product'; ?></label><select id="<?php echo $escape($id . '-record'); ?>" name="<?php echo $escape($prefix . '[product_id]'); ?>" data-component-field="product_id" data-component-record data-searchable data-placeholder="Search or select..."<?php echo $source === 'CUSTOM' ? ' disabled' : ' required'; ?>><option value="">Search or select...</option><?php if ($record): ?><option value="<?php echo $escape($record['id']); ?>" selected><?php echo $escape($record['name'] . ' / ' . $record['category_name'] . ' / ' . $record['type_name']); ?></option><?php endif; ?></select></div>
<div class="admin-field" data-new-component<?php echo $source === 'CUSTOM' ? '' : ' hidden'; ?>><label for="<?php echo $escape($id . '-name'); ?>">Custom component name</label><input id="<?php echo $escape($id . '-name'); ?>" name="<?php echo $escape($prefix . '[custom_name]'); ?>" data-component-field="custom_name" maxlength="180" value="<?php echo $escape($row['custom_name'] ?? ''); ?>"<?php echo $source === 'CUSTOM' ? ' required' : ' disabled'; ?>></div>
<div class="admin-field"><label for="<?php echo $escape($id . '-quantity'); ?>">Quantity per requested product</label><input id="<?php echo $escape($id . '-quantity'); ?>" name="<?php echo $escape($prefix . '[quantity]'); ?>" data-component-field="quantity" type="number" min="1" max="1000000" required value="<?php echo $escape($row['quantity'] ?? '1'); ?>"></div>
<?php foreach (['color','scent'] as $kind) {
    $renderRequestOption($prefix, $id, $kind, $row, $source === 'CUSTOM' ? array_column($creationOptions[$kind . 's'], 'name', 'id') : ($record[$kind === 'color' ? 'colors' : 'scents'] ?? []), $source === 'CUSTOM', 'data-component-field');
} ?>
<div class="admin-field admin-field--wide"><label for="<?php echo $escape($id . '-specifications'); ?>">Component specifications</label><textarea id="<?php echo $escape($id . '-specifications'); ?>" name="<?php echo $escape($prefix . '[specifications]'); ?>" data-component-field="specifications" maxlength="10000" rows="2"><?php echo $escape($row['specifications'] ?? ''); ?></textarea></div>
</div></section>
<?php };
$renderCustomProduct = static function (int|string $index, array $product) use ($creationOptions, $escape, $renderRequestOption, $renderComponent): void {
    $prefix = 'custom_products[' . $index . ']';
    $id = 'custom-' . $index;
    ?>
<section class="admin-manual-item" data-custom-product><div class="admin-manual-item__heading"><h3>Requested Product <span data-custom-number><?php echo $escape(is_int($index) ? $index + 1 : ''); ?></span></h3><button type="button" class="admin-button admin-button--secondary" data-remove-custom><?php echo AdminIcons::render('trash'); ?>Remove product</button></div><div class="admin-form-grid">
<?php foreach (['working_name' => 'Working name','category_id' => 'Category','product_type_id' => 'Type','requested_quantity' => 'Requested quantity'] as $key => $label): ?>
<div class="admin-field"><label for="<?php echo $escape($id . '-' . $key); ?>"><?php echo $label; ?></label>
<?php if (in_array($key, ['category_id','product_type_id'], true)): ?><select id="<?php echo $escape($id . '-' . $key); ?>" name="<?php echo $escape($prefix . '[' . $key . ']'); ?>" data-custom-field="<?php echo $key; ?>" required><option value="">Select <?php echo strtolower($label); ?></option><?php foreach ($creationOptions[$key === 'category_id' ? 'categories' : 'types'] as $option): ?><option value="<?php echo $escape($option['id']); ?>"<?php echo (string)($product[$key] ?? '') === (string)$option['id'] ? ' selected' : ''; ?>><?php echo $escape($option['name']); ?></option><?php endforeach; ?></select>
<?php else: ?><input id="<?php echo $escape($id . '-' . $key); ?>" name="<?php echo $escape($prefix . '[' . $key . ']'); ?>" data-custom-field="<?php echo $key; ?>"<?php echo $key === 'requested_quantity' ? ' type="number" min="1" max="1000000"' : ' maxlength="180"'; ?> required value="<?php echo $escape($product[$key] ?? ($key === 'requested_quantity' ? '1' : '')); ?>"><?php endif; ?></div><?php endforeach; ?>
<?php foreach (['color','scent'] as $kind) {
    $renderRequestOption($prefix, $id, $kind, $product, array_column($creationOptions[$kind . 's'], 'name', 'id'), true, 'data-custom-field');
} ?>
</div><h4>Components</h4><p>Reference existing Products or physical Items, or specify a genuinely custom component. Quantities are per requested product.</p><div data-request-components><?php foreach ($product['components'] ?? [] as $child => $component) {
    $renderComponent($index, $child, $component);
} ?></div><button type="button" class="admin-button admin-button--secondary" data-add-component><?php echo AdminIcons::render('plus'); ?>Add component</button>
<div class="admin-field"><label for="<?php echo $escape($id . '-specifications'); ?>">Specifications</label><textarea id="<?php echo $escape($id . '-specifications'); ?>" name="<?php echo $escape($prefix . '[specifications]'); ?>" data-custom-field="specifications" maxlength="10000" rows="4"><?php echo $escape($product['specifications'] ?? ''); ?></textarea></div></section>
<?php }; ?>
<fieldset data-journey-step="1"><legend>Requested products</legend><p id="request-products-error" class="admin-field-error"><?php echo $escape($creationFlash['errors']['custom_products'] ?? ''); ?></p><p>A requested design can combine existing Products, physical Items, and new components.</p><div data-custom-products><?php foreach ((($creationValues['custom_products'] ?? []) ?: [[]]) as $index => $product) {
    $renderCustomProduct($index, $product);
} ?></div><button type="button" class="admin-button admin-button--secondary" data-add-custom><?php echo AdminIcons::render('plus'); ?>Add requested product</button></fieldset>
<template id="custom-product-template"><?php $renderCustomProduct('template', []); ?></template>
<template id="request-component-template"><?php $renderComponent('template', 'template', []); ?></template>
<script type="application/json" id="request-component-catalogue"><?php echo json_encode($creationOptions['components'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/json" id="request-standard-options"><?php echo json_encode(['color' => array_column($creationOptions['colors'], 'name', 'id'),'scent' => array_column($creationOptions['scents'], 'name', 'id')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<p>Up to 50 requested products and 50 components per product, within a 2 MiB brief. Longer specifications can be recorded as separate requests.</p>
<p id="request-payload-message" class="admin-field-error" role="alert" tabindex="-1"></p>
