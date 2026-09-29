<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Empty State.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */
?>
<div class="admin-empty-state" role="status">
  <span class="admin-empty-state__mark" aria-hidden="true">✓</span>
  <h3><?php echo $escape($emptyTitle); ?></h3>
  <p><?php echo $escape($emptyMessage); ?></p>
</div>
