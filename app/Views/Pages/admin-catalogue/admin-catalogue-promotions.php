<?php
declare(strict_types=1);
$activeCatalogueTab = 'promotions'; require __DIR__ . '/../../Components/Admin/catalogue-tabs.php';
$values = $promotionFlash['values'];
?>
<header class="admin-page-header"><div><h1>Promotion / collaboration codes</h1><p>Configure each code's discount. Applied orders retain their original promotion details.</p></div></header>
<?php if ($promotionFlash['errors'] !== [] || is_string($promotionFlash['success'])): ?>
  <?php $alertTone = $promotionFlash['errors'] !== [] ? 'error' : 'success'; $alertTitle = $alertTone === 'error' ? 'Not saved' : 'Saved'; $alertMessage = $promotionFlash['errors'] !== [] ? implode(' ', $promotionFlash['errors']) : $promotionFlash['success']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>
<section class="admin-panel admin-panel--table"><div class="admin-table-scroll" tabindex="0"><table class="admin-table"><caption>Configured codes</caption><thead><tr><th scope="col">Code</th><th scope="col">Discount</th><th scope="col">Validity</th><th scope="col">Minimum subtotal</th><th scope="col">State</th></tr></thead><tbody>
<?php foreach ($promotions as $promo): ?><tr><td><?php echo $escape($promo['code']); ?></td><td><?php echo $escape($promo['discount_value'] . ($promo['discount_type'] === 'PERCENTAGE' ? '%' : ' TND')); ?></td><td><?php echo $escape(($promo['valid_from'] ?? 'Any start') . ' to ' . ($promo['valid_until'] ?? 'No expiry')); ?></td><td><?php echo $escape($promo['minimum_subtotal']); ?> TND</td><td><?php echo (int) $promo['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td></tr><?php endforeach; ?>
<?php if ($promotions === []): ?><tr><td colspan="5">No promotion codes configured.</td></tr><?php endif; ?>
</tbody></table></div></section>
<form class="admin-panel admin-conversion-form" method="post">
<input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
<h2>Create or update a code</h2><p class="admin-customer-mode-help">Use an existing code to update its configuration or deactivate it.</p><div class="admin-form-grid">
<div class="admin-field"><label for="promo-code">Code</label><input id="promo-code" name="code" required maxlength="40" value="<?php echo $escape($values['code'] ?? ''); ?>"></div>
<div class="admin-field"><label for="promo-type">Discount type</label><select id="promo-type" name="discount_type"><?php foreach (['PERCENTAGE'=>'Percentage','FIXED_TND'=>'Fixed TND'] as $key=>$label): ?><option value="<?php echo $escape($key); ?>"<?php echo ($values['discount_type'] ?? '') === $key ? ' selected' : ''; ?>><?php echo $escape($label); ?></option><?php endforeach; ?></select></div>
<div class="admin-field"><label for="promo-value">Discount value</label><input id="promo-value" name="discount_value" required inputmode="decimal" value="<?php echo $escape($values['discount_value'] ?? ''); ?>"></div>
<div class="admin-field"><label for="promo-minimum">Minimum merchandise subtotal (TND)</label><input id="promo-minimum" name="minimum_subtotal" inputmode="decimal" value="<?php echo $escape($values['minimum_subtotal'] ?? '0.000'); ?>"></div>
<?php foreach (['valid_from'=>'Valid from','valid_until'=>'Valid until'] as $key=>$label): ?><div class="admin-field"><label for="promo-<?php echo $escape($key); ?>"><?php echo $escape($label); ?> (optional)</label><input id="promo-<?php echo $escape($key); ?>" name="<?php echo $escape($key); ?>" type="date" value="<?php echo $escape($values[$key] ?? ''); ?>"></div><?php endforeach; ?>
<div class="admin-field"><label for="promo-state">State</label><select id="promo-state" name="is_active"><option value="1">Active</option><option value="0"<?php echo ($values['is_active'] ?? '') === '0' ? ' selected' : ''; ?>>Inactive</option></select></div>
</div><div class="admin-conversion-form__actions"><button class="admin-button" type="submit">Save promotion</button></div></form>
