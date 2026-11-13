<?php

// Shared public head and navigation. Catalogue pages pass their already
// fetched categories; every other page gets them from SiteNavigation.
require_once __DIR__ . '/../../Bootstrap/session.php';
$locale = \App\Services\Locale::current();

$currentPage = basename($_SERVER['PHP_SELF']);
$isCategoryPage = $currentPage === 'category.php'
  || str_contains(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/categories/');
if (!isset($catalogueCategories)) {
    $catalogueCategories = \App\View\SiteNavigation::categories($locale);
}
$discovery = \App\Services\PublicMetadata::forPage(
    $currentPage,
    $isCategoryPage,
    $locale,
    BASE_URL,
    $_SERVER,
    $_GET,
    (int) http_response_code(),
    is_array($categoryPage ?? null) ? $categoryPage : null,
    is_array($product ?? null) ? $product : null,
    is_array($scents ?? null) ? $scents : []
);
?>

<!DOCTYPE html>
<html lang="<?php echo $locale; ?>" dir="<?php echo $locale === 'ar' ? 'rtl' : 'ltr'; ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle ?? "Hom'As Candles"); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($discovery['description'], ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="robots" content="<?php echo $discovery['robots']; ?>">
  <?php if ($discovery['canonical'] !== null): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($discovery['canonical'], ENT_QUOTES, 'UTF-8'); ?>">
  <?php endif; ?>
  <?php if ($discovery['robots'] === 'index, follow' && $discovery['canonical'] !== null): ?>
    <meta property="og:type" content="<?php echo $discovery['type']; ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle ?? "Hom'As Candles", ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($discovery['description'], ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($discovery['canonical'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($discovery['image'] !== null): ?>
      <meta property="og:image" content="<?php echo htmlspecialchars($discovery['image'], ENT_QUOTES, 'UTF-8'); ?>">
      <meta name="twitter:card" content="summary_large_image">
      <meta name="twitter:image" content="<?php echo htmlspecialchars($discovery['image'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle ?? "Hom'As Candles", ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($discovery['description'], ENT_QUOTES, 'UTF-8'); ?>">
  <?php endif; ?>
  <?php if ($discovery['structuredData'] !== null): ?>
    <script type="application/ld+json"><?php echo json_encode($discovery['structuredData'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
  <?php endif; ?>
  <link rel="stylesheet" href="
      <?php echo BASE_URL . 'assets/css/style.css'; ?>
    ">
  <?php // Page-specific CSS uses filemtime for cache invalidation.?>
  <?php if (in_array($currentPage, ['login.php', 'forgot-password.php', 'reset-password.php'], true)): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/login.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/login.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'reset-password.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/reset-password.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/reset-password.css'); ?>">
  <?php endif; ?>
  <?php if (in_array($currentPage, ['signup.php', 'google-complete.php', 'google-link.php'], true)): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/signup.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/signup.css'); ?>">
  <?php endif; ?>
  <?php if (in_array($currentPage, ['login.php', 'signup.php', 'google-complete.php', 'google-link.php'], true)): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/components/social-auth.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/components/social-auth.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'contact.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/contact.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/contact.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'index.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/home.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/home.css'); ?>">
  <?php endif; ?>
  <?php // Checkout and its confirmation reuse the cart layout, then add their own form rules.?>
  <?php if (in_array($currentPage, ['cart.php', 'checkout.php', 'order-confirmation.php'], true)): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/cart.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/cart.css'); ?>">
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/checkout.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/checkout.css'); ?>">
  <?php endif; ?>
  <?php if (in_array($currentPage, ['account.php', 'account-order.php', 'account-order-edit.php', 'profile.php', 'order-payment.php'], true)): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/account.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/account.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'shop.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/shop.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/shop.css'); ?>">
  <?php endif; ?>
  <?php if ($isCategoryPage): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/category.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/category.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'shop.php' || $isCategoryPage): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/components/product-card.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/components/product-card.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'product.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/product.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/product.css'); ?>">
  <?php endif; ?>
  <?php if ($locale === 'ar'): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/rtl.css'; ?>">
  <?php endif; ?>
