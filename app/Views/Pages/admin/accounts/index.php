<?php

declare(strict_types=1);

use App\View\AdminIcons;

$values = $accountFlash['values'] ?: ($profile ?? []); ?>
<header class="admin-page-header">
  <div>
    <h1><?php echo $kind === 'customers' ? 'Edit customer profile' : ($accountList !== null ? 'Team & access' : ($id === null ? 'Add administrator' : 'Edit administrator')); ?></h1>
    <?php if (is_array($profile)): ?><p><strong><?php echo $escape($profile['first_name'] . ' ' . $profile['last_name']); ?></strong></p><?php endif; ?>
    <p>Keep contact details and addresses up to date. Previous orders retain their agreed customer details.</p>
  </div>
  <?php if ($accountList !== null): ?>
  <a class="admin-button" href="<?php echo $escape(BASE_URL . 'admin/accounts.php?id=new'); ?>"><?php echo AdminIcons::render('plus'); ?>Add administrator</a>
  <?php endif; ?>
</header>
<?php if ($accountFlash['errors'] || $accountFlash['success']): $alertTone = $accountFlash['errors'] ? 'error' : 'success';
    $alertTitle = $accountFlash['errors'] ? 'Not saved' : 'Saved';
    $alertMessage = $accountFlash['errors'] ? implode(' ', $accountFlash['errors']) : $accountFlash['success'];
    require VIEW_PATH . '/Components/Admin/alert.php';
endif; ?>
<?php if ($accountList !== null): ?>
  <section class="admin-panel">
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable table">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accountList as $account): ?><tr>
              <td><?php echo $escape($account['first_name'] . ' ' . $account['last_name']); ?></td>
              <td><?php echo $escape($account['email']); ?></td>
              <td><span class="admin-badge admin-badge--<?php echo $account['account_status'] === 'ACTIVE' ? 'success' : 'neutral'; ?>"><?php echo $escape($account['account_status']); ?></span></td>
              <td><?php echo AdminIcons::recordActions(BASE_URL . 'admin/accounts.php?id=' . $account['id'], 'administrators', (int) $account['id'], trim($account['first_name'] . ' ' . $account['last_name'])); ?></td>
            </tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
