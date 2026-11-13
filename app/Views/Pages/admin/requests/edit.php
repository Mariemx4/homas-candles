<?php declare(strict_types=1);

use App\View\AdminIcons;

?>
<header class="admin-page-header"><div><h1>Correct working brief</h1><p class="admin-page-header__summary">Update requested products before agreement. Each saved correction retains the previous brief, your name and the reason.</p></div><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/detail.php?id=' . $id; ?>"><?php echo AdminIcons::render('back'); ?>Back to request</a></header>
<?php if ($creationFlash['errors'] !== []): ?>
  <?php $alertTone = 'error';
    $alertTitle = 'Correction not saved';
    $alertMessage = implode(' ', $creationFlash['errors']);
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>
<?php if ($requestResult['httpStatus'] === 200): ?>
<form class="admin-panel admin-conversion-form" method="post" action="<?php echo BASE_URL; ?>admin/requests/edit.php">
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
  <input type="hidden" name="id" value="<?php echo $id; ?>">
  <input type="hidden" name="version" value="<?php echo $escape($creationValues['version'] ?? ''); ?>">
  <?php if (($creationValues['version'] ?? '') !== $requestDetail['briefVersion']): ?><p class="admin-alert admin-alert--warning">This form contains an older brief. Copy any changes you need to keep, then <a href="<?php echo BASE_URL . 'admin/requests/edit.php?id=' . $id; ?>">reload the current brief</a> before saving.</p><?php endif; ?>
  <?php require VIEW_PATH . '/Components/Admin/custom-request-products.php'; ?>
  <div class="admin-field"><label for="revision-reason">Reason for correction</label><textarea id="revision-reason" name="revision_reason" maxlength="500" rows="3" required aria-describedby="revision-reason-error"><?php echo $escape($creationValues['revision_reason'] ?? ''); ?></textarea><small id="revision-reason-error" class="admin-field-error"><?php echo $escape($creationFlash['errors']['revision_reason'] ?? ''); ?></small></div>
  <input type="hidden" name="request_payload_complete" value="1">
  <div class="admin-conversion-form__actions"><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/detail.php?id=' . $id; ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a><button class="admin-button" type="submit"><?php echo AdminIcons::render('save'); ?>Save correction</button></div>
</form>
<script src="<?php echo BASE_URL . 'assets/js/admin/custom-request.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/custom-request.js'); ?>" defer></script>
<?php else: ?><p class="admin-alert admin-alert--warning">Brief corrections are available on unconverted requests before agreement. For a website enquiry without separate product definitions, record clarifications in Contact history and review the final quotation during conversion.</p><?php endif; ?>
