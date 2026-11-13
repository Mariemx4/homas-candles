<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;
use App\Services\MaterialService;

$humanize = Presentation::humanize(...);
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    try {
        return \App\Services\BusinessClock::displayTimestamp($value, 'd M Y, H:i');
    } catch (Throwable) {
        return 'Not set';
    }
};
$materialNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$materialForm ??= ['errors' => [], 'values' => [], 'success' => null];
$errors = $materialForm['errors'];
$values = $materialForm['values'];
$movementValues = ($values['action'] ?? '') === 'record_movement' ? $values : [];
$movementValue = static fn (string $key): string => is_string($movementValues[$key] ?? null) ? $movementValues[$key] : '';
$detail = $materialShow['detail'];
?>
<?php require VIEW_PATH . '/Components/Admin/return-to-production.php'; ?>
<?php if (!$isNew && !is_array($detail)): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="material-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="material-error-title"><?php echo (int) $materialShow['httpStatus'] === 404 ? 'Material not found' : 'Unable to show material'; ?></h1>
      <p><?php echo $escape($materialShow['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($selfUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to materials</a>
    </div>
  </section>
<?php else: ?>
  <?php $material = $detail['material'];
    $referenced = $detail['referenced']; ?>
<?php if (!empty($material['id'])): ?><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => 'materials','id' => $material['id']])); ?>"><?php echo AdminIcons::render('settings'); ?>Status & availability</a><?php endif; ?>
  <header class="admin-order-header">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($selfUrl); ?>">← Back to materials</a>
      <?php if (!$isNew): ?><p class="admin-eyebrow">Material record</p><?php endif; ?>
      <div class="admin-order-header__title">
        <h1><?php echo $isNew ? 'New material' : $escape($material['name']); ?></h1>
        <?php if (!$isNew): ?><span class="admin-badge admin-badge--<?php echo ((int) $material['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $material['is_active'] === 1) ? 'Active' : 'Inactive'; ?></span><?php endif; ?>
      </div>
    </div>
    <?php if (!$isNew): ?>
      <form method="post" action="<?php echo $escape($selfUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="id" value="<?php echo $escape($material['id']); ?>">
        <input type="hidden" name="action" value="<?php echo ((int) $material['is_active'] === 1) ? 'deactivate' : 'activate'; ?>">
        <button class="admin-button admin-button--secondary" type="submit"><?php echo ((int) $material['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?></button>
      </form>
    <?php endif; ?>
  </header>

  <?php if (is_string($materialNotice['success'])): ?>
    <?php $alertTone = 'success';
      $alertTitle = 'Saved';
      $alertMessage = $materialNotice['success'];
      require VIEW_PATH . '/Components/Admin/alert.php'; ?>
  <?php endif; ?>
  <?php if (isset($errors['form'])): ?>
    <?php $alertTone = 'error';
      $alertTitle = 'Not saved';
      $alertMessage = $errors['form'];
      require VIEW_PATH . '/Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <?php if (!$isNew && $referenced): ?>
    <?php $alertTone = 'info';
      $alertTitle = 'Identity locked';
      $alertMessage = 'This material has recorded movements or recipe usage, so its type, SKU, unit, and stock-tracking setting can no longer change.';
      require VIEW_PATH . '/Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="material-form-title">
    <header class="admin-panel__header"><h2 id="material-form-title">Material details</h2></header>
    <form class="admin-status-form admin-product-core-form" method="post" action="<?php echo $escape($selfUrl); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="action" value="save_core">
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?php echo $escape($material['id']); ?>"><?php endif; ?>
      <?php
          $locked = !$isNew && $referenced;
$tracksStockChecked = $locked ? (bool) $material['tracks_stock'] : ($values !== [] ? ($values['tracks_stock'] ?? '') === '1' : (bool) ($material['tracks_stock'] ?? true));
$minimumStockValue = $values['minimum_stock_level'] ?? ($material['minimum_stock_level'] ?? '');
$minimumStockValue = is_scalar($minimumStockValue) ? (string) $minimumStockValue : '';
?>

      <div class="admin-form-grid">
        <div class="admin-field">
          <label for="material-type-select">Material type</label>
          <select id="material-type-select" name="material_type_id"<?php echo $locked ? ' disabled' : ''; ?> required>
            <?php foreach ($detail['types'] as $type): ?>
              <?php $currentType = $values['material_type_id'] ?? ($material['material_type_id'] ?? null); ?>
              <option value="<?php echo $escape($type['id']); ?>"<?php echo (string) $currentType === (string) $type['id'] ? ' selected' : ''; ?>><?php echo $escape($type['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($locked): ?><input type="hidden" name="material_type_id" value="<?php echo $escape($material['material_type_id']); ?>"><?php endif; ?>
          <?php if (isset($errors['material_type_id'])): ?><p class="admin-field-error"><?php echo $escape($errors['material_type_id']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="material-sku">SKU</label>
          <input id="material-sku" name="sku" type="text" maxlength="100"<?php echo $locked ? ' disabled' : ''; ?> value="<?php echo $escape($values['sku'] ?? ($material['sku'] ?? '')); ?>">
          <?php if ($locked): ?><input type="hidden" name="sku" value="<?php echo $escape($material['sku']); ?>"><?php endif; ?>
          <?php if (isset($errors['sku'])): ?><p class="admin-field-error"><?php echo $escape($errors['sku']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field admin-field--wide">
          <label for="material-name">Name</label>
          <input id="material-name" name="name" type="text" maxlength="180" required value="<?php echo $escape($values['name'] ?? ($material['name'] ?? '')); ?>">
          <?php if (isset($errors['name'])): ?><p class="admin-field-error"><?php echo $escape($errors['name']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="material-unit">Unit</label>
          <input id="material-unit" name="unit" type="text" maxlength="30"<?php echo $locked ? ' disabled' : ''; ?> value="<?php echo $escape($values['unit'] ?? ($material['unit'] ?? '')); ?>" placeholder="g, ml, piece...">
          <?php if ($locked): ?><input type="hidden" name="unit" value="<?php echo $escape($material['unit']); ?>"><?php endif; ?>
          <?php if (isset($errors['unit'])): ?><p class="admin-field-error"><?php echo $escape($errors['unit']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field">
          <label for="material-unit-cost">Unit cost (TND)</label>
          <input id="material-unit-cost" name="unit_cost" type="text" inputmode="decimal" maxlength="12" required value="<?php echo $escape($values['unit_cost'] ?? ($material['unit_cost'] ?? '0.000')); ?>">
          <?php if (isset($errors['unit_cost'])): ?><p class="admin-field-error"><?php echo $escape($errors['unit_cost']); ?></p><?php endif; ?>
        </div>
        <div class="admin-field admin-taxonomy-form__checkbox">
          <label>
            <input id="material-tracks-stock" type="checkbox" name="tracks_stock" value="1"<?php echo $locked ? ' disabled' : ''; ?><?php echo $tracksStockChecked ? ' checked' : ''; ?>>
            <span>Tracks stock quantity</span>
          </label>
          <?php if ($locked): ?><input type="hidden" name="tracks_stock" value="<?php echo (int) $material['tracks_stock']; ?>"><?php endif; ?>
        </div>
        <div class="admin-field" id="material-minimum-field"<?php echo $tracksStockChecked ? '' : ' hidden'; ?>>
          <label for="material-minimum-stock">Minimum stock level (<span id="material-minimum-unit"><?php echo $escape($values['unit'] ?? ($material['unit'] ?? 'selected unit')); ?></span>)</label>
          <input id="material-minimum-stock" name="minimum_stock_level" type="text" inputmode="decimal" maxlength="13"<?php echo $tracksStockChecked ? ' required' : ' disabled'; ?> aria-describedby="material-minimum-help" value="<?php echo $escape($minimumStockValue); ?>">
          <p id="material-minimum-help">Required when tracking stock. Use the same unit as the material, with up to three decimals. Zero is an explicit threshold.</p>
          <?php if (isset($errors['minimum_stock_level'])): ?><p class="admin-field-error" role="alert"><?php echo $escape($errors['minimum_stock_level']); ?></p><?php endif; ?>
        </div>
        <?php if ($isNew): ?>
          <div class="admin-field">
            <label for="material-initial-stock">Opening stock quantity <span>(optional, recorded as a STOCK_IN movement)</span></label>
            <input id="material-initial-stock" name="initial_stock_quantity" type="text" inputmode="decimal" maxlength="12" value="<?php echo $escape($values['initial_stock_quantity'] ?? '0.000'); ?>">
            <?php if (isset($errors['initial_stock_quantity'])): ?><p class="admin-field-error"><?php echo $escape($errors['initial_stock_quantity']); ?></p><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="admin-field admin-taxonomy-form__checkbox">
          <label><input type="checkbox" name="is_active" value="1"<?php echo (array_key_exists('is_active', $values) ? $values['is_active'] === '1' : (bool) ($material['is_active'] ?? true)) ? ' checked' : ''; ?>> <span>Active</span></label>
        </div>
      </div>

      <button class="admin-button" type="submit"><?php echo $isNew ? 'Create material' : 'Save material'; ?></button>
    </form>
    <script src="<?php echo $escape(BASE_URL . 'assets/js/admin/material-form.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/material-form.js')); ?>" defer></script>
  </section>

  <?php if (!$isNew): ?>
    <?php if ((int) $material['tracks_stock'] === 1): ?>
      <section class="admin-panel" aria-labelledby="movement-form-title">
        <header class="admin-panel__header"><h2 id="movement-form-title">Update your stock</h2></header>
        <form class="admin-status-form admin-product-core-form" method="post" action="<?php echo $escape($selfUrl); ?>" data-stock-movement data-stock-current="<?php echo $escape($material['stock_quantity']); ?>" data-stock-unit="<?php echo $escape($material['unit']); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="record_movement">
          <input type="hidden" name="id" value="<?php echo $escape($material['id']); ?>">
          <div class="admin-form-grid">
            <div class="admin-field">
              <label for="movement-type">What happened?</label>
              <select id="movement-type" name="movement_type" required<?php echo isset($errors['movement_type']) ? ' aria-invalid="true" aria-describedby="movement-type-error"' : ''; ?>>
                <?php if ($movementValues !== [] && !in_array($movementValue('movement_type'), MaterialService::MANUAL_MOVEMENT_TYPES, true)): ?><option value="" selected>Choose a valid movement type</option><?php endif; ?>
                <?php foreach (MaterialService::MANUAL_MOVEMENT_TYPES as $type): ?>
                  <option value="<?php echo $escape($type); ?>"<?php echo $movementValue('movement_type') === $type ? ' selected' : ''; ?>><?php echo $escape(['STOCK_IN' => 'Receive stock','ADJUSTMENT_IN' => 'Correct count: increase','ADJUSTMENT_OUT' => 'Correct count: decrease','DAMAGED_OR_WASTED' => 'Record waste or damage'][$type]); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['movement_type'])): ?><p id="movement-type-error" class="admin-field-error"><?php echo $escape($errors['movement_type']); ?></p><?php endif; ?>
            </div>
            <div class="admin-field">
              <label for="movement-quantity">Quantity (<?php echo $escape($material['unit']); ?>)</label>
              <input id="movement-quantity" name="quantity_change" type="text" inputmode="decimal" maxlength="12" placeholder="0.000" value="<?php echo $escape($movementValue('quantity_change')); ?>"<?php echo isset($errors['quantity_change']) ? ' aria-invalid="true" aria-describedby="movement-quantity-error"' : ''; ?>>
              <?php if (isset($errors['quantity_change'])): ?><p id="movement-quantity-error" class="admin-field-error"><?php echo $escape($errors['quantity_change']); ?></p><?php endif; ?>
            </div>
            <div class="admin-field admin-field--wide">
              <label for="movement-note">Note <span>(required for adjustments and waste)</span></label>
              <textarea id="movement-note" name="movement_note" rows="3" maxlength="500"<?php echo isset($errors['movement_note']) ? ' aria-invalid="true" aria-describedby="movement-note-error"' : ''; ?>><?php echo $escape($movementValue('movement_note')); ?></textarea>
              <?php if (isset($errors['movement_note'])): ?><p id="movement-note-error" class="admin-field-error"><?php echo $escape($errors['movement_note']); ?></p><?php endif; ?>
            </div>
          </div>
          <p>Current stock: <strong><?php echo $escape(number_format((float) $material['stock_quantity'], 3)); ?> <?php echo $escape($material['unit']); ?></strong>. Stock can never go negative; the change is rejected server-side if it would.</p>
          <p class="admin-stock-preview" data-stock-preview aria-live="polite">Enter a quantity to preview the new stock level.</p><button class="admin-button" data-stock-submit type="submit"><?php echo AdminIcons::render('save'); ?>Record movement</button>
        </form>
      </section>

      <section class="admin-panel admin-panel--table" aria-labelledby="recent-movements-title">
        <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
        <header class="admin-panel__header"><div><h2 id="recent-movements-title">Recent movements</h2></div><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/inventory/movements.php?' . http_build_query(['material' => $material['id']])); ?>"><?php echo AdminIcons::render('eye'); ?>View full history</a></header>
        <?php if ($detail['movements'] === []): ?>
          <?php $emptyTitle = 'No movements yet';
            $emptyMessage = 'Stock movements for this material will appear here.';
            require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
        <?php else: ?>
          <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable recent movements table">
            <table class="admin-table">
              <caption class="admin-visually-hidden">Recent movements</caption>
              <thead><tr><th scope="col">Type</th><th scope="col">Change</th><th scope="col">Balance</th><th scope="col">By</th><th scope="col">When</th><th scope="col">Note</th></tr></thead>
              <tbody>
                <?php foreach ($detail['movements'] as $movement): ?>
                  <tr>
                    <td><?php echo $escape($humanize($movement['movement_type'])); ?></td>
                    <td><?php echo $escape(number_format((float) $movement['quantity_change'], 3)); ?></td>
                    <td><?php echo $escape(number_format((float) $movement['stock_before'], 3)); ?> → <?php echo $escape(number_format((float) $movement['stock_after'], 3)); ?></td>
                    <td><?php echo $escape($movement['administrator_name'] ?: 'System'); ?></td>
                    <td><time datetime="<?php echo $escape(\App\Services\BusinessClock::displayTimestamp($movement['created_at'])); ?>"><?php echo $escape($formatDate($movement['created_at'])); ?></time></td>
                    <td><?php echo $escape($movement['movement_note'] ?: '—'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