</head>

<body>
  <a class="site-skip-link" href="#main-content"><?php echo site_text('nav.skip_to_content'); ?></a>
  <header class="site-header">
    <nav class="site-nav container" aria-label="Main navigation">
      <div class="header-top">
        <div class="header-tools">
          <!-- WHAT: the "browse categories" slide-out drawer, opened/closed by floating_menus.js -->
          <div class="discovery-menu">
            <button class="header-icon-button category-menu category-menu-trigger" aria-label="<?php echo site_text('nav.categories'); ?>"
              aria-expanded="false" aria-controls="categories-drawer" type="button">
              <svg class="top-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <section class="categories-panel" id="categories-drawer" aria-label="<?php echo site_text('nav.categories'); ?>" hidden>
              <div class="drawer-header">
                <div class="drawer-heading">
        <span><?php echo site_text('nav.collection'); ?></span>
                  <h2><?php echo site_text('nav.shop_by_category'); ?></h2>
                </div>
                <button class="header-icon-button close-category-menu" type="button" aria-label="<?php echo site_text('nav.close_categories'); ?>">
                  <svg class="top-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
                </button>
              </div>
              <nav class="category-links" aria-label="Product categories">
                <ul>
                  <?php foreach ($catalogueCategories as $index => $category): ?>
                    <li><a href="<?php echo htmlspecialchars(BASE_URL . $category['path'], ENT_QUOTES, 'UTF-8'); ?>"><span><?php echo sprintf('%02d', $index + 1); ?></span><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?><i data-lucide="arrow-up-right"></i></a></li>
                  <?php endforeach; ?>
                </ul>
              </nav>
              <div class="drawer-footer">
                <div class="drawer-socials">
                  <a href="https://www.facebook.com/profile.php?id=61574948525165" target="_blank"
                    rel="noopener noreferrer" aria-label="Visit Hom'As on Facebook">
                    <img src="<?php echo BASE_URL . 'assets/icons/facebook.png'; ?>" alt="">
                  </a>
                  <a href="https://www.instagram.com/homas_candles/" target="_blank"
                    rel="noopener noreferrer" aria-label="Follow Hom'As on Instagram">
                    <img src="<?php echo BASE_URL . 'assets/icons/instagram.png'; ?>" alt="">
                  </a>
                </div>
                <p>&copy; <?= date('Y') ?> Hom'As Candles</p>
              </div>
            </section>
          </div>

          <form class="search" action="<?php echo BASE_URL . 'shop.php'; ?>" method="GET"
            data-site-search>
            <input type="hidden" name="lang" value="<?php echo $locale; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
            <input class="search_input" type="search" id="site-search" name="query" maxlength="100"
              value="<?php echo htmlspecialchars((string) ($selectedSearch ?? '')); ?>"
              placeholder="<?php echo site_text('nav.search_placeholder'); ?>" aria-label="<?php echo site_text('nav.search'); ?>" required>
            <button type="submit" aria-label="<?php echo site_text('nav.search_submit'); ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </button>
            <span class="search__status" data-search-status aria-live="polite"></span>
          </form>
        </div>

        <a href="<?php echo BASE_URL . 'index.php'; ?>" class="logo-link">
          <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>"
            alt="Hom'As Candles Logo" class="logo">
        </a>

        <div class="header-actions">
          <!-- WHAT: the EN/FR/AR switcher, wired up by language_picker.js -->
          <div class="language-picker" data-language-picker>
            <button class="language-picker__trigger" type="button" aria-label="<?php echo site_text('nav.choose_language'); ?>"
              aria-expanded="false" aria-controls="language-menu">
              <i data-lucide="languages" aria-hidden="true"></i>
              <span class="language-picker__code" data-language-code><?php echo strtoupper($locale); ?></span>
              <i data-lucide="chevron-down" class="language-picker__chevron" aria-hidden="true"></i>
            </button>

            <div class="language-menu" id="language-menu" role="listbox"
              aria-label="<?php echo site_text('nav.available_languages'); ?>" data-language-menu hidden>
              <button type="button" role="option" aria-selected="<?php echo $locale === 'en' ? 'true' : 'false'; ?>"
                data-language-option data-value="en" data-code="EN" data-name="English">
                <span>EN</span><strong>English</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
              <button type="button" role="option" aria-selected="<?php echo $locale === 'fr' ? 'true' : 'false'; ?>"
                data-language-option data-value="fr" data-code="FR" data-name="Français">
                <span>FR</span><strong>Français</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
              <button type="button" role="option" aria-selected="<?php echo $locale === 'ar' ? 'true' : 'false'; ?>"
                data-language-option data-value="ar" data-code="AR" data-name="العربية">
                <span>AR</span><strong lang="ar" dir="rtl">العربية</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
            </div>

          </div>
          <button class="header-icon-button account-menu account-menu-trigger" aria-label="<?php echo site_text('nav.account_menu'); ?>"
            aria-expanded="false" aria-controls="account-drawer" type="button">
            <svg class="top-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
          </button>
          <!-- WHAT: the same drawer changes with the centralized customer session. -->
          <!-- WHY:  authenticated customers get a protected POST logout instead of a GET link. -->
          <section class="account-panel" id="account-drawer" aria-label="<?php echo site_text('nav.account_menu'); ?>" hidden>
            <div class="account-panel__header">
              <div>
                <span><?php echo site_text('nav.your_space'); ?></span>
                <h2><?php echo site_text($customerSession->isAuthenticated() ? 'nav.your_account' : 'nav.welcome'); ?></h2>
              </div>
              <button class="header-icon-button account-drawer-button" type="button" aria-label="<?php echo site_text('nav.close_account'); ?>">
                <svg class="top-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
              </button>
            </div>
            <nav class="account-menu-panel">
              <ul>
                <?php if ($customerSession->isAuthenticated()): ?>
                  <li><a href="<?php echo BASE_URL . 'account.php'; ?>"><?php echo site_text('account.title'); ?><i data-lucide="user-round" aria-hidden="true"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'account.php#orders'; ?>"><?php echo site_text('account.orders'); ?><i data-lucide="package" aria-hidden="true"></i></a></li>
                  <li>
                    <form action="<?php echo BASE_URL . 'logout.php'; ?>" method="POST" class="account-menu-panel__form">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
                      <button type="submit"><?php echo site_text('nav.sign_out'); ?><i data-lucide="log-out" aria-hidden="true"></i></button>
                    </form>
                  </li>
                <?php else: ?>
                  <li><a href="<?php echo BASE_URL . 'login.php'; ?>"><?php echo site_text('nav.sign_in'); ?><i data-lucide="user-round-arrow-left"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'signup.php'; ?>"><?php echo site_text('nav.create_account'); ?><i data-lucide="user-round-plus"></i></a></li>
                <?php endif; ?>
              </ul>
            </nav>
            <p class="account-panel__note"><?php echo site_text($customerSession->isAuthenticated()
                  ? 'account.note_signed_in' : 'account.note_guest'); ?></p>
          </section>
        </div>
      </div>

      <ul class="site-nav-list">
        <li>
          <a href="<?php echo BASE_URL . 'index.php'; ?>"
            <?php echo $currentPage === 'index.php' ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="HouseHeart"></i>
            <span><?php echo site_text('nav.home'); ?></span></a>
        </li>
        <li>
          <a href="<?php echo BASE_URL . 'shop.php'; ?>"
            <?php echo ($currentPage === 'shop.php' || $currentPage === 'product.php' || $isCategoryPage) ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="Store" class="site-header-icon"></i>
            <span><?php echo site_text('nav.shop'); ?></span></a>
        </li>
        <li>
          <a href="<?php echo BASE_URL . 'cart.php'; ?>"
            <?php echo $currentPage === 'cart.php' ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="shopping-cart" class="site-header-icon"></i>
            <span><?php echo site_text('nav.cart'); ?></span></a>
        </li>
      </ul>
      <!--<p>Delivery for all Tunisia for 9DT</p>-->
    </nav>
  </header>

  <!-- we don't close the body and html tags here because we want to include the footer.php file which will close them -->
