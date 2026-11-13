<?php
declare(strict_types=1);
$escape = static fn ($value) => htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="account-page container">
  <a class="account-back" href="<?php echo $escape(BASE_URL . 'account-order.php?id=' . ($order['id'] ?? '')); ?>"><?php echo site_text('account.back'); ?></a>
  <header class="account-page__header"><h1><?php echo site_text($order === null ? 'account.not_found' : 'account.edit_order'); ?></h1><?php if ($order): ?><p><?php echo $escape($order['order_number']); ?></p><?php endif; ?></header>
  <?php if ($errors): ?><p class="account-card" role="alert"><?php echo $escape(implode(' ', $errors)); ?></p><?php endif; ?>
  <?php if ($order && !$canEdit): ?><p class="account-card"><?php echo site_text('account.edit_closed'); ?></p><?php endif; ?>
  <?php if ($order && $canEdit): ?>
    <?php if ($quote): ?>
      <section class="account-card"><h2><?php echo site_text('account.review_changes'); ?></h2>
        <dl class="account-details">
          <div><dt><?php echo site_text('account.delivery'); ?></dt><dd><?php echo site_text($quote['values']['delivery_method'] === 'PICKUP' ? 'checkout.pickup' : 'checkout.home_delivery'); ?></dd></div>
          <div><dt><?php echo site_text('account.required'); ?></dt><dd><?php echo $escape($quote['values']['required_date']); ?></dd></div>
          <?php if ($quote['values']['delivery_method'] === 'DELIVERY'): ?><div><dt><?php echo site_text('account.address'); ?></dt><dd><?php echo $escape(implode(', ', array_intersect_key($quote['values'], array_flip(['street_address','delegation','governorate','postal_code'])))); ?></dd></div><?php endif; ?>
          <?php if ($quote['values']['customer_notes']): ?><div><dt><?php echo site_text('account.notes'); ?></dt><dd><?php echo $escape($quote['values']['customer_notes']); ?></dd></div><?php endif; ?>
        </dl>
        <table class="account-orders"><thead><tr><th><?php echo site_text('account.item'); ?></th><th><?php echo site_text('account.quantity'); ?></th><th><?php echo site_text('account.unit_price'); ?></th><th><?php echo site_text('account.total'); ?></th></tr></thead><tbody>
        <?php foreach ($quote['lines'] as $line): ?><tr><td><?php echo $escape($line['name']); ?></td><td><?php echo $line['quantity']; ?></td><td><?php echo \App\Services\Money::formatMillis($line['unit_millis']); ?> DT</td><td><?php echo \App\Services\Money::formatMillis($line['total_millis']); ?> DT</td></tr><?php endforeach; ?>
        </tbody></table><dl class="account-totals">
        <?php foreach (['subtotal' => 'subtotal','promo' => 'promotion','manual' => 'discount','delivery' => 'delivery_fee','total' => 'total','deposit' => 'deposit','paid' => 'paid','pending' => 'pending','deposit_due' => 'deposit_due','remaining' => 'remaining','credit' => 'credit'] as $key => $label): ?>
          <div><dt><?php echo site_text('account.' . $label); ?></dt><dd><?php echo \App\Services\Money::formatMillis($quote[$key . '_millis']); ?> DT</dd></div>
        <?php endforeach; ?></dl>
        <p><?php echo site_text('account.payment_edit_help'); ?></p>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="review_token" value="<?php echo $escape($draft['token']); ?>"><button class="account-button" name="action" value="save"><?php echo site_text('account.save_order'); ?></button></form>
      </section>
    <?php endif; ?>
    <form method="post" class="account-profile-form">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="version" value="<?php echo $escape($version); ?>">
      <section class="account-card"><h2><?php echo site_text('account.items'); ?></h2><p><?php echo site_text('account.edit_rules'); ?></p>
        <div class="account-form-grid"><?php foreach ($items as $item): ?><label><?php echo $escape($item['product_name_snapshot']); ?><input type="number" min="1" max="1000000" step="1" required name="quantities[<?php echo (int)$item['id']; ?>]" value="<?php echo $escape(is_array($values['quantities'] ?? null) ? ($values['quantities'][$item['id']] ?? '') : ''); ?>"></label><?php endforeach; ?></div>
      </section>
      <section class="account-card"><h2><?php echo site_text('account.delivery'); ?></h2><div class="account-form-grid">
        <label><?php echo site_text('account.delivery'); ?><select name="delivery_method"><?php foreach (['PICKUP' => 'checkout.pickup','DELIVERY' => 'checkout.home_delivery'] as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo ($values['delivery_method'] ?? '') === $key ? ' selected' : ''; ?>><?php echo site_text($label); ?></option><?php endforeach; ?></select></label>
        <label><?php echo site_text('account.required'); ?><input type="date" name="required_date" required value="<?php echo $escape($values['required_date'] ?? ''); ?>"></label>
        <?php foreach (['street_address' => 255,'delegation' => 100,'governorate' => 100,'postal_code' => 4] as $key => $max): ?><label><?php echo site_text('account.' . $key); ?><input name="<?php echo $key; ?>" maxlength="<?php echo $max; ?>" value="<?php echo $escape($values[$key] ?? ''); ?>"<?php echo $key === 'postal_code' ? ' inputmode="numeric" pattern="[0-9]{4}"' : ''; ?>></label><?php endforeach; ?>
        <label class="account-form-wide"><?php echo site_text('account.notes'); ?><textarea name="customer_notes" maxlength="500" rows="3"><?php echo $escape($values['customer_notes'] ?? ''); ?></textarea></label>
      </div></section>
      <button class="account-button" name="action" value="preview"><?php echo site_text('account.review_changes'); ?></button>
    </form>
  <?php endif; ?>
</main>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
