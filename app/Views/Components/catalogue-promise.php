<?php

declare(strict_types=1);



if (!isset($catalogueClassPrefix) || !is_string($catalogueClassPrefix)) {
    throw new RuntimeException('Catalogue class prefix is required.');
}
?>

<aside class="<?php echo htmlspecialchars($catalogueClassPrefix); ?>-promise" aria-label="<?php echo site_text('catalogue.promise'); ?>">
  <?php foreach (['catalogue.promise_1', 'catalogue.promise_2', 'catalogue.promise_3'] as $promise): ?>
    <p>
      <i data-lucide="sparkles" aria-hidden="true"></i>
      <?php echo site_text($promise); ?>
      <i data-lucide="sparkles" aria-hidden="true"></i>
    </p>
  <?php endforeach; ?>
</aside>
