<?php

declare(strict_types=1);

use App\View\CustomerAccountView;

// Authentication belongs to the route; this fragment receives display-safe data.
$accountNavActive ??= 'overview';
$accountNavItems = [
    ['overview', 'home', 'account.overview', 'account.php'],
    ['orders', 'bag', 'account.orders', 'account.php#orders'],
    ['profile', 'user', 'account.profile_nav', 'profile.php'],
    ['addresses', 'pin', 'account.addresses', 'profile.php#addresses'],
];
?>
<aside class="account-sidebar">
  <div class="account-identity">
    <span class="account-avatar" aria-hidden="true"><?php echo $escape(CustomerAccountView::initials($account)); ?></span>
    <div><strong><?php echo $escape($account['first_name'] . ' ' . $account['last_name']); ?></strong><span><?php echo site_text('nav.your_space'); ?></span></div>
  </div>
  <nav class="account-navigation" aria-label="<?php echo site_text('account.nav_label'); ?>">
    <?php foreach ($accountNavItems as [$key, $icon, $label, $route]): ?>
      <a href="<?php echo $escape(BASE_URL . $route); ?>"<?php echo $accountNavActive === $key ? ' aria-current="page"' : ''; ?>>
        <?php echo CustomerAccountView::icon($icon); ?><span><?php echo site_text($label); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="account-support">
    <?php echo CustomerAccountView::icon('help'); ?><strong><?php echo site_text('account.help_title'); ?></strong>
    <p><?php echo site_text('account.help_copy'); ?></p>
    <a href="<?php echo $escape(BASE_URL . 'contact.php'); ?>"><?php echo site_text('account.contact_us'); ?> <?php echo CustomerAccountView::icon('arrow'); ?></a>
  </div>
</aside>
