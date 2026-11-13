<?php

declare(strict_types=1);
$customerModeAllowNew ??= false;
$customerModeContext ??= 'order';
$customerModeIncludeNotes ??= true;
$customerContactErrors ??= [];
$customerMode = $customerContactValues['customer_mode'] ?? (!empty($customerContactValues['customer_id']) ? 'existing' : 'guest');
if (!in_array($customerMode, $customerModeAllowNew ? ['existing', 'guest', 'new'] : ['existing', 'guest'], true)) {
    $customerMode = 'guest';
}
$selectedCustomer = is_array($selectedOrderCustomer ?? null) ? $selectedOrderCustomer : null;
$customerPickerId = $customerMode === 'existing' ? (string) ($customerContactValues['customer_id'] ?? '') : '';
$snapshotHasErrors = array_intersect_key($customerContactErrors, array_flip(['customer_name', 'customer_email', 'customer_phone', 'customer_notes'])) !== [];
$contactValue = static function (string $mode, string $field) use ($customerContactValues, $customerMode, $customerModeAllowNew): string {
    return (string) ($customerContactValues[$mode . '_' . $field] ?? ($mode === $customerMode || !$customerModeAllowNew ? ($customerContactValues[$field] ?? '') : ''));
};
$contactField = static function (string $mode, string $field, string $label, bool $required = false, string $type = 'text', int $maximum = 255, string $pattern = '') use ($contactValue, $customerContactErrors, $customerMode, $escape): void {
    $name = $mode . '_' . $field;
    $error = $mode === $customerMode ? ($customerContactErrors[$field] ?? '') : '';
    ?>
  <div class="admin-field<?php echo $field === 'customer_name' ? ' admin-field--wide' : ''; ?>">
    <label for="<?php echo $escape($name); ?>"><?php echo $escape($label); ?></label>
    <input id="<?php echo $escape($name); ?>" name="<?php echo $escape($name); ?>" type="<?php echo $escape($type); ?>" maxlength="<?php echo $maximum; ?>" value="<?php echo $escape($contactValue($mode, $field)); ?>" <?php echo $required ? ' required' : ''; ?><?php if ($pattern !== ''): ?> pattern="<?php echo $escape($pattern); ?>" inputmode="numeric" <?php endif; ?><?php if ($error !== ''): ?> aria-invalid="true" aria-describedby="<?php echo $escape($name . '-error'); ?>" <?php endif; ?>>
    <?php if ($error !== ''): ?><small class="admin-field-error" id="<?php echo $escape($name . '-error'); ?>"><?php echo $escape($error); ?></small><?php endif; ?>
  </div>
<?php
};
$contactFields = static function (string $mode) use ($contactField, $contactValue, $customerModeAllowNew, $customerModeIncludeNotes, $escape): void {
    ?>
  <div class="admin-form-grid">
    <?php $contactField($mode, 'customer_name', $mode === 'new' ? 'Full name (optional; defaults to the email name)' : 'Full name', $mode !== 'new', 'text', 201); ?>
    <?php $contactField($mode, 'customer_phone', 'Phone', true, 'tel', 8, '[0-9]{8}'); ?>
    <?php $contactField($mode, 'customer_email', $mode === 'new' ? 'Email' : 'Email (optional)', $mode === 'new', 'email'); ?>
    <?php if ($mode === 'new'): $contactField($mode, 'customer_second_phone', 'Second phone (optional)', false, 'tel', 8, '[0-9]{8}');
    endif; ?>
    <?php if ($customerModeAllowNew && $customerModeIncludeNotes): ?>
      <div class="admin-field admin-field--wide"><label for="<?php echo $escape($mode . '_customer_notes'); ?>">Customer / order notes</label><textarea id="<?php echo $escape($mode . '_customer_notes'); ?>" name="<?php echo $escape($mode . '_customer_notes'); ?>" maxlength="10000" rows="3"><?php echo $escape($contactValue($mode, 'customer_notes')); ?></textarea></div>
    <?php endif; ?>
  </div>
<?php
};
?>
<div data-order-customer-modes data-copy-on-select="<?php echo $customerModeAllowNew ? '1' : '0'; ?>" data-initial-customer-mode="<?php echo $escape($customerMode); ?>">
  <fieldset class="admin-customer-mode-choice">
    <legend>Who is this <?php echo $escape($customerModeContext); ?> for?</legend>
    <div class="admin-customer-mode-options">
      <?php foreach (['existing' => 'Existing customer', 'guest' => ($customerModeContext === 'request' ? 'Guest' : 'Guest order'), 'new' => 'Create new customer account'] as $mode => $label): ?>
        <?php if ($mode === 'new' && !$customerModeAllowNew) {
            continue;
        } ?>
        <label class="admin-customer-mode-option"><input type="radio" name="customer_mode" value="<?php echo $escape($mode); ?>" data-customer-mode aria-controls="customer-panel-<?php echo $escape($mode); ?>" <?php echo $customerMode === $mode ? ' checked' : ''; ?>><span><?php echo $escape($label); ?></span></label>
      <?php endforeach; ?>
    </div>
    <?php if (isset($customerContactErrors['customer_mode'])): ?><p class="admin-field-error" role="alert"><?php echo $escape($customerContactErrors['customer_mode']); ?></p><?php endif; ?>
  </fieldset>
  <fieldset id="customer-panel-existing" class="admin-customer-mode-panel" data-customer-mode-panel="existing" <?php echo $customerMode === 'existing' ? '' : ' hidden disabled'; ?>>
    <legend class="admin-visually-hidden">Existing customer</legend>
    <div class="admin-order-customer-picker" data-order-customer-picker data-endpoint="<?php echo BASE_URL . 'admin/orders/customers.php'; ?>">
      <input type="hidden" name="customer_id" value="<?php echo $escape($customerPickerId); ?>" data-customer-id>
      <div data-customer-search-panel<?php echo $selectedCustomer !== null && $customerPickerId !== '' ? ' hidden' : ''; ?>>
        <div class="admin-field"><label for="order-customer-search">Search customer</label>
          <div class="admin-order-customer-picker__search"><input id="order-customer-search" type="search" maxlength="100" placeholder="Search by name, email or phone" data-customer-search><button class="admin-button admin-button--secondary" type="button" data-customer-find>Search</button></div>
        </div>
        <div data-customer-results class="admin-order-customer-picker__results" aria-live="polite"></div>
      </div>
      <div class="admin-selected-customer" data-customer-summary<?php echo $selectedCustomer !== null && $customerPickerId !== '' ? '' : ' hidden'; ?>>
        <div data-customer-selection aria-live="polite"><strong data-customer-summary-name><?php echo $escape($selectedCustomer['customer_name'] ?? ''); ?></strong><span data-customer-summary-email><?php echo $escape($selectedCustomer['customer_email'] ?? ''); ?></span><span data-customer-summary-phone><?php echo $escape($selectedCustomer['customer_phone'] ?? ''); ?></span><small data-customer-summary-address><?php echo $escape(implode(', ', array_filter([$selectedCustomer['street_address'] ?? '', $selectedCustomer['delegation'] ?? '', $selectedCustomer['governorate'] ?? '', $selectedCustomer['postal_code'] ?? '']))); ?></small></div>
        <div class="admin-order-customer-picker__actions"><button class="admin-button admin-button--secondary" type="button" data-customer-change>Change customer</button><button class="admin-button admin-button--secondary" type="button" data-customer-fill><?php echo $customerModeContext === 'request' ? 'Use account contact' : 'Use account contact and address'; ?></button></div>
      </div>
      <?php if (isset($customerContactErrors['customer_id'])): ?><p class="admin-field-error" role="alert"><?php echo $escape($customerContactErrors['customer_id']); ?></p><?php endif; ?>
    </div>
    <details data-customer-snapshot<?php echo $customerPickerId !== '' ? '' : ' hidden'; ?><?php echo $customerMode === 'existing' && $snapshotHasErrors ? ' open' : ''; ?>>
      <summary><?php echo $customerModeContext === 'request' ? 'Request contact snapshot' : 'Order contact snapshot'; ?></summary>
      <p class="admin-customer-mode-help">Contact details for this <?php echo $escape($customerModeContext); ?>. Changing these does not update the customer account.</p>
      <fieldset class="admin-customer-snapshot-fields" data-customer-snapshot-fields<?php echo $customerPickerId !== '' ? '' : ' disabled'; ?>>
        <?php $contactFields('existing'); ?>
      </fieldset>
    </details>
    <noscript>
      <p>Customer search and switching modes need JavaScript. A preselected account can still be used.</p>
    </noscript>
  </fieldset>
  <fieldset id="customer-panel-guest" class="admin-customer-mode-panel" data-customer-mode-panel="guest" <?php echo $customerMode === 'guest' ? '' : ' hidden disabled'; ?>>
    <legend>Guest contact</legend>
    <p class="admin-customer-mode-help">Guest order — no customer account will be created.</p>
    <?php $contactFields('guest'); ?>
  </fieldset>
  <?php if ($customerModeAllowNew): ?>
    <fieldset id="customer-panel-new" class="admin-customer-mode-panel" data-customer-mode-panel="new" <?php echo $customerMode === 'new' ? '' : ' hidden disabled'; ?>>
      <legend>New customer account</legend>
      <p class="admin-customer-mode-help">No password is required. The customer can set one using Forgot password.</p>
      <?php $contactFields('new'); ?>
      <h3 class="admin-customer-address-heading">Account primary address</h3>
      <p class="admin-customer-mode-help">Required for the account<?php echo $customerModeContext === 'order' ? ', including pickup orders' : ''; ?>.</p>
      <div class="admin-form-grid">
        <?php foreach (['street_address' => ['Street address', 255], 'delegation' => ['Delegation', 100], 'governorate' => ['Governorate', 100], 'postal_code' => ['Postal code', 4]] as $field => [$label, $maximum]): ?>
          <?php $name = 'account_' . $field;
            $error = $customerMode === 'new' ? ($customerContactErrors[$name] ?? '') : ''; ?>
          <div class="admin-field<?php echo $field === 'street_address' ? ' admin-field--wide' : ''; ?>"><label for="<?php echo $escape($name); ?>"><?php echo $escape($label); ?></label><input id="<?php echo $escape($name); ?>" name="<?php echo $escape($name); ?>" maxlength="<?php echo $maximum; ?>" required value="<?php echo $escape($customerContactValues[$name] ?? ''); ?>" <?php echo $field === 'postal_code' ? ' pattern="[0-9]{4}" inputmode="numeric"' : ''; ?><?php if ($error !== ''): ?> aria-invalid="true" aria-describedby="<?php echo $escape($name . '-error'); ?>" <?php endif; ?>><?php if ($error !== ''): ?><small class="admin-field-error" id="<?php echo $escape($name . '-error'); ?>"><?php echo $escape($error); ?></small><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>
</div>