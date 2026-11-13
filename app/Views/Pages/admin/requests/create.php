<?php

declare(strict_types=1);

use App\View\AdminIcons;

/** Presentation-only helper: field errors were prepared by the controller/flash layer. */
$fieldError = static fn (string $key): string => isset($creationFlash['errors'][$key]) ? (string) $creationFlash['errors'][$key] : '';
?>
<header class="admin-page-header">
  <div><h1>Add custom request</h1><p class="admin-page-header__summary">Record an enquiry received by phone, social media, or in person before pricing is agreed.</p></div>
  <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/index.php'; ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a>
</header>

<?php if ($creationFlash['errors'] !== []): ?>
  <?php $alertTone = 'error';
    $alertTitle = 'Request not created';
    $alertMessage = implode(' ', array_unique(array_values($creationFlash['errors'])));
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>

<?php $draftKind = 'request';
$draftRoute = BASE_URL . 'admin/requests/create.php';
require VIEW_PATH . '/Components/Admin/saved-work.php'; ?>
<form data-form-journey="request" class="admin-panel admin-conversion-form" method="post" action="<?php echo BASE_URL . 'admin/requests/create.php'; ?>">
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="work_draft_id" value="<?php echo $escape($creationValues['work_draft_id'] ?? ''); ?>">
  <fieldset data-journey-step="0"><legend>Customer</legend>
    <?php $customerModeAllowNew = true;
$customerModeContext = 'request';
$customerModeIncludeNotes = false;
$customerContactValues = $creationValues;
$customerContactErrors = $creationFlash['errors'];
require VIEW_PATH . '/Components/Admin/order-customer-picker.php'; ?>
  </fieldset>
  <fieldset data-journey-step="0"><legend>Request details</legend><div class="admin-form-grid">
    <div class="admin-field"><label for="request-contact-method">Preferred contact</label><select id="request-contact-method" name="preferred_contact_method"><?php foreach (App\Services\CustomRequestService::CONTACT_METHODS as $method): ?><option value="<?php echo $escape($method); ?>"<?php echo ($creationValues['preferred_contact_method'] ?? '') === $method ? ' selected' : ''; ?>><?php echo $escape(ucwords(strtolower($method))); ?></option><?php endforeach; ?></select></div>
  </div></fieldset>
  <?php require VIEW_PATH . '/Components/Admin/custom-request-products.php'; ?>
  <fieldset data-journey-step="2"><legend>Request preferences</legend><div class="admin-form-grid">
    <div class="admin-field"><label for="request-budget">Budget (TND)</label><input id="request-budget" name="budget" inputmode="decimal" value="<?php echo $escape($creationValues['budget'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-required-date">Required date</label><input id="request-required-date" name="required_date" type="date" value="<?php echo $escape($creationValues['required_date'] ?? ''); ?>"></div>
    <div class="admin-field admin-field--wide"><label for="request-packaging">Packaging preferences</label><input id="request-packaging" name="packaging_preferences" maxlength="500" value="<?php echo $escape($creationValues['packaging_preferences'] ?? ''); ?>"></div>
    <div class="admin-field admin-field--wide"><label for="request-message">Customer request</label><textarea id="request-message" name="customer_message" maxlength="10000" rows="6"><?php echo $escape($creationValues['customer_message'] ?? ''); ?></textarea></div>
  </div></fieldset>
  <input type="hidden" name="request_payload_complete" value="1">
  <div class="admin-conversion-form__actions" data-journey-step="2"><p>This creates an enquiry in Submitted status. Discuss and agree the final product before converting it into an order.</p><button class="admin-button" type="submit">Create custom request</button></div>
<button class="admin-button admin-button--secondary" type="submit" name="action" value="save_draft" formnovalidate><?php echo AdminIcons::render('save'); ?>Save draft for later</button>
</form>

<script src="<?php echo BASE_URL . 'assets/js/admin/order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/order-details.js'); ?>" defer></script>

<script src="<?php echo BASE_URL . 'assets/js/admin/custom-request.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/custom-request.js'); ?>" defer></script>
