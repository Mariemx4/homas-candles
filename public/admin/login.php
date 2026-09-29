<?php


declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Login.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../app/Bootstrap/admin/administrator-auth.php';

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $administratorAuthController->login($_POST);
}
if ($administratorSession->isAuthenticated()) {
  // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
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

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldError = static fn(string $field): ?string => isset($errors[$field]) && is_string($errors[$field])
  ? $errors[$field]
  : null;
$generalError = $fieldError('authentication') ?? $fieldError('form');
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Administrator sign in | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/admin.css?v=' . filemtime(__DIR__ . '/../assets/css/pages/admin.css'); ?>">
</head>

<body class="admin-body">
  <main class="admin-login">
    <!-- SECTION PURPOSE: Group the Administrator sign in content, states, and controls as one accessible page region. -->
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

      <!-- FORM PURPOSE: Submit form with POST; the receiving PHP route must validate the values before using them. -->
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
      <p class="admin-security-note">Administrator sessions expire after 15 minutes of inactivity.</p>
    </section>
  </main>
</body>

</html>
