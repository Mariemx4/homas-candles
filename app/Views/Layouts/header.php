<?php
// WHAT: the shared <head> + top navigation, require'd at the top of every page.
// WHY:  keeps the logo/menu/search/language-picker/nav markup in one place —
//       nobody else in the app duplicates this HTML.
require_once __DIR__ . '/../../Bootstrap/session.php';

// WHAT: figures out which page is currently rendering.
// WHY:  drives which page-specific CSS gets loaded below, and which nav
//       link gets the "active" class further down.
$currentPage = basename($_SERVER['PHP_SELF']);
$isCategoryPage = str_contains(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/categories/');
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle ?? "Hom'As Candles"); ?></title>
  <link rel="stylesheet" href="
      <?php echo BASE_URL . 'assets/css/style.css'; ?>
    ">
  <?php // WHAT: load each page's own stylesheet only on that page. ?>
  <?php // WHY:  keeps every page's CSS out of every other page's request; ?>
  <?php //       filemtime() in the query string busts the browser cache ?>
  <?php //       whenever that specific file changes. ?>
  <?php if (in_array($currentPage, ['login.php', 'forgot-password.php', 'reset-password.php'], true)): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/login.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/login.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'reset-password.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/reset-password.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/reset-password.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'signup.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/signup.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/signup.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'contact.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/contact.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/contact.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'index.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/home.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/home.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'cart.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/cart.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/cart.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'shop.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/shop.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/shop.css'); ?>">
  <?php endif; ?>
  <?php if ($isCategoryPage): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/category.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/category.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'shop.php' || $isCategoryPage): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/components/product-card.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/components/product-card.css'); ?>">
  <?php endif; ?>
  <?php if ($currentPage === 'product.php'): ?>
    <link rel="stylesheet"
      href="<?php echo BASE_URL . 'assets/css/pages/product.css?v=' . filemtime(__DIR__ . '/../../../public/assets/css/pages/product.css'); ?>">
  <?php endif; ?>
</head>

