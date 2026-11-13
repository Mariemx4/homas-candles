<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;
use App\Services\CustomRequestService;

$humanize = Presentation::humanize(...);
$format = static fn (mixed $value): string => is_string($value) && trim($value) !== '' ? $value : 'Not specified';
$failedAction = $actionFlash['values']['action'] ?? '';
$actionValue = static function (string $action, string $field, string $default = '') use ($actionFlash, $failedAction): string {
    $value = $actionFlash['values'][$field] ?? null;
    return $failedAction === $action && is_string($value) ? $value : $default;
};
?>
<?php if (!is_array($requestDetail)): ?>
  <?php $emptyTitle = $requestResult['httpStatus'] === 404 ? 'Request not found' : 'Request unavailable';
    $emptyMessage = 'Return to the request inbox and choose an available record.';
    require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
<?php else: $request = $requestDetail['request']; ?>
  <header class="admin-page-header admin-page-header--detail"><div><p class="admin-eyebrow">Custom request</p><h1><?php echo $escape($request['request_number']); ?></h1><p class="admin-page-header__summary">Submitted <?php echo $escape(\App\Services\BusinessClock::displayTimestamp($request['created_at'])); ?> · <?php echo $escape($request['category_name']); ?></p></div><div class="admin-detail-actions"><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => 'requests','id' => $request['id']])); ?>"><?php echo empty($request['archived_at']) ? 'Archive request' : 'Restore request'; ?></a><a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to inbox</a><?php if (empty($request['archived_at']) && (string) $request['request_status'] === 'AGREED' && $request['converted_order_id'] === null): ?><a class="admin-button" href="<?php echo BASE_URL . 'admin/requests/convert.php?id=' . $escape($request['id']); ?>">Create manual order</a><?php endif; ?></div></header>

  <?php if (is_string($noticeFlash['success'])): ?><?php $alertTone = 'success';
      $alertTitle = 'Request updated';
      $alertMessage = $noticeFlash['success'];
      require VIEW_PATH . '/Components/Admin/alert.php'; ?><?php endif; ?>
  <?php if ($actionFlash['errors'] !== []): ?><?php $alertTone = 'error';
      $alertTitle = 'Update not saved';
      $alertMessage = implode(' ', array_values($actionFlash['errors']));
      require VIEW_PATH . '/Components/Admin/alert.php'; ?><?php endif; ?>

<?php
$phase = match($request['request_status']) {
    'SUBMITTED' => 0,'CONTACTED','DISCUSSING' => 1,'AGREED' => 2,'CONVERTED' => 3,default => -1
};
$nextRequestTitle = match($request['request_status']) {
    'SUBMITTED' => 'Review the brief and record contact','CONTACTED' => 'Continue the conversation','DISCUSSING' => 'Confirm what you have agreed','AGREED' => 'Turn the agreement into an order','CONVERTED' => 'Continue with the linked order',default => 'This request is closed'
};
$nextRequestLink = $request['request_status'] === 'AGREED' ? BASE_URL . 'admin/requests/convert.php?id=' . $request['id'] : ($request['request_status'] === 'CONVERTED' ? BASE_URL . 'admin/orders/detail.php?id=' . $request['converted_order_id'] : '#request-conversation');
$nextRequestButton = match($request['request_status']) {
    'SUBMITTED' => 'Record contact','CONTACTED' => 'Continue discussion','DISCUSSING' => 'Record agreement','AGREED' => 'Create order','CONVERTED' => 'Open order',default => 'View history'
};
if (!empty($request['archived_at'])) {
    $nextRequestTitle = 'This request is archived';
    $nextRequestLink = BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => 'requests','id' => $request['id']]);
    $nextRequestButton = 'Restore request';
}
?>
<ol class="admin-progress-track" aria-label="Request progress"><?php foreach (['New enquiry','In discussion','Agreed','Order created'] as $index => $label): ?><li data-state="<?php echo $phase === $index ? 'current' : ($phase > $index ? 'complete' : 'upcoming'); ?>"<?php echo $phase === $index ? ' aria-current="step"' : ''; ?>><?php echo $label; ?></li><?php endforeach; ?></ol>
<section class="admin-task-panel"><div><span class="admin-eyebrow">CURRENT TASK</span><h2><?php echo $escape($nextRequestTitle); ?></h2><p>Keep the customer's brief, discussion and final agreement together.</p></div><?php if ($phase >= 0 || !empty($request['archived_at'])): ?><a class="admin-button" href="<?php echo $escape($nextRequestLink); ?>"><?php echo $escape($nextRequestButton); ?></a><?php endif; ?></section>
<div data-request-record>
  <?php if (!empty($request['custom_products'])): ?>
  <section class="admin-panel" data-request-section="Brief"><h2>Requested products</h2>
  <?php foreach ($request['custom_products'] as $definition): ?><article><h3><?php echo $escape($definition['working_name']); ?></h3><p><?php echo $escape($definition['category_name'] . ' / ' . $definition['type_name'] . ' / Quantity ' . $definition['requested_quantity']); ?></p><p><?php echo $escape('Color: ' . $definition['color_preferences'] . ' [' . $definition['color_mode'] . '] / Scent: ' . $definition['scent_preferences'] . ' [' . $definition['scent_mode'] . ']'); ?></p><p><?php echo nl2br($escape($definition['specifications'])); ?></p>
  <?php if (!empty($definition['components'])): ?><ul><?php foreach ($definition['components'] as $component): ?><li><?php echo $escape($component['quantity'] . ' x ' . $component['product_name_snapshot'] . ' (' . $component['source'] . ')'); ?><?php foreach (['color','scent'] as $kind): ?><?php if ($component[$kind . '_mode'] !== 'NONE'): ?> / <?php echo $escape(ucfirst($kind) . ': ' . $component[$kind . '_value'] . ' [' . $component[$kind . '_mode'] . ']'); ?><?php endif; ?><?php endforeach; ?><?php echo $escape(' ' . $component['specifications']); ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($definition['draft_product_id'] !== null): ?><a href="<?php echo BASE_URL . 'admin/catalogue/products.php?id=' . $escape($definition['draft_product_id']); ?>">Complete catalogue draft</a><?php endif; ?></article><?php endforeach; ?>
  <?php if ($request['production_completed']): ?><form method="post" action="<?php echo BASE_URL; ?>admin/requests/action.php"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="id" value="<?php echo $escape($request['id']); ?>"><button class="admin-button admin-button--secondary" name="action" value="create_drafts">Create catalogue drafts</button></form><p>Drafts remain inactive until completed and activated in Catalogue.</p><?php else: ?><p>Catalogue drafts become available after this order reaches Prepared.</p><?php endif; ?></section>
  <?php endif; ?>
  <?php if ($requestDetail['briefEditable'] && $request['custom_products'] !== []): ?><p data-request-section="Brief"><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/edit.php?id=' . $escape($request['id']); ?>"><?php echo AdminIcons::render('edit'); ?>Correct working brief</a></p><?php elseif ($request['custom_products'] !== []): ?><p data-request-section="Brief">Product definitions are locked after agreement, conversion or closure.</p><?php endif; ?>
  <?php if ($requestDetail['briefRevisions'] !== []): ?>
  <section class="admin-panel" data-request-section="History"><h2>Brief revisions</h2><p>Each correction preserves the previous specifications. Newest corrections appear first.</p>
  <?php foreach ($requestDetail['briefRevisions'] as $revision): ?>
  <details><summary><?php echo $escape($revision['created_at'] . ' · ' . $revision['administrator_name'] . ' · ' . $revision['reason']); ?></summary>
    <?php foreach (['before_brief' => 'Before correction','after_brief' => 'After correction'] as $key => $label): $snapshot = json_decode($revision[$key], true); ?>
    <h3><?php echo $label; ?></h3>
    <?php foreach ($snapshot['custom_products'] ?? [] as $product): ?><article><h4><?php echo $escape($product['working_name'] . ' · Quantity ' . $product['requested_quantity']); ?></h4><p><?php echo $escape('Color: ' . $product['color_preferences'] . '; Scent: ' . $product['scent_preferences']); ?></p><p class="admin-long-copy"><?php echo nl2br($escape($product['specifications'])); ?></p><ul><?php foreach ($product['components'] ?? [] as $component): ?><li class="admin-long-copy"><?php echo $escape($component['quantity'] . ' · ' . $component['product_name_snapshot'] . '; Color: ' . $component['color_value'] . '; Scent: ' . $component['scent_value'] . '; ' . $component['specifications']); ?></li><?php endforeach; ?></ul></article><?php endforeach; ?>
    <?php endforeach; ?>
  </details><?php endforeach; ?>
  <?php if ($requestDetail['revisionPages'] > 1): ?><nav aria-label="Brief revision history"><p>History page <?php echo $requestDetail['revisionPage']; ?> of <?php echo $requestDetail['revisionPages']; ?></p>
  <?php foreach ([-1 => 'Newer revisions',1 => 'Older revisions'] as $step => $label): $page = $requestDetail['revisionPage'] + $step;
      if ($page < 1 || $page > $requestDetail['revisionPages']) {
          continue;
      } ?>
  <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $request['id'],'revision_page' => $page,'return' => $requestResult['backQuery']])); ?>"><?php echo $label; ?></a>
  <?php endforeach; ?></nav><?php endif; ?></section><?php endif; ?>

  <div class="admin-detail-grid admin-request-detail-grid">
    <div class="admin-detail-grid__main">
      <section class="admin-panel" data-request-section="Brief"><header class="admin-panel__header"><div><p class="admin-eyebrow">Submitted brief</p><h2>Preferences</h2></div><span class="admin-badge admin-badge--<?php echo $escape($request['attention']['tone']); ?>"><?php echo $escape($request['attention']['label']); ?></span></header>
        <dl class="admin-definition-grid"><div><dt>Product</dt><dd><?php echo $escape($format($request['product_name'])); ?></dd></div><div><dt>Quantity</dt><dd><?php echo $escape($format($request['requested_quantity'])); ?></dd></div><div><dt>Budget</dt><dd><?php echo $request['budget'] === null ? 'Not specified' : $escape(number_format((float) $request['budget'], 3)) . ' TND'; ?></dd></div><div><dt>Required date</dt><dd><?php echo $escape($format($request['required_date'])); ?></dd></div><div><dt>Color</dt><dd class="admin-long-copy"><?php echo nl2br($escape($format($request['color_preferences']))); ?></dd></div><div><dt>Scent</dt><dd class="admin-long-copy"><?php echo nl2br($escape($format($request['scent_preferences']))); ?></dd></div><div><dt>Packaging</dt><dd class="admin-long-copy"><?php echo nl2br($escape($format($request['packaging_preferences']))); ?></dd></div></dl>
        <div class="admin-message-block"><h3>Customer message</h3><p class="admin-long-copy"><?php echo nl2br($escape($format($request['customer_message']))); ?></p></div>
      </section>

      <section class="admin-panel" data-request-section="Conversation"><header class="admin-panel__header"><div><h2>Contact history</h2></div></header>
        <?php if ($requestDetail['contacts'] === []): ?><?php $emptyTitle = 'No contact recorded';
            $emptyMessage = 'Log operational contact attempts here without changing the customer’s submitted message.';
            require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
        <?php else: ?><ol class="admin-timeline"><?php foreach ($requestDetail['contacts'] as $contact): ?><li><div><strong><?php echo $escape($humanize($contact['contact_outcome'])); ?></strong><span><?php echo $escape($humanize($contact['contact_method'])); ?> · <?php echo $escape($contact['administrator_name']); ?></span></div><time><?php echo $escape(\App\Services\BusinessClock::displayTimestamp($contact['contacted_at'])); ?></time><?php if ($contact['contact_notes'] !== null): ?><p class="admin-long-copy"><?php echo nl2br($escape($contact['contact_notes'])); ?></p><?php endif; ?><?php if ($contact['next_follow_up_at'] !== null): ?><small>Follow up: <?php echo $escape(\App\Services\BusinessClock::displayTimestamp($contact['next_follow_up_at'])); ?></small><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
      </section>
    </div>

    <aside class="admin-detail-grid__aside">
      <section class="admin-panel" data-request-section="Overview"><header class="admin-panel__header"><div><h2>Current state</h2></div></header><p><?php $status = $request['request_status'];
require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></p><dl class="admin-definition-list"><div><dt>Assigned</dt><dd><?php echo $escape($request['administrator_name'] ?? 'Unassigned'); ?></dd></div><?php if ($request['converted_order_id'] !== null): ?><div><dt>Order</dt><dd><a href="<?php echo BASE_URL . 'admin/orders/detail.php?id=' . $escape($request['converted_order_id']); ?>"><?php echo $escape($request['converted_order_number']); ?></a></dd></div><div><dt>Converted</dt><dd><?php echo $escape($request['converted_at']); ?></dd></div><?php endif; ?></dl></section>

      <section class="admin-panel admin-contact-card" data-request-section="Overview"><header class="admin-panel__header"><div><h2>Customer</h2></div></header><dl class="admin-definition-list"><div><dt>Name</dt><dd><?php echo $escape($request['customer_name_snapshot']); ?></dd></div><div><dt>Phone</dt><dd><?php echo $escape($request['customer_phone_snapshot']); ?></dd></div><div><dt>Email</dt><dd><?php echo $escape($format($request['customer_email_snapshot'])); ?></dd></div><div><dt>Preferred</dt><dd><?php echo $escape($humanize($request['preferred_contact_method'])); ?></dd></div></dl></section>

      <?php if ($requestDetail['editable']): ?>
        <?php $assignmentId = $actionValue('assign', 'administrator_id', (string) $request['assigned_administrator_id']); ?>
        <section class="admin-panel" data-request-section="Overview">
          <h2>Assignment</h2>
          <form class="admin-stack-form" action="<?php echo BASE_URL . 'admin/requests/action.php'; ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="id" value="<?php echo $escape($request['id']); ?>">
            <input type="hidden" name="action" value="assign">
            <div class="admin-field">
              <label for="assigned-admin">Administrator</label>
              <select id="assigned-admin" name="administrator_id">
                <option value=""<?php echo $assignmentId === '' ? ' selected' : ''; ?>>Unassigned</option>
                <?php if ($assignmentId !== '' && !in_array($assignmentId, array_map('strval', array_column($requestDetail['administrators'], 'id')), true)): ?>
                  <option value="<?php echo $escape($assignmentId); ?>" selected>Unavailable administrator — choose another</option>
                <?php endif; ?>
                <?php foreach ($requestDetail['administrators'] as $admin): ?>
                  <option value="<?php echo $escape($admin['id']); ?>"<?php echo $assignmentId === (string) $admin['id'] ? ' selected' : ''; ?>><?php echo $escape($admin['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="admin-button admin-button--secondary" type="submit"><?php echo AdminIcons::render('save'); ?>Save assignment</button>
          </form>
        </section>

        <?php
    $contactMethod = $actionValue('contact', 'contact_method', $request['preferred_contact_method']);
          $contactOutcome = $actionValue('contact', 'contact_outcome', CustomRequestService::CONTACT_OUTCOMES[0]);
          ?>
        <section class="admin-panel" data-request-section="Conversation">
          <h2>Log contact</h2>
          <form class="admin-stack-form" action="<?php echo BASE_URL . 'admin/requests/action.php'; ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
            <input type="hidden" name="id" value="<?php echo $escape($request['id']); ?>">
            <input type="hidden" name="action" value="contact">
            <div class="admin-field">
              <label for="contact-method">Method</label>
              <select id="contact-method" name="contact_method" required>
                <?php if (!in_array($contactMethod, CustomRequestService::CONTACT_METHODS, true)): ?><option value="<?php echo $escape($contactMethod); ?>" selected>Choose a valid contact method</option><?php endif; ?>
                <?php foreach (CustomRequestService::CONTACT_METHODS as $option): ?><option value="<?php echo $escape($option); ?>"<?php echo $contactMethod === $option ? ' selected' : ''; ?>><?php echo $escape($humanize($option)); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="admin-field">
              <label for="contact-outcome">Outcome</label>
              <select id="contact-outcome" name="contact_outcome" required>
                <?php if (!in_array($contactOutcome, CustomRequestService::CONTACT_OUTCOMES, true)): ?><option value="<?php echo $escape($contactOutcome); ?>" selected>Choose a valid contact outcome</option><?php endif; ?>
                <?php foreach (CustomRequestService::CONTACT_OUTCOMES as $option): ?><option value="<?php echo $escape($option); ?>"<?php echo $contactOutcome === $option ? ' selected' : ''; ?>><?php echo $escape($humanize($option)); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="admin-field"><label for="contact-follow-up">Next follow-up (Tunisia time, optional)</label><input id="contact-follow-up" name="follow_up" type="datetime-local" value="<?php echo $escape($actionValue('contact', 'follow_up')); ?>"></div>
            <div class="admin-field"><label for="contact-notes">Protected notes</label><textarea id="contact-notes" name="notes" maxlength="5000" rows="4"><?php echo $escape($actionValue('contact', 'notes')); ?></textarea></div>
            <button class="admin-button admin-button--secondary" type="submit"><?php echo AdminIcons::render('save'); ?>Record contact</button>
          </form>
        </section>

        <?php if ($requestDetail['allowedTransitions'] !== []): ?>
          <?php
              $nextStatus = $actionValue('transition', 'new_status', $requestDetail['allowedTransitions'][0]);
            $transitionMethod = $actionValue('transition', 'contact_method', $request['preferred_contact_method']);
            ?>
          <section class="admin-panel" data-request-section="Conversation">
            <h2>Change status</h2>
            <form class="admin-stack-form" action="<?php echo BASE_URL . 'admin/requests/action.php'; ?>" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
              <input type="hidden" name="id" value="<?php echo $escape($request['id']); ?>">
              <input type="hidden" name="action" value="transition">
              <div class="admin-field">
                <label for="new-request-status">Next status</label>
                <select id="new-request-status" name="new_status" required>
                  <?php if (!in_array($nextStatus, $requestDetail['allowedTransitions'], true)): ?><option value="<?php echo $escape($nextStatus); ?>" selected>Unavailable status — choose another</option><?php endif; ?>
                  <?php foreach ($requestDetail['allowedTransitions'] as $option): ?><option value="<?php echo $escape($option); ?>"<?php echo $nextStatus === $option ? ' selected' : ''; ?>><?php echo $escape($humanize($option)); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="admin-field">
                <label for="transition-method">Contact method (unless cancelled)</label>
                <select id="transition-method" name="contact_method">
                  <option value=""<?php echo $transitionMethod === '' ? ' selected' : ''; ?>>Not applicable</option>
                  <?php if ($transitionMethod !== '' && !in_array($transitionMethod, CustomRequestService::CONTACT_METHODS, true)): ?><option value="<?php echo $escape($transitionMethod); ?>" selected>Choose a valid contact method</option><?php endif; ?>
                  <?php foreach (CustomRequestService::CONTACT_METHODS as $option): ?><option value="<?php echo $escape($option); ?>"<?php echo $transitionMethod === $option ? ' selected' : ''; ?>><?php echo $escape($humanize($option)); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="admin-field"><label for="transition-notes">Contact notes</label><textarea id="transition-notes" name="notes" maxlength="5000" rows="3"><?php echo $escape($actionValue('transition', 'notes')); ?></textarea></div>
              <button class="admin-button" type="submit"><?php echo AdminIcons::render('save'); ?>Record outcome</button>
            </form>
          </section>
        <?php endif; ?>
      <?php else: ?><section class="admin-panel" data-request-section="Overview"><h2>Editing closed</h2><p>This request is closed. Its discussion and history remain available.</p></section><?php endif; ?>
    </aside>
  </div>
</div>
<?php endif; ?>
