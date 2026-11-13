<?php

declare(strict_types=1);



$alertTone = in_array($alertTone ?? 'info', ['info', 'success', 'error', 'warning'], true) ? $alertTone : 'info';
?>
<div class="admin-alert admin-alert--<?php echo $escape($alertTone); ?>" role="<?php echo $alertTone === 'error' ? 'alert' : 'status'; ?>">
  <strong><?php echo $escape($alertTitle); ?></strong>
  <span><?php echo $escape($alertMessage); ?></span>
</div>
