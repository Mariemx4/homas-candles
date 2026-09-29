<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Order Status; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

/**
 * @var array $orderDetail
 * @var array $transitionConfirmation
 * @var object $backUrl  
 * @var callable $escape
 */

$escape = $escape ?? static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$humanize = static fn(mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$generalError = $transitionForm['errors']['form'] ?? $transitionForm['errors']['transition'] ?? null;
$noteError = $transitionForm['errors']['note'] ?? null;
?>
<?php if (!is_array($orderDetail['order']) || !is_array($transitionConfirmation)): ?>
  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>Transition unavailable</h1>
      <p><?php echo $escape($orderDetail['loadError'] ?? 'The requested transition could not be loaded.'); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Back to orders</a>
    </div>
  </section>
<?php elseif (!$transitionConfirmation['validProposal']): ?>
  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-detail-error">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1>Illegal status transition</h1>
      <p><?php echo $escape($transitionConfirmation['proposalError']); ?></p>
      <?php if ($generalError !== null): ?><p><?php echo $escape($generalError); ?></p><?php endif; ?>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>">Review current order</a>
    </div>
  </section>
<?php else: ?>
  <?php $order = $orderDetail['order']; ?>
  <header class="admin-order-header admin-order-header--status">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($backUrl); ?>">← Back to order</a>
      <h1>Update status</h1>
      <p>Order <?php echo $escape($order['order_number']); ?></p>
    </div>
  </header>

  <?php if ($generalError !== null): ?>
    <?php
    $alertTone = 'error';
    $alertTitle = 'Transition not completed';
    $alertMessage = $generalError;
    require __DIR__ . '/../../Components/Admin/alert.php';
    ?>
  <?php endif; ?>

  <div class="admin-transition-confirmation">
    <!-- SECTION PURPOSE: Group the Review the change content, states, and controls as one accessible page region. -->
    <section class="admin-panel admin-transition-confirmation__summary" aria-labelledby="transition-summary-title">
      <header class="admin-panel__header">
        <h2 id="transition-summary-title">Status change</h2>
      </header>
      <div class="admin-transition-route">
        <div><span>Current status</span><strong><?php echo $escape($humanize($transitionConfirmation['currentStatus'])); ?></strong></div>
        <span class="admin-transition-route__arrow" aria-hidden="true">→</span>
        <div><span>Next status</span><strong><?php echo $escape($humanize($transitionConfirmation['nextStatus'])); ?></strong></div>
      </div>
      <div class="admin-consequence">
        <strong>What happens next</strong>
        <p><?php echo $escape($transitionConfirmation['consequence']); ?></p>
      </div>
      <?php if (!$transitionConfirmation['allowed']): ?>
        <div class="admin-blocked-reason" role="alert"><strong>Transition blocked</strong><span><?php echo $escape($transitionConfirmation['reason']); ?></span></div>
      <?php endif; ?>
    </section>

    <!-- SECTION PURPOSE: Group the Administrator confirmation content, states, and controls as one accessible page region. -->
    <section class="admin-panel admin-transition-confirmation__form" aria-labelledby="transition-form-title">
      <header class="admin-panel__header">
        <h2 id="transition-form-title">Save update</h2>
      </header>
      <!-- FORM PURPOSE: Submit status form with POST; the receiving PHP route must validate the values before using them. -->
      <form class="admin-status-form" action="<?php echo BASE_URL . 'admin/orders/status.php'; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <input type="hidden" name="id" value="<?php echo $escape($order['id']); ?>">
        <input type="hidden" name="new_status" value="<?php echo $escape($transitionConfirmation['nextStatus']); ?>">
        <input type="hidden" name="return" value="<?php echo $escape($orderDetail['backQuery']); ?>">

        <label for="transition-note">Administrator note <span>(optional)</span></label>
        <textarea id="transition-note" name="note" maxlength="500" rows="3" aria-describedby="transition-note-help<?php echo $noteError !== null ? ' transition-note-error' : ''; ?>" <?php echo $noteError !== null ? ' aria-invalid="true"' : ''; ?>><?php echo $escape($transitionForm['values']['note'] ?? ''); ?></textarea>
        <p id="transition-note-help">Saved with this update in the order's status history.</p>
        <?php if ($noteError !== null): ?><p class="admin-field-error" id="transition-note-error"><?php echo $escape($noteError); ?></p><?php endif; ?>

        <div class="admin-status-form__actions">
          <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>">Cancel</a>
          <button class="admin-button" type="submit" <?php echo !$transitionConfirmation['allowed'] ? ' disabled' : ''; ?>>Mark as <?php echo $escape($humanize($transitionConfirmation['nextStatus'])); ?></button>
        </div>
        <?php if (!$transitionConfirmation['allowed']): ?><p class="admin-status-form__disabled">Resolve the blocking requirement before trying again.</p><?php endif; ?>
      </form>
    </section>
  </div>
<?php endif; ?>