<?php


declare(strict_types=1);

use App\View\Presentation;

require_once __DIR__ . '/../../app/Bootstrap/admin/administrator-auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $administratorAuthController->login($_POST);
}
if ($administratorSession->isAuthenticated()) {
    header('Location: ' . BASE_URL . 'admin/index.php', true, 303);
    exit;
}

$formState = $authFlash->consume('administrator_login');
$errors = $formState['errors'];
$values = $formState['values'];
$message = null;
if (isset($_GET['session_expired'])) {
    $errors['form'] = 'Your administrator session expired. Please sign in again.';
} elseif (isset($_GET['access_revoked'])) {
    $errors['form'] = 'Your administrator access is no longer active.';
} elseif (isset($_GET['signed_out'])) {
    $message = 'You have signed out safely.';
}

$escape = Presentation::escape(...);
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field])
  ? $errors[$field]
  : null;
$generalError = $fieldError('authentication') ?? $fieldError('form');
$googleButtonConfig = require APP_PATH . '/config/google-auth.php';
$googleFormCsrf = new \App\Security\CsrfTokenManager(independentForms: true);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Administrator sign in | Hom'As Candles</title>
  <?php foreach (App\Services\AdminAssets::styles(dirname(__DIR__)) as $stylesheet): ?>
  <link rel="stylesheet" href="<?php echo $escape(BASE_URL . $stylesheet); ?>">
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?php echo $escape(BASE_URL . 'assets/css/components/social-auth.css?v=' . substr(hash_file('sha256', PUBLIC_PATH . '/assets/css/components/social-auth.css'), 0, 12)); ?>">
</head>

<body class="admin-body">
  <main class="admin-login">
    <section class="admin-login__card" aria-labelledby="admin-login-title">
      <a class="admin-brand" href="<?php echo BASE_URL . 'index.php'; ?>">
        <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>" alt="Hom'As Candles">
      </a>
      <h1 id="admin-login-title">Administrator sign in</h1>
      <p class="admin-muted">Use your approved staff account to continue.</p>

      <?php if ($generalError !== null): ?>
        <div class="admin-alert admin-alert--error" role="alert"><?php echo $escape($generalError); ?></div>
      <?php elseif ($message !== null): ?>
        <div class="admin-alert admin-alert--success" role="status"><?php echo $escape($message); ?></div>
      <?php endif; ?>

      <?php if ($googleButtonConfig->available()): ?>
        <div class="social-auth">
          <form method="post" action="<?php echo $escape(BASE_URL . 'admin/google-start.php'); ?>" class="social-auth__form">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($googleFormCsrf->token()); ?>">
            <button type="submit" class="google-sign-in">
              <img src="<?php echo $escape(BASE_URL . 'assets/brand/google-g.png'); ?>" width="20" height="20" alt="" aria-hidden="true">
              <span>Continue with Google</span>
            </button>
          </form>
          <p class="social-auth__divider"><span>or use your staff password</span></p>
        </div>
      <?php endif; ?>

      <form class="admin-form" action="<?php echo BASE_URL . 'admin/login.php'; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <label for="login_identifier">Email or phone number</label>
        <input id="login_identifier" name="login_identifier" type="text" maxlength="255"
          autocomplete="username" required autofocus
          value="<?php echo $escape($values['login_identifier'] ?? ''); ?>"
          <?php echo $fieldError('login_identifier') !== null ? 'aria-invalid="true" aria-describedby="identifier-error"' : ''; ?>>
        <?php if ($fieldError('login_identifier') !== null): ?>
          <p class="admin-field-error" id="identifier-error"><?php echo $escape($fieldError('login_identifier')); ?></p>
        <?php endif; ?>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="4096"
          autocomplete="current-password" required
          <?php echo $fieldError('password') !== null ? 'aria-invalid="true" aria-describedby="password-error"' : ''; ?>>
        <?php if ($fieldError('password') !== null): ?>
          <p class="admin-field-error" id="password-error"><?php echo $escape($fieldError('password')); ?></p>
        <?php endif; ?>

        <button type="submit">Sign in securely</button>
      </form>
      <p class="admin-security-note">For your security, this workspace signs out after <?php echo $administratorSession->inactivityMinutes(); ?> minutes without activity.</p>
    </section>
  </main>
</body>

</html>
