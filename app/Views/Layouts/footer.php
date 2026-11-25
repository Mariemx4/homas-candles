<?php


// WHAT: the shared footer + closing </body></html>, require'd at the bottom
//       of every page that includes header.php.
// WHY:  `??=` here (instead of just reading $currentPage) means this file
//       still works even if a page somehow includes footer.php without
//       having included header.php first.
$currentPage ??= basename($_SERVER['PHP_SELF'] ?? '');
$isCategoryPage ??= $currentPage === 'category.php';
?>

    <footer class="site-footer">
      <div class="site-footer__glow" aria-hidden="true"></div>

      <div class="site-footer__inner container">
        <div class="site-footer__intro">
          <a href="<?php echo BASE_URL . 'index.php'; ?>" class="site-footer__logo-link">
            <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>"
              alt="Hom'As Candles" class="site-footer__logo">
          </a>
          <p class="site-footer__eyebrow"><?php echo site_text('footer.hand_poured'); ?></p>
        </div>

        <div class="site-footer__grid">
          <section class="site-footer__story" aria-labelledby="footer-about-title">
            <h2 id="footer-about-title"><?php echo site_text('footer.story'); ?></h2>
            <p><?php echo site_text('footer.story_body'); ?></p>
          </section>

          <nav class="site-footer__nav" aria-labelledby="footer-explore-title">
            <h2 id="footer-explore-title"><?php echo site_text('footer.explore'); ?></h2>
            <ul>
              <li><a href="<?php echo BASE_URL . 'index.php'; ?>"><?php echo site_text('nav.home'); ?></a></li>
              <li><a href="<?php echo BASE_URL . 'shop.php'; ?>"><?php echo site_text('footer.shop_all'); ?></a></li>
              <li><a href="<?php echo BASE_URL . 'contact.php'; ?>"><?php echo site_text('nav.contact'); ?></a></li>
              <li><a href="<?php echo BASE_URL . 'signup.php'; ?>"><?php echo site_text('nav.create_account'); ?></a></li>
            </ul>
          </nav>

          <section class="site-footer__contact" aria-labelledby="footer-contact-title">
            <h2 id="footer-contact-title"><?php echo site_text('footer.talk'); ?></h2>
            <ul>
              <li>
                <span><?php echo site_text('footer.email'); ?></span>
                <a href="mailto:homascandles@gmail.com">homascandles@gmail.com</a>
              </li>
              <li>
                <span><?php echo site_text('footer.phone'); ?></span>
                <a href="tel:+21628713024">+216 28 713 024</a>
              </li>
              <li>
                <span><?php echo site_text('footer.delivery'); ?></span>
                <p><?php echo site_text('footer.delivery_body'); ?></p>
              </li>
            </ul>
          </section>

          <section class="site-footer__payments" aria-labelledby="footer-payment-title">
            <h2 id="footer-payment-title">
              <i data-lucide="credit-card"></i>
              <?php echo site_text('footer.payment'); ?>
            </h2>
            <p><?php echo site_text('footer.payment_body'); ?></p>
            <ul class="site-footer__payment-list">
              <li>D17</li>
              <li><?php echo site_text('footer.cash'); ?></li>
            </ul>
          </section>
        </div>

        <div class="site-footer__bottom">
          <p>&copy; <?= date('Y') ?> Hom'As Candles. <?php echo site_text('footer.rights'); ?></p>

          <p class="site-footer__signature">
            <i data-lucide="sparkles" aria-hidden="true"></i>
            <?php echo site_text('footer.tagline'); ?>
            <i data-lucide="sparkles" aria-hidden="true"></i>
          </p>

          <div class="site-footer__socials" aria-label="Social media">
            <a href="https://www.facebook.com/profile.php?id=61574948525165"
              target="_blank" rel="noopener noreferrer" aria-label="Visit Hom'As on Facebook">
              <img src="<?php echo BASE_URL . 'assets/icons/facebook.png'; ?>" alt="">
              <span>Facebook</span>
            </a>
            <a href="https://www.instagram.com/homas_candles/"
              target="_blank" rel="noopener noreferrer" aria-label="Follow Hom'As on Instagram">
              <img src="<?php echo BASE_URL . 'assets/icons/instagram.png'; ?>" alt="">
              <span>Instagram</span>
            </a>
          </div>
        </div>
      </div>
    </footer>

    <script
      type="module"
      src="<?php echo BASE_URL . 'assets/js/main.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/main.js'); ?>">
    </script>

    <script
      type="module"
      src="<?php echo BASE_URL . 'assets/js/language_picker.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/language_picker.js'); ?>">
    </script>

    <?php // WHAT: load each page's own JS module only on that page.?>
    <?php // WHY:  mirrors the per-page CSS loading in header.php — a page?>
    <?php //       never downloads JS it doesn't need.?>
    <?php if (isset($currentPage) && $currentPage === 'cart.php'): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/cart.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/cart.js'); ?>">
      </script>
    <?php endif; ?>

    <?php if (isset($currentPage) && $currentPage === 'checkout.php'): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/checkout.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/checkout.js'); ?>">
      </script>
    <?php endif; ?>

    <?php if (isset($currentPage) && $currentPage === 'product.php'): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/product.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/product.js'); ?>">
      </script>
    <?php endif; ?>

    <?php if (isset($currentPage) && ($currentPage === 'shop.php' || $isCategoryPage)): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/catalogue.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/catalogue.js'); ?>">
      </script>
    <?php endif; ?>

    <?php if (isset($currentPage) && in_array($currentPage, ['login.php', 'signup.php', 'forgot-password.php', 'reset-password.php', 'google-complete.php'], true)): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/auth.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/auth.js'); ?>">
      </script>
    <?php endif; ?>

    <?php if (isset($currentPage) && $currentPage === 'contact.php'): ?>
      <script
        type="module"
        src="<?php echo BASE_URL . 'assets/js/contact.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/contact.js'); ?>">
      </script>
    <?php endif; ?>

    </body>

    </html>
