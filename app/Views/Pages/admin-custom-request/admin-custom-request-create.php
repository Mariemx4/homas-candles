<?php

declare(strict_types=1);

/** Presentation-only helper: field errors were prepared by the controller/flash layer. */
$fieldError = static fn (string $key): string => isset($creationFlash['errors'][$key]) ? (string) $creationFlash['errors'][$key] : '';
?>
<header class="admin-page-header">
  <div><h1>Add custom request</h1><p class="admin-page-header__summary">Record an enquiry received by phone, social media, or in person before pricing is agreed.</p></div>
  <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/index.php'; ?>">Cancel</a>
</header>

<?php if ($creationFlash['errors'] !== []): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Request not created'; $alertMessage = implode(' ', array_unique(array_values($creationFlash['errors']))); require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<form class="admin-panel admin-conversion-form" method="post" action="<?php echo BASE_URL . 'admin/requests/create.php'; ?>">
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
  <fieldset><legend>Customer</legend>
    <?php $customerModeAllowNew = true; $customerModeContext = 'request'; $customerModeIncludeNotes = false;
      $customerContactValues = $creationValues; $customerContactErrors = $creationFlash['errors'];
      require __DIR__ . '/../../Components/Admin/order-customer-picker.php'; ?>
  </fieldset>
  <fieldset><legend>Request details</legend><div class="admin-form-grid">
    <div class="admin-field"><label for="request-contact-method">Preferred contact</label><select id="request-contact-method" name="preferred_contact_method"><?php foreach (App\Services\CustomRequestService::CONTACT_METHODS as $method): ?><option value="<?php echo $escape($method); ?>"<?php echo ($creationValues['preferred_contact_method'] ?? '') === $method ? ' selected' : ''; ?>><?php echo $escape(ucwords(strtolower($method))); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="request-owner">Assign to</label><select id="request-owner" name="assigned_administrator_id"><?php foreach ($creationOptions['administrators'] as $admin): ?><option value="<?php echo $escape($admin['id']); ?>"<?php echo (string) ($creationValues['assigned_administrator_id'] ?? '') === (string) $admin['id'] ? ' selected' : ''; ?>><?php echo $escape($admin['name']); ?></option><?php endforeach; ?></select></div>
  </div></fieldset>
  <fieldset><legend>Specifications</legend><div class="admin-form-grid">
    <div class="admin-field admin-field--wide"><label for="request-category-create">Category</label><select id="request-category-create" name="category_id" required><option value="">Select a category</option><?php foreach ($creationOptions['categories'] as $category): ?><option value="<?php echo $escape($category['id']); ?>"<?php echo (string) ($creationValues['category_id'] ?? '') === (string) $category['id'] ? ' selected' : ''; ?>><?php echo $escape($category['name']); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="request-budget">Budget (TND)</label><input id="request-budget" name="budget" inputmode="decimal" value="<?php echo $escape($creationValues['budget'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-quantity">Requested quantity</label><input id="request-quantity" name="requested_quantity" type="number" min="1" step="1" value="<?php echo $escape($creationValues['requested_quantity'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-required-date">Required date</label><input id="request-required-date" name="required_date" type="date" value="<?php echo $escape($creationValues['required_date'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-colors">Color preferences</label><input id="request-colors" name="color_preferences" maxlength="500" value="<?php echo $escape($creationValues['color_preferences'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-scents">Scent preferences</label><input id="request-scents" name="scent_preferences" maxlength="500" value="<?php echo $escape($creationValues['scent_preferences'] ?? ''); ?>"></div>
    <div class="admin-field admin-field--wide"><label for="request-packaging">Packaging preferences</label><input id="request-packaging" name="packaging_preferences" maxlength="500" value="<?php echo $escape($creationValues['packaging_preferences'] ?? ''); ?>"></div>
    <div class="admin-field admin-field--wide"><label for="request-message">Customer request</label><textarea id="request-message" name="customer_message" maxlength="10000" rows="6"><?php echo $escape($creationValues['customer_message'] ?? ''); ?></textarea></div>
  </div></fieldset>
  <div class="admin-conversion-form__actions"><p>This creates an enquiry in Submitted status. Discuss and agree the final product before converting it into an order.</p><button class="admin-button" type="submit">Create custom request</button></div>
</form>

<script src="<?php echo BASE_URL . 'assets/js/admin-order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-order-details.js'); ?>" defer></script>
