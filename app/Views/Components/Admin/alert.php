<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Alert.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$alertTone = in_array($alertTone ?? 'info', ['info', 'success', 'error', 'warning'], true) ? $alertTone : 'info';
?>
<div class="admin-alert admin-alert--<?php echo $escape($alertTone); ?>" role="<?php echo $alertTone === 'error' ? 'alert' : 'status'; ?>">
  <strong><?php echo $escape($alertTitle); ?></strong>
  <span><?php echo $escape($alertMessage); ?></span>
</div>