<?php else: ?>
  <form method="post" class="admin-panel admin-conversion-form"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="kind" value="<?php echo $escape($kind); ?>"><input type="hidden" name="id" value="<?php echo $escape($id ?? 'new'); ?>">
    <input type="hidden" name="profile_version" value="<?php echo $escape(is_string($values['profile_version'] ?? null) ? $values['profile_version'] : ($currentProfileVersion ?? '')); ?>">
    <?php if (isset($values['profile_version']) && $values['profile_version'] !== $currentProfileVersion): ?><p class="admin-alert admin-alert--warning">These entries came from an older profile. Copy any changes you want to keep, then <a href="<?php echo $escape(BASE_URL . 'admin/accounts.php?' . http_build_query(['kind' => $kind,'id' => $id,'return' => $accountReturn ?? ''])); ?>">reload the latest details</a>.</p><?php endif; ?>
    <fieldset><legend>Contact details</legend><div class="admin-form-grid">
      <?php foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'phone_number' => 'Primary phone', 'second_email' => 'Secondary email (optional)', 'second_phone_number' => 'Secondary phone (optional)'] as $key => $label): ?><div class="admin-field"><label for="profile-<?php echo $escape($key); ?>"><?php echo $escape($label); ?></label><input id="profile-<?php echo $escape($key); ?>" name="<?php echo $escape($key); ?>" type="<?php echo str_contains($key, 'email') ? 'email' : (str_contains($key, 'phone') ? 'tel' : 'text'); ?>" value="<?php echo $escape(is_scalar($values[$key] ?? null) ? $values[$key] : ''); ?>" maxlength="<?php echo str_contains($key, 'email') ? 255 : (str_contains($key, 'phone') ? 8 : 100); ?>"<?php echo str_contains($key, 'phone') ? ' inputmode="numeric" pattern="[0-9]{8}"' : ''; ?> <?php echo str_starts_with($key, 'second_') ? '' : ' required'; ?>><?php if (isset($accountFlash['errors'][$key])): ?><p class="admin-field-error"><?php echo $escape($accountFlash['errors'][$key]); ?></p><?php endif; ?></div><?php endforeach; ?>
      <?php if ($id === null): ?><div class="admin-field"><label for="profile-password">Initial password</label><input id="profile-password" type="password" name="password" autocomplete="new-password" required><small>At least eight characters with a letter and number.</small></div><?php endif; ?>
    </div></fieldset>
    <?php if ($kind === 'customers'): ?>
    <h2>Delivery addresses</h2><p class="admin-panel__intro">Keep one to three current addresses. Previous orders keep the address agreed for that order.</p>
    <?php foreach ($addresses as $index => $address): ?><fieldset data-account-address><legend><?php echo (int)$address['is_primary'] === 1 ? 'Primary address' : 'Address ' . ($index + 1); ?></legend><div class="admin-form-grid">
      <?php foreach (['address_label' => 'Address label (optional)','street_address' => 'Street address','delegation' => 'Delegation','governorate' => 'Governorate','postal_code' => 'Postal code'] as $key => $label):$field = 'address-' . $address['id'] . '-' . $key;
          $entered = $values['addresses'][$address['id']][$key] ?? $address[$key]; ?>
      <div class="admin-field<?php echo $key === 'street_address' ? ' admin-field--wide' : ''; ?>"><label for="<?php echo $escape($field); ?>"><?php echo $label; ?></label><input id="<?php echo $escape($field); ?>" name="addresses[<?php echo $escape($address['id']); ?>][<?php echo $key; ?>]" value="<?php echo $escape(is_scalar($entered) ? $entered : ''); ?>" maxlength="<?php echo ['address_label' => 50,'street_address' => 255,'delegation' => 100,'governorate' => 100,'postal_code' => 4][$key]; ?>"<?php echo $key === 'address_label' ? '' : ' required'; ?><?php echo $key === 'postal_code' ? ' inputmode="numeric" pattern="[0-9]{4}"' : ''; ?>></div>
      <?php endforeach; ?></div>
      <?php if (count($addresses) > 1): ?><p><label><input type="checkbox" name="addresses[<?php echo $escape($address['id']); ?>][remove]" value="1"<?php echo ($values['addresses'][$address['id']]['remove'] ?? '') === '1' ? ' checked' : ''; ?>> Remove this address when saving</label></p><?php endif; ?></fieldset>
    <?php endforeach; ?>
    <?php for ($index = 0;$index < max(1, 3 - count($addresses));++$index):$newAddress = is_array($values['new_addresses'][$index] ?? null) ? $values['new_addresses'][$index] : []; ?>
    <details class="admin-optional-settings" data-optional-address<?php echo array_filter($newAddress) ? ' open' : ''; ?>><summary>Add another address</summary><p class="admin-panel__intro">Fill this only when adding an address. Keep at most three; remove an existing address above if replacing one.</p><div class="admin-form-grid">
      <?php foreach (['address_label' => 'Address label (optional)','street_address' => 'Street address','delegation' => 'Delegation','governorate' => 'Governorate','postal_code' => 'Postal code'] as $key => $label):$field = 'new-address-' . $index . '-' . $key; ?>
      <div class="admin-field<?php echo $key === 'street_address' ? ' admin-field--wide' : ''; ?>"><label for="<?php echo $field; ?>"><?php echo $label; ?></label><input id="<?php echo $field; ?>" name="new_addresses[<?php echo $index; ?>][<?php echo $key; ?>]" value="<?php echo $escape(is_scalar($newAddress[$key] ?? null) ? $newAddress[$key] : ''); ?>" maxlength="<?php echo ['address_label' => 50,'street_address' => 255,'delegation' => 100,'governorate' => 100,'postal_code' => 4][$key]; ?>"<?php echo $key === 'postal_code' ? ' inputmode="numeric" pattern="[0-9]{4}"' : ''; ?><?php echo $key !== 'address_label' ? ' data-address-required' : ''; ?>></div>
      <?php endforeach; ?></div></details><?php endfor; ?>
    <?php endif; ?>
    <input type="hidden" name="return" value="<?php echo $escape($accountReturn ?? ''); ?>"><div class="admin-conversion-form__actions"><?php if (!empty($customerReturnUrl)): ?><a class="admin-button admin-button--secondary" href="<?php echo $escape($customerReturnUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to customer</a><?php endif; ?><button class="admin-button"><?php echo AdminIcons::render('save'); ?>Save current profile</button></div>
  </form>
  <?php if ($id !== null): ?><p class="admin-context-links"><a class="admin-button admin-button--ghost" href="<?php echo $escape(BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => $kind, 'id' => $id])); ?>"><?php echo AdminIcons::render('settings'); ?>Manage account access</a></p><?php endif; ?>
<?php endif; ?>
<?php require VIEW_PATH . '/Components/Admin/account-change-history.php'; ?>
