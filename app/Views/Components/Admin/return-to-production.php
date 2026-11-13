<?php
declare(strict_types=1);
$returnProductionOrder = is_string($_GET['return_order'] ?? null) ? filter_var($_GET['return_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($returnProductionOrder !== false): ?>
<div class="admin-context-links" data-return-order="<?php echo $escape($returnProductionOrder); ?>"><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?id=' . $returnProductionOrder . '#order-production'); ?>">← Back to order production</a><span class="admin-panel__meta">Review the supplies, then return to the order to continue.</span></div>
<?php endif; ?>
