<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Stat Card.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$statTone = in_array($statTone ?? 'default', ['default', 'warning', 'danger', 'success'], true)
    ? $statTone
    : 'default';
?>
<article class="admin-stat admin-stat--<?php echo $escape($statTone); ?>">
  <div class="admin-stat__heading">
    <p><?php echo $escape($statLabel); ?></p>
    <span aria-hidden="true"><?php echo $escape($statMark); ?></span>
  </div>
  <p class="admin-stat__value"><?php echo $escape($statValue); ?></p>
  <p class="admin-stat__note"><?php echo $escape($statNote); ?></p>
</article>
