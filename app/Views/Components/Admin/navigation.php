<?php

declare(strict_types=1);

use App\View\AdminNavigation;
use App\View\AdminIcons;

$activeNavigation ??= 'dashboard';
$navigationGroups = AdminNavigation::sidebarGroups(); ?>
<aside class="admin-sidebar" id="admin-navigation" aria-label="Administrator navigation">
  <div class="admin-sidebar__brand-row"><a class="admin-brand admin-brand--shell" href="<?php echo BASE_URL; ?>admin/index.php" aria-label="Hom’As — Today"><img src="<?php echo BASE_URL; ?>assets/brand/logo.png" alt="Hom’As Candles"><span class="admin-brand-caption">THE WORKSPACE</span></a><button class="admin-nav-close" type="button" aria-label="Close navigation">&times;</button></div>
  <nav class="admin-nav" aria-label="Main">
    <?php foreach ($navigationGroups as $label => $items): ?><div class="admin-nav-group">
        <p class="admin-nav__label"><?php echo $escape($label); ?></p>
        <ul>
          <?php foreach ($items as [$key, $name, $icon, $path]): ?><li><a class="admin-nav__item<?php echo $key === $activeNavigation ? ' admin-nav__item--current' : ''; ?>" href="<?php echo $escape(BASE_URL . $path); ?>" <?php echo $key === $activeNavigation ? ' aria-current="page"' : ''; ?>><span class="admin-nav__icon"><?php echo AdminIcons::render($icon); ?></span><span><?php echo $escape($name); ?></span><?php if ($key === $activeNavigation): ?><span class="admin-nav-current-dot" aria-hidden="true"></span><?php endif; ?></a></li><?php endforeach; ?>
        </ul>
      </div><?php endforeach; ?></nav>
  <div class="admin-sidebar__footer"><a class="admin-storefront-link" href="<?php echo BASE_URL; ?>index.php"><?php echo AdminIcons::render('store'); ?><span>View storefront</span><span aria-hidden="true">↗</span></a>
    <div class="admin-sidebar__identity"><span class="admin-identity__avatar" aria-hidden="true"><?php echo $escape($administratorInitials); ?></span><span><strong><?php echo $escape($administratorName); ?></strong><small>Hom’As team</small></span></div>
    <form action="<?php echo BASE_URL; ?>admin/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><button class="admin-signout" type="submit"><?php echo AdminIcons::render('logout'); ?> Sign out</button></form>
  </div>
</aside>