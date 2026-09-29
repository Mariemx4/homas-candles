<?php

/**
 * FILE PURPOSE: Outer HTML layout for Footer; it provides the shared page shell and view helpers.
 *
 * DATA FLOW: The entry point prepares $contentView and page data, then requires this layout. Variables defined here, including $escape, are in scope when the layout requires the selected page view.
 */
// WHAT: the shared footer + closing </body></html>, require'd at the bottom
//       of every page that includes header.php.
// WHY:  `??=` here (instead of just reading $currentPage) means this file
//       still works even if a page somehow includes footer.php without
//       having included header.php first.
$currentPage ??= basename($_SERVER['PHP_SELF'] ?? '');
$isCategoryPage ??= str_contains(
  str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? ''),
  '/categories/'
);
?>

    <footer class="site-footer">
      <div class="site-footer__glow" aria-hidden="true"></div>

      <div class="site-footer__inner container">
        <div class="site-footer__intro">
          <a href="<?php echo BASE_URL . 'index.php'; ?>" class="site-footer__logo-link">
            <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>"
              alt="Hom'As Candles" class="site-footer__logo">
          </a>
          <p class="site-footer__eyebrow">Hand-poured in Tunisia</p>
        </div>

        <div class="site-footer__grid">
          <!-- SECTION PURPOSE: Group the Our story content, states, and controls as one accessible page region. -->
          <section class="site-footer__story" aria-labelledby="footer-about-title">
            <h2 id="footer-about-title">Our story</h2>
            <p>We create thoughtful candles that bring warmth, beauty, and a little ceremony to everyday life. Every Hom'As piece is handcrafted with care and made to feel truly special.</p>
          </section>

          <nav class="site-footer__nav" aria-labelledby="footer-explore-title">
            <h2 id="footer-explore-title">Explore</h2>
            <ul>
              <li><a href="<?php echo BASE_URL . 'index.php'; ?>">Home</a></li>
              <li><a href="<?php echo BASE_URL . 'shop.php'; ?>">Shop all</a></li>
              <li><a href="<?php echo BASE_URL . 'contact.php'; ?>">Contact</a></li>
              <li><a href="<?php echo BASE_URL . 'signup.php'; ?>">Create account</a></li>
            </ul>
          </nav>

          <!-- SECTION PURPOSE: Group the Let’s talk content, states, and controls as one accessible page region. -->
          <section class="site-footer__contact" aria-labelledby="footer-contact-title">
            <h2 id="footer-contact-title">Let’s talk</h2>
            <ul>
              <li>
                <span>Email</span>
                <a href="mailto:homascandles@gmail.com">homascandles@gmail.com</a>
              </li>
              <li>
                <span>Phone</span>
                <a href="tel:+21628713024">+216 28 713 024</a>
              </li>
              <li>
                <span>Delivery</span>
                <p>Available across Tunisia</p>
              </li>
            </ul>
          </section>

          <!-- SECTION PURPOSE: Group the Payment content, states, and controls as one accessible page region. -->
          <section class="site-footer__payments" aria-labelledby="footer-payment-title">
            <h2 id="footer-payment-title">
              <i data-lucide="credit-card"></i>
              Payment
            </h2>
            <p>Simple and secure ways to complete your order.</p>
            <ul class="site-footer__payment-list">
              <li>D17</li>
              <li>Cash on delivery</li>
            </ul>
          </section>
        </div>

        <div class="site-footer__bottom">
          <p>&copy; <?= date('Y') ?> Hom'As Candles. All rights reserved.</p>

          <p class="site-footer__signature">
            <i data-lucide="sparkles" aria-hidden="true"></i>
            Light the moment, smell the memory.
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
      src="<?php echo BASE_URL . 'assets/js/main.js'; ?>">
    </script>

    <script
      type="module"
      src="<?php echo BASE_URL . 'assets/js/language_picker.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/language_picker.js'); ?>">
    </script>

    <?php // WHAT: load each page's own JS module only on that page. ?>
    <?php // WHY:  mirrors the per-page CSS loading in header.php — a page ?>
    <?php //       never downloads JS it doesn't need. ?>
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

    <?php if (isset($currentPage) && in_array($currentPage, ['login.php', 'signup.php', 'forgot-password.php', 'reset-password.php'], true)): ?>
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
