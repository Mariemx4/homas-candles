<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Loading State.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */
?>
<div class="admin-loading-state" role="status" aria-live="polite">
  <span class="admin-loading-state__spinner" aria-hidden="true"></span>
  <span><?php echo $escape($loadingMessage ?? 'Loading dashboard data…'); ?></span>
</div>
