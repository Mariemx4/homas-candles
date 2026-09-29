<?php declare(strict_types=1); ?>
<div class="admin-order-customer-picker" data-order-customer-picker data-endpoint="<?php echo BASE_URL . 'admin/orders/customers.php'; ?>">
  <input type="hidden" name="customer_id" value="<?php echo $escape($customerPickerId ?? ''); ?>" data-customer-id>
  <div class="admin-field">
    <label for="order-customer-search">Link a customer account</label>
    <div class="admin-order-customer-picker__search"><input id="order-customer-search" type="search" maxlength="100" placeholder="Search name, email, or phone" data-customer-search><button class="admin-button admin-button--secondary" type="button" data-customer-find>Search</button></div>
  </div>
  <p data-customer-selection><?php echo $escape(isset($selectedOrderCustomer) && is_array($selectedOrderCustomer) ? 'Linked account: ' . $selectedOrderCustomer['customer_name'] . ' · ' . $selectedOrderCustomer['customer_phone'] : 'Guest order — no account linked.'); ?></p>
  <div data-customer-results class="admin-order-customer-picker__results" aria-live="polite"></div>
  <div class="admin-order-customer-picker__actions">
    <button class="admin-button admin-button--secondary" type="button" data-customer-fill<?php echo empty($customerPickerId) ? ' disabled' : ''; ?>>Use account contact and address</button>
    <button class="admin-button admin-button--secondary" type="button" data-customer-clear>Guest order</button>
  </div>
  <small>Linking adds this order to the account’s order history. Order contact and address details can differ from the account.</small>
</div>
