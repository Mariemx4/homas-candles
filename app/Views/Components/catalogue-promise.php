<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Catalogue Promise.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

if (!isset($catalogueClassPrefix) || !is_string($catalogueClassPrefix)) {
    throw new RuntimeException('Catalogue class prefix is required.');
}
?>

<aside class="<?php echo htmlspecialchars($catalogueClassPrefix); ?>-promise" aria-label="The Hom'As promise">
  <?php foreach (['Handmade with care', 'Made for meaningful moments', 'Delivered across Tunisia'] as $promise): ?>
    <p>
      <i data-lucide="sparkles" aria-hidden="true"></i>
      <?php echo htmlspecialchars($promise); ?>
      <i data-lucide="sparkles" aria-hidden="true"></i>
    </p>
  <?php endforeach; ?>
</aside>
