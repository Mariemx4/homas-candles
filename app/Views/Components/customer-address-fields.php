<?php

declare(strict_types=1);

// Both saved and new addresses use the same field contract and server validation.
// $addressPrefix, $addressValues, $addressFieldId and $savedAddress come from the view.
$addressFields = ['address_label' => 50, 'street_address' => 255, 'delegation' => 100, 'governorate' => 100, 'postal_code' => 4];
?>
<div class="account-form-grid">
  <?php foreach ($addressFields as $field => $limit): $fieldId = $addressFieldId . '-' . $field; ?>
    <div class="account-field<?php echo $field === 'street_address' ? ' account-form-wide' : ''; ?>">
      <label for="<?php echo $escape($fieldId); ?>"><?php echo site_text('account.' . $field); ?></label>
      <input id="<?php echo $escape($fieldId); ?>" name="<?php echo $escape($addressPrefix . '[' . $field . ']'); ?>"
             value="<?php echo $escape($addressValues[$field] ?? ''); ?>" maxlength="<?php echo $limit; ?>"
             autocomplete="<?php echo ['address_label' => 'off', 'street_address' => 'street-address', 'delegation' => 'address-level2', 'governorate' => 'address-level1', 'postal_code' => 'postal-code'][$field]; ?>"
             <?php echo $field === 'postal_code' ? 'inputmode="numeric" pattern="[0-9]{4}"' : ''; ?>
             <?php echo $savedAddress && $field !== 'address_label' ? 'required' : ''; ?>>
    </div>
  <?php endforeach; ?>
</div>
