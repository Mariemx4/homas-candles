<?php

declare(strict_types=1);

// Missing credentials must never leave a pretend sign-in button in the UI.
$googleButtonConfig = require APP_PATH . '/config/google-auth.php';
if (!$googleButtonConfig->available()) {
    return;
}
?>
<div class="social-auth">
  <form method="post" action="<?php echo $escape(BASE_URL . 'auth/google-start.php'); ?>" class="social-auth__form">
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
    <input type="hidden" name="origin" value="<?php echo $currentPage === 'signup.php' ? 'signup.php' : 'login.php'; ?>">
    <button type="submit" class="google-sign-in">
      <!-- Official current Google mark; keep its colors and aspect ratio intact. -->
      <img src="<?php echo $escape(BASE_URL . 'assets/brand/google-g.png'); ?>" width="20" height="20" alt="" aria-hidden="true">
      <span dir="auto"><?php echo site_text('google.continue'); ?></span>
    </button>
  </form>
  <p class="social-auth__divider"><span><?php echo site_text('google.or_email'); ?></span></p>
</div>
