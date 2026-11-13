<?php

declare(strict_types=1);

use App\View\CustomerAccountView;

$account = array_intersect_key($profile, array_flip(['first_name', 'last_name']));
$accountNavActive = 'profile';
$errors = $profileFlash['errors'];
$stale = isset($values['profile_version']) && (!is_string($values['profile_version']) || !hash_equals($profileVersion, $values['profile_version']));
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page account-space container">
  <div class="account-layout">
    <?php require VIEW_PATH . '/Components/customer-account-navigation.php'; ?>
    <div class="account-content">
      <header class="account-welcome"><div><p class="account-eyebrow"><?php echo site_text('nav.your_space'); ?></p><h1><?php echo site_text('account.profile_nav'); ?></h1><p><?php echo site_text('account.profile_intro'); ?></p></div></header>
      <?php if ($profileFlash['success']): ?><div class="account-notice" role="status"><?php echo CustomerAccountView::icon('check'); ?><span><?php echo $escape($profileFlash['success']); ?></span></div><?php endif; ?>
      <?php if ($errors): ?>
        <div class="account-notice account-notice--error" role="alert" tabindex="-1" data-profile-errors><div><strong><?php echo site_text('account.check_fields'); ?></strong><ul>
          <?php foreach ($errors as $field => $message): ?><li><a href="#<?php echo $escape($field === 'address' ? 'addresses' : (in_array($field, ['first_name', 'last_name', 'email', 'phone_number', 'second_email', 'second_phone_number'], true) ? 'profile-' . $field : 'profile-form')); ?>"><?php echo $escape(CustomerAccountView::profileError((string) $field, (string) $message)); ?></a></li><?php endforeach; ?>
        </ul></div></div>
      <?php endif; ?>
      <?php if ($stale): ?><div class="account-notice account-notice--error" role="alert"><p><?php echo site_text('account.error_stale'); ?> <a href="<?php echo $escape(BASE_URL . 'profile.php'); ?>"><?php echo site_text('account.reload_profile'); ?></a></p></div><?php endif; ?>

      <form method="post" id="profile-form" class="account-profile-form" data-account-profile>
        <input type="hidden" name="profile_version" value="<?php echo $escape($values['profile_version'] ?? $profileVersion); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <section class="account-card" aria-labelledby="profile-contact-title">
          <header class="account-section-heading"><div><h2 id="profile-contact-title"><?php echo site_text('account.contact_details'); ?></h2><p><?php echo site_text('account.contact_help'); ?></p></div><?php echo CustomerAccountView::icon('user'); ?></header>
          <div class="account-form-grid">
            <?php foreach (['first_name', 'last_name', 'email', 'phone_number'] as $field): $error = isset($errors[$field]) ? CustomerAccountView::profileError($field, (string) $errors[$field]) : null; ?>
              <div class="account-field"><label for="profile-<?php echo $field; ?>"><?php echo site_text('account.' . $field); ?></label>
                <input id="profile-<?php echo $field; ?>" name="<?php echo $field; ?>" type="<?php echo $field === 'email' ? 'email' : ($field === 'phone_number' ? 'tel' : 'text'); ?>"
                       autocomplete="<?php echo ['first_name' => 'given-name', 'last_name' => 'family-name', 'email' => 'email', 'phone_number' => 'tel'][$field]; ?>"
                       value="<?php echo $escape($values[$field] ?? ''); ?>" maxlength="<?php echo $field === 'email' ? 255 : ($field === 'phone_number' ? 20 : 100); ?>" required
                       <?php echo $error !== null ? 'aria-invalid="true" aria-describedby="error-' . $field . '"' : ''; ?>>
                <?php if ($error !== null): ?><p class="account-field-error" id="error-<?php echo $field; ?>"><?php echo $escape($error); ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <details class="account-optional"<?php echo !empty($values['second_email']) || !empty($values['second_phone_number']) || isset($errors['second_email']) || isset($errors['second_phone_number']) ? ' open' : ''; ?>>
            <summary><?php echo CustomerAccountView::icon('plus'); ?><?php echo site_text('account.optional_contacts'); ?></summary>
            <div class="account-form-grid">
              <?php foreach (['second_email', 'second_phone_number'] as $field): $error = isset($errors[$field]) ? CustomerAccountView::profileError($field, (string) $errors[$field]) : null; ?>
                <div class="account-field"><label for="profile-<?php echo $field; ?>"><?php echo site_text('account.' . $field); ?></label><input id="profile-<?php echo $field; ?>" name="<?php echo $field; ?>" type="<?php echo $field === 'second_email' ? 'email' : 'tel'; ?>" value="<?php echo $escape($values[$field] ?? ''); ?>" maxlength="<?php echo $field === 'second_email' ? 255 : 20; ?>"<?php echo $error !== null ? ' aria-invalid="true" aria-describedby="error-' . $field . '"' : ''; ?>><?php if ($error !== null): ?><p class="account-field-error" id="error-<?php echo $field; ?>"><?php echo $escape($error); ?></p><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          </details>
        </section>

        <section class="account-card" id="addresses" aria-labelledby="profile-addresses-title">
          <header class="account-section-heading"><div><h2 id="profile-addresses-title"><?php echo site_text('account.addresses'); ?></h2><p><?php echo site_text('account.address_help'); ?></p></div><span class="account-count"><?php echo count($addresses); ?> / 3</span></header>
          <?php if (isset($errors['address'])): ?><p class="account-field-error"><?php echo $escape(CustomerAccountView::profileError('address', (string) $errors['address'])); ?></p><?php endif; ?>
          <?php foreach ($addresses as $index => $address):
              $addressPrefix = 'addresses[' . $address['id'] . ']';
              $addressFieldId = 'address-' . $address['id'];
              $addressValues = is_array($values['addresses'][$address['id']] ?? null) ? array_replace($address, $values['addresses'][$address['id']]) : $address;
              $savedAddress = true;
              $selectedPrimary = isset($values['primary_address_id']) ? (string) $values['primary_address_id'] === (string) $address['id'] : (int) $address['is_primary'] === 1;
              ?>
            <fieldset class="account-address-form" data-saved-address>
              <legend><?php echo $escape($address['address_label'] ?: site_text('account.address') . ' ' . ($index + 1)); ?></legend>
              <label class="account-default-choice"><input type="radio" name="primary_address_id" value="<?php echo (int) $address['id']; ?>"<?php echo $selectedPrimary ? ' checked' : ''; ?>><?php echo CustomerAccountView::icon('pin'); ?><span><?php echo site_text('account.default_address'); ?></span></label>
              <?php require VIEW_PATH . '/Components/customer-address-fields.php'; ?>
              <label class="account-checkbox account-remove-choice"><input type="checkbox" data-remove-address name="<?php echo $escape($addressPrefix . '[remove]'); ?>" value="1"<?php echo ($addressValues['remove'] ?? '') === '1' ? ' checked' : ''; ?>><span><?php echo site_text('account.remove_address'); ?></span></label>
            </fieldset>
          <?php endforeach; ?>
          <?php for ($index = 0; $index < max(1, 3 - count($addresses)); ++$index):
              $addressValues = is_array($values['new_addresses'][$index] ?? null) ? $values['new_addresses'][$index] : [];
              $addressPrefix = 'new_addresses[' . $index . ']';
              $addressFieldId = 'new-address-' . $index;
              $savedAddress = false;
              $hasEntries = (bool) array_filter($addressValues);
              ?>
            <details class="account-address-form account-new-address" data-new-address<?php echo $hasEntries ? ' open' : ''; ?><?php echo count($addresses) >= 3 && !$hasEntries ? ' hidden' : ''; ?>>
              <summary><?php echo CustomerAccountView::icon('plus'); ?><?php echo site_text('account.new_address'); ?></summary>
              <?php require VIEW_PATH . '/Components/customer-address-fields.php'; ?>
            </details>
          <?php endfor; ?>
          <p class="account-field-help" data-address-limit<?php echo count($addresses) < 3 ? ' hidden' : ''; ?>><?php echo site_text('account.address_limit'); ?></p>
        </section>

        <div class="account-save-bar"><p data-profile-save-state data-saved-label="<?php echo site_text('account.save_hint'); ?>" data-unsaved-label="<?php echo site_text('account.unsaved'); ?>" aria-live="polite"><?php echo site_text('account.save_hint'); ?></p><button class="account-button" type="submit"><?php echo CustomerAccountView::icon('check'); ?><?php echo site_text('account.save_profile'); ?></button></div>
      </form>
      <section class="account-card account-security" id="security" aria-labelledby="account-security-title"><header class="account-section-heading"><div><h2 id="account-security-title"><?php echo site_text('account.security'); ?></h2><p><?php echo site_text('account.security_help'); ?></p></div><?php echo CustomerAccountView::icon('shield'); ?></header><a class="account-button account-button--secondary" href="<?php echo $escape(BASE_URL . 'forgot-password.php'); ?>"><?php echo site_text('account.reset_password'); ?><?php echo CustomerAccountView::icon('arrow'); ?></a></section>
      <details class="account-card account-danger"><summary><?php echo site_text('account.deactivate'); ?></summary><p><?php echo site_text('account.deactivate_help'); ?></p><form method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><label class="account-checkbox"><input type="checkbox" required name="confirm_deactivate" value="1"><span><?php echo site_text('account.deactivate_confirm'); ?></span></label><button class="account-button account-button--danger" name="action" value="deactivate_account"><?php echo site_text('account.deactivate'); ?></button></form></details>
    </div>
  </div>
</main>
<script src="<?php echo $escape(BASE_URL . 'assets/js/customer-account.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/customer-account.js')); ?>" defer></script>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
