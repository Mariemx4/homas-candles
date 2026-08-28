<?php

declare(strict_types=1);

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
