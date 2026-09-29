<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Forgot Password.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
        require_once __DIR__ . '/../app/Bootstrap/password-reset.php';
        $passwordResetController->requestCode($_POST);
    } catch (Throwable) {
        // Handle a database/mailer bootstrap failure without exposing internals.
        error_log('Password reset bootstrap failed.');
        // ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
        require_once __DIR__ . '/../app/Bootstrap/session.php';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        (new \App\Auth\AuthFormFlash())->put(
            'forgot-password',
            ['form' => 'Password reset is temporarily unavailable. Please try again.'],
            ['email' => $email]
        );
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'forgot-password.php', true, 303);
        exit;
    }
}

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../app/Bootstrap/session.php';

$formState = (new \App\Auth\AuthFormFlash())->consume('forgot-password');
$errors = $formState['errors'];
$values = $formState['values'];
$state = $errors === [] ? 'empty' : 'error';
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$emailError = isset($errors['email']) ? (string) $errors['email'] : null;
$pageTitle = "Forgot password | Hom'As Candles";
require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="login-page">
  <!-- SECTION PURPOSE: Group the Forgot your password? content, states, and controls as one accessible page region. -->
  <section class="login-shell" aria-labelledby="forgot-title">
    <div class="login-story" aria-hidden="true">
      <img src="<?php echo BASE_URL . 'assets/brand/candleSet.jpg'; ?>" alt="" class="login-story__image">
      <div class="login-story__overlay"></div>
      <div class="login-story__content">
        <span class="login-story__eyebrow">A secure way back</span>
        <blockquote>Reset gently,<br>return safely.</blockquote>
        <p>We will email a short-lived code to help you choose a new password.</p>
      </div>
    </div>
    <div class="login-panel">
      <div class="login-panel__inner">
        <p class="login-kicker">Account recovery</p>
        <h1 id="forgot-title">Forgot your password?</h1>
        <p class="login-intro">Enter the email address connected to your customer account.</p>

        <!-- FORM PURPOSE: Submit login form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="login-form" action="<?php echo BASE_URL . 'forgot-password.php'; ?>" method="POST" data-auth-form novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <div class="login-field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" value="<?php echo $escape($values['email'] ?? ''); ?>"
              autocomplete="email" maxlength="255" placeholder="you@example.com" required
              <?php echo $emailError !== null ? 'aria-invalid="true" aria-describedby="email-validation-message"' : ''; ?>>
            <?php if ($emailError !== null): ?><p id="email-validation-message" class="field-validation-message" role="alert"><?php echo $escape($emailError); ?></p><?php endif; ?>
          </div>
          <button class="login-submit" type="submit">Send verification code<i data-lucide="mail" aria-hidden="true"></i></button>
        </form>

        <div class="ui-state-group ui-state-group--compact auth-state-group" data-auth-states data-initial-state="<?php echo $state; ?>" aria-live="polite">
          <div class="ui-state" data-ui-state="empty" <?php echo $state !== 'empty' ? 'hidden' : ''; ?>><i data-lucide="mail-question" aria-hidden="true"></i><div><h3>Check your email.</h3><p>Codes expire after 10 minutes and may be used only once.</p></div></div>
          <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner" aria-hidden="true"></span><div><h3>Sending your code…</h3><p>Please wait a moment.</p></div></div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" <?php echo $state !== 'error' ? 'hidden' : ''; ?>><i data-lucide="x" aria-hidden="true"></i><div><h3>We could not continue.</h3><p data-ui-state-message><?php echo $escape($errors['form'] ?? $emailError ?? 'Please check the form.'); ?></p></div></div>
          <div class="ui-state ui-state--success" data-ui-state="success" hidden></div>
        </div>
        <p class="login-signup"><a href="<?php echo BASE_URL . 'login.php'; ?>">Return to sign in</a></p>
      </div>
    </div>
  </section>
</main>

<?php require_once __DIR__ . '/../app/Views/Layouts/footer.php'; ?>