<body>
  <header class="site-header">
    <nav class="site-nav container" aria-label="Main navigation">
      <div class="header-top">
        <div class="header-tools">
          <!-- WHAT: the "browse categories" slide-out drawer, opened/closed by floating_menus.js -->
          <div class="discovery-menu">
            <button class="header-icon-button category-menu category-menu-trigger" aria-label="Browse categories"
              aria-expanded="false" aria-controls="categories-drawer" type="button">
              <i data-lucide="menu" class="top-header-icon"></i>
            </button>
            <section class="categories-panel" id="categories-drawer" aria-label="Product categories" hidden>
              <div class="drawer-header">
                <div class="drawer-heading">
                  <span>Hom'As collection</span>
                  <h2>Shop by category</h2>
                </div>
                <button class="header-icon-button close-category-menu" type="button" aria-label="Close categories menu">
                  <i data-lucide="x" class="top-header-icon"></i>
                </button>
              </div>
              <nav class="category-links" aria-label="Product categories">
                <ul>
                  <li><a href="<?php echo BASE_URL . 'categories/candles.php'; ?>"><span>01</span>Candles<i data-lucide="arrow-up-right"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'categories/candle-sets.php'; ?>"><span>02</span>Candle sets<i data-lucide="arrow-up-right"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'categories/candle-bouquets.php'; ?>"><span>03</span>Candle bouquets<i data-lucide="arrow-up-right"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'categories/event-favors.php'; ?>"><span>04</span>Event Favors<i data-lucide="arrow-up-right"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'categories/home-diffusers.php'; ?>"><span>05</span>Home diffusers<i data-lucide="arrow-up-right"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'categories/accessories.php'; ?>"><span>06</span>Accessories<i data-lucide="arrow-up-right"></i></a></li>
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
            <i data-lucide="search" aria-hidden="true"></i>
            <input class="search_input" type="search" id="site-search" name="query"
              placeholder="Search candles..." aria-label="Search for a product" required>
            <button type="submit" aria-label="Submit search">
              <i data-lucide="arrow-right"></i>
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
            <button class="language-picker__trigger" type="button" aria-label="Choose language"
              aria-expanded="false" aria-controls="language-menu">
              <i data-lucide="languages" aria-hidden="true"></i>
              <span class="language-picker__code" data-language-code>EN</span>
              <i data-lucide="chevron-down" class="language-picker__chevron" aria-hidden="true"></i>
            </button>

            <div class="language-menu" id="language-menu" role="listbox"
              aria-label="Available languages" data-language-menu hidden>
              <button type="button" role="option" aria-selected="true"
                data-language-option data-value="en" data-code="EN" data-name="English">
                <span>EN</span><strong>English</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
              <button type="button" role="option" aria-selected="false"
                data-language-option data-value="fr" data-code="FR" data-name="Français">
                <span>FR</span><strong>Français</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
              <button type="button" role="option" aria-selected="false"
                data-language-option data-value="ar" data-code="AR" data-name="العربية">
                <span>AR</span><strong lang="ar" dir="rtl">العربية</strong><i data-lucide="check" aria-hidden="true"></i>
              </button>
            </div>

            <input type="hidden" name="language" value="en" data-language-input>
          </div>
          <button class="header-icon-button account-menu account-menu-trigger" aria-label="Account menu"
            aria-expanded="false" aria-controls="account-drawer" type="button">
            <i data-lucide="user-round" class="top-header-icon"></i>
          </button>
          <!-- WHAT: the same drawer changes with the centralized customer session. -->
          <!-- WHY:  authenticated customers get a protected POST logout instead of a GET link. -->
          <section class="account-panel" id="account-drawer" hidden>
            <div class="account-panel__header">
              <div>
                <span>Your Hom'As space</span>
                <h2><?php echo $customerSession->isAuthenticated() ? 'Your account' : 'Welcome'; ?></h2>
              </div>
              <button class="header-icon-button account-drawer-button" type="button" aria-label="Close account menu">
                <i data-lucide="X" class="top-header-icon"></i>
              </button>
            </div>
            <nav class="account-menu-panel">
              <ul>
                <?php if ($customerSession->isAuthenticated()): ?>
                  <li>
                    <form action="<?php echo BASE_URL . 'logout.php'; ?>" method="POST" class="account-menu-panel__form">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
                      <button type="submit">Sign out<i data-lucide="log-out" aria-hidden="true"></i></button>
                    </form>
                  </li>
                <?php else: ?>
                  <li><a href="<?php echo BASE_URL . 'login.php'; ?>">Sign in<i data-lucide="user-round-arrow-left"></i></a></li>
                  <li><a href="<?php echo BASE_URL . 'signup.php'; ?>">Create account<i data-lucide="user-round-plus"></i></a></li>
                <?php endif; ?>
              </ul>
            </nav>
            <p class="account-panel__note"><?php echo $customerSession->isAuthenticated()
                ? 'Your customer session is active and protected.'
                : 'Save favourites and keep track of every handmade treasure.'; ?></p>
          </section>
        </div>
      </div>

      <ul class="site-nav-list">
        <li>
          <a href="<?php echo BASE_URL . 'index.php'; ?>"
            <?php echo $currentPage === 'index.php' ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="HouseHeart"></i>
            <span>Home</span></a>
        </li>
        <li>
          <a href="<?php echo BASE_URL . 'shop.php'; ?>"
            <?php echo ($currentPage === 'shop.php' || $currentPage === 'product.php' || $isCategoryPage) ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="Store" class="site-header-icon"></i>
            <span>Shop</span></a>
        </li>
        <li>
          <a href="<?php echo BASE_URL . 'cart.php'; ?>"
            <?php echo $currentPage === 'cart.php' ? 'class="active" aria-current="page"' : ''; ?>>
            <i data-lucide="shopping-cart" class="site-header-icon"></i>
            <span>Cart</span></a>
        </li>
      </ul>
      <!--<p>Delivery for all Tunisia for 9DT</p>-->
    </nav>
  </header>

  <!-- we don't close the body and html tags here because we want to include the footer.php file which will close them -->
