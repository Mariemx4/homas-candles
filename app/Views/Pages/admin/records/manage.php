<?php

declare(strict_types=1);

use App\View\AdminIcons;
use App\Http\AdminRecordNavigation;

// Return to this exact record. Deleted records return to their directory.
$back = BASE_URL . ($lifecycle !== null
    ? AdminRecordNavigation::recordPath($kind, $id)
    : AdminRecordNavigation::listPath($kind));
$recordLabel = AdminRecordNavigation::label($kind);
?>
<div data-admin-confirmation-content>
  <header class="admin-page-header">
    <div>
      <h1><?php echo $escape($recordLabel); ?> status</h1>
      <p>Change availability while keeping existing work and history intact.</p>
    </div><a class="admin-button admin-button--secondary" href="<?php echo $escape($back); ?>"><?php echo AdminIcons::render('back'); ?>Back to <?php echo $lifecycle !== null ? $escape(strtolower($recordLabel)) : 'list'; ?></a>
  </header>
  <?php if ($lifecycleFlash['errors'] || $lifecycleFlash['success']): $alertTone = $lifecycleFlash['errors'] ? 'error' : 'success';
      $alertTitle = $lifecycleFlash['errors'] ? 'Action unavailable' : 'Saved';
      $alertMessage = $lifecycleFlash['errors'] ? implode(' ', $lifecycleFlash['errors']) : $lifecycleFlash['success'];
      require VIEW_PATH . '/Components/Admin/alert.php';
  endif; ?>
  <?php if ($lifecycle): $record = $lifecycle['record'];
      $name = $record['name'] ?? $record['code'] ?? $record['request_number'] ?? $record['slug'] ?? trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?>
    <section class="admin-panel admin-lifecycle-panel">
      <h2><?php echo $escape($name); ?></h2><span class="admin-badge admin-badge--<?php echo $lifecycle['active'] ? 'success' : 'neutral'; ?>"><?php echo $kind === 'requests' ? ($lifecycle['active'] ? 'Current' : 'Archived') : ($lifecycle['active'] ? 'Active' : 'Inactive / Draft'); ?></span>
      <?php if ($lifecycle['dependencies']): ?><p>This record cannot be permanently deleted. Its references must remain intact.</p>
        <ul><?php foreach ($lifecycle['dependencies'] as $source => $count): ?><li><?php echo $escape($count . ' ' . ucwords(str_replace('_', ' ', $source))); ?></li><?php endforeach; ?></ul>
      <?php elseif ($kind === 'requests'): ?><p>Custom requests preserve contacts, quotations and conversion history. Archive hides the request from the default work list; restore keeps the same history.</p>
      <?php elseif ($lifecycle['can_delete']): ?><p>This record has no business dependencies. Permanent deletion removes the record and its owned translation or reset metadata. This cannot be undone.</p>
      <?php else: ?><p>Your current administrator account and the last active administrator cannot be removed or deactivated.</p><?php endif; ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="kind" value="<?php echo $escape($kind); ?>"><input type="hidden" name="id" value="<?php echo $escape($id); ?>">
        <?php if ($kind === 'requests'): ?><button class="admin-button<?php echo $lifecycle['active'] ? ' admin-button--warning' : ''; ?>" name="action" value="<?php echo $lifecycle['active'] ? 'archive' : 'restore'; ?>"><?php echo $lifecycle['active'] ? 'Archive request' : 'Restore request'; ?></button>
        <?php else: ?>
          <?php if (!$lifecycle['active'] || $lifecycle['can_deactivate']): ?><p><?php echo $lifecycle['active'] ? 'Deactivation preserves references and history while stopping new use.' : 'Activation uses the existing readiness and availability rules.'; ?></p><button class="admin-button admin-button--secondary" name="action" value="<?php echo $lifecycle['active'] ? 'deactivate' : 'activate'; ?>"><?php echo $lifecycle['active'] ? 'Deactivate' : 'Activate'; ?></button><?php endif; ?>
          <?php if ($lifecycle['can_delete']): ?><details>
              <summary>Delete this unused record permanently</summary>
              <p>Delete <?php echo $escape($name); ?>? References are checked again when you submit.</p><button class="admin-button admin-button--danger" name="action" value="delete"><?php echo AdminIcons::render('trash'); ?>Permanently delete</button>
            </details><?php endif; ?>
        <?php endif; ?><a class="admin-button admin-button--secondary" href="<?php echo $escape($back); ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a></form>
    </section><?php endif; ?>
</div>
