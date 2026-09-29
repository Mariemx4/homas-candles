<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Catalogue Taxonomy Edit; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\TaxonomyService;

$activeCatalogueTab = $taxonomyKind;
$singular = TaxonomyService::label($taxonomyKind);
$item = $taxonomyShow['item'];
$taxonomyForm ??= ['errors' => [], 'values' => [], 'success' => null];
$errors = $taxonomyForm['errors'];
$values = $taxonomyForm['values'];
?>
<?php require __DIR__ . '/../../Components/Admin/catalogue-tabs.php'; ?>

<?php if ($isNew === false && $item === null): ?>
  <!-- SECTION PURPOSE: Group the related page content content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-detail-error" aria-labelledby="taxonomy-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="taxonomy-error-title"><?php echo (int) $taxonomyShow['httpStatus'] === 404 ? 'Not found' : 'Unable to show record'; ?></h1>
      <p><?php echo $escape($taxonomyShow['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($selfUrl); ?>">Back to <?php echo $escape(strtolower(TaxonomyService::label($taxonomyKind, true))); ?></a>
    </div>
  </section>
<?php else: ?>
  <?php
    $slugValue = $values['slug'] ?? ($item['slug'] ?? '');
    $isActiveValue = array_key_exists('is_active', $values) ? $values['is_active'] === '1' : ($item['is_active'] ?? true);
    $namesByLanguage = [];
    foreach (['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'] as $languageCode => $label) {
        $namesByLanguage[$languageCode] = $values['name_' . $languageCode] ?? ($item['translations'][$languageCode] ?? '');
    }
  ?>
  <header class="admin-page-header">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($selfUrl); ?>">← Back to <?php echo $escape(strtolower(TaxonomyService::label($taxonomyKind, true))); ?></a>
      <?php if (!$isNew): ?><p class="admin-eyebrow">Edit</p><?php endif; ?>
      <h1><?php echo $isNew ? 'New ' . $escape(strtolower($singular)) : $escape($item['translations']['en'] ?: $item['slug']); ?></h1>
    </div>
    <?php if (!$isNew): ?>
      <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
      <form method="post" action="<?php echo $escape($selfUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="form_action" value="toggle">
        <input type="hidden" name="id" value="<?php echo $escape($item['id']); ?>">
        <input type="hidden" name="active" value="<?php echo $item['is_active'] ? '0' : '1'; ?>">
        <button class="admin-button admin-button--secondary" type="submit"><?php echo $item['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
      </form>
    <?php endif; ?>
  </header>

  <?php if (is_string($taxonomyNotice['success'] ?? null)): ?>
    <?php $alertTone = 'success'; $alertTitle = 'Saved'; $alertMessage = $taxonomyNotice['success']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>
  <?php if (isset($errors['form'])): ?>
    <?php $alertTone = 'error'; $alertTitle = 'Not saved'; $alertMessage = $errors['form']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <!-- SECTION PURPOSE: Group the details content, states, and controls as one accessible page region. -->
  <section class="admin-panel" aria-labelledby="taxonomy-form-title">
    <header class="admin-panel__header"><h2 id="taxonomy-form-title"><?php echo $escape($singular); ?> details</h2></header>
    <!-- FORM PURPOSE: Submit status form with POST; the receiving PHP route must validate the values before using them. -->
    <form class="admin-status-form admin-taxonomy-form" method="post" action="<?php echo $escape($selfUrl); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="form_action" value="save">
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?php echo $escape($item['id']); ?>"><?php endif; ?>

      <label for="taxonomy-slug">Slug</label>
      <input id="taxonomy-slug" name="slug" type="text" maxlength="150" required value="<?php echo $escape($slugValue); ?>" placeholder="lowercase-with-hyphens">
      <?php if (isset($errors['slug'])): ?><p class="admin-field-error"><?php echo $escape($errors['slug']); ?></p><?php endif; ?>

      <?php foreach ($namesByLanguage as $languageCode => $name): ?>
        <label for="taxonomy-name-<?php echo $languageCode; ?>"><?php echo ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'][$languageCode]; ?> name<?php echo $languageCode !== 'en' ? ' <span>(optional)</span>' : ''; ?></label>
        <input id="taxonomy-name-<?php echo $languageCode; ?>" name="name_<?php echo $languageCode; ?>" type="text" maxlength="150" value="<?php echo $escape($name); ?>">
        <?php if (isset($errors['name_' . $languageCode])): ?><p class="admin-field-error"><?php echo $escape($errors['name_' . $languageCode]); ?></p><?php endif; ?>
      <?php endforeach; ?>

      <?php if ($taxonomyKind === 'colors'): ?>
        <label for="taxonomy-hex">Hex color <span>(optional)</span></label>
        <input id="taxonomy-hex" name="hex_code" type="text" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="<?php echo $escape($values['hex_code'] ?? ($item['hex_code'] ?? '')); ?>" placeholder="#F4DADA">
        <?php if (isset($errors['hex_code'])): ?><p class="admin-field-error"><?php echo $escape($errors['hex_code']); ?></p><?php endif; ?>
      <?php endif; ?>

      <?php if ($taxonomyKind === 'scents'): ?>
        <label for="taxonomy-material">Linked fragrance material <span>(optional)</span></label>
        <?php $selectedMaterial = $values['fragrance_material_id'] ?? ($item['fragrance_material_id'] ?? ''); ?>
        <select id="taxonomy-material" name="fragrance_material_id">
          <option value="">Not linked</option>
          <?php foreach ($taxonomyShow['materialOptions'] as $material): ?>
            <option value="<?php echo $escape($material['id']); ?>"<?php echo (string) $selectedMaterial === (string) $material['id'] ? ' selected' : ''; ?>><?php echo $escape($material['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['fragrance_material_id'])): ?><p class="admin-field-error"><?php echo $escape($errors['fragrance_material_id']); ?></p><?php endif; ?>
      <?php endif; ?>

      <label class="admin-taxonomy-form__checkbox">
        <input type="checkbox" name="is_active" value="1"<?php echo $isActiveValue ? ' checked' : ''; ?>>
        <span>Active (visible for assignment on the public site)</span>
      </label>

      <button class="admin-button" type="submit">Save <?php echo $escape(strtolower($singular)); ?></button>
    </form>
  </section>
<?php endif; ?>
