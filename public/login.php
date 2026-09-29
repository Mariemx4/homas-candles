<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Login.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */

// WHAT: POST requests are handled before any HTML is sent.
// WHY:  the controller can then use POST/Redirect/GET without "headers already sent" errors.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
        require_once __DIR__ . '/../app/Bootstrap/customer-auth.php';
        $customerAuthController->login($_POST);
    } catch (Throwable) {
        // Keep database/bootstrap failures inside the normal safe form flow.
        error_log('Customer login bootstrap failed.');
        // ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
        require_once __DIR__ . '/../app/Bootstrap/session.php';
        $safeIdentifier = isset($_POST['login_identifier']) && is_string($_POST['login_identifier'])
            ? trim($_POST['login_identifier'])
            : '';
        (new \App\Auth\AuthFormFlash())->put(
            'login',
            ['form' => 'Sign-in is temporarily unavailable. Please try again.'],
            ['login_identifier' => $safeIdentifier]
        );
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
}

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../app/Bootstrap/session.php';

use App\Auth\AuthFormFlash;

$formState = (new AuthFormFlash())->consume('login');
$errors = $formState['errors'];
$values = $formState['values'];
$successMessage = $formState['success'];

if (isset($_GET['session_expired'])) {
    $errors['form'] = 'Your session expired after a period of inactivity. Please sign in again.';
}
if (isset($_GET['signed_out'])) {
    $successMessage = 'You have signed out safely.';
}

$authInitialState = $errors !== [] ? 'error' : ($successMessage !== null ? 'success' : 'empty');
$stateMessage = $errors['authentication'] ?? $errors['form'] ?? 'Please check the highlighted fields and try again.';
$pageTitle = "Sign in | Hom'As Candles";
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field]) ? $errors[$field] : null;

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="login-page">
  <!-- SECTION PURPOSE: Group the Sign in to your account content, states, and controls as one accessible page region. -->
  <section class="login-shell" aria-labelledby="login-title">
    <div class="login-story" aria-hidden="true">
      <img src="<?php echo BASE_URL . 'assets/brand/candleSet.jpg'; ?>" alt="" class="login-story__image">
      <div class="login-story__overlay"></div>
      <div class="login-story__content">
        <span class="login-story__eyebrow">Hand-poured in Tunisia</span>
        <blockquote>“A little flame,<br>a softer home.”</blockquote>
        <p>Thoughtfully handmade pieces created to make everyday moments feel special.</p>
      </div>
    </div>

    <div class="login-panel">
      <div class="login-panel__inner">
        <p class="login-kicker">Welcome home</p>
        <h1 id="login-title">Sign in to your account</h1>
        <p class="login-intro">Return to your saved favourites, orders, and little luxuries.</p>

        <?php if (count($errors) > 1): ?>
          <div class="auth-error-summary" role="alert" tabindex="-1" data-error-summary>
            <h2>Please check the following:</h2>
            <ul>
              <?php foreach ($errors as $field => $message): ?>
                <li><a href="#<?php echo $escape(in_array($field, ['authentication', 'form'], true) ? 'login_identifier' : $field); ?>"><?php echo $escape($message); ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- FORM PURPOSE: Submit login form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="login-form" action="<?php echo BASE_URL . 'login.php'; ?>" method="POST" data-auth-form novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <div class="login-field">
            <label for="login_identifier">Email or phone number</label>
            <input type="text" id="login_identifier" name="login_identifier"
              value="<?php echo $escape($values['login_identifier'] ?? ''); ?>"
              placeholder="you@example.com or +216 XX XXX XXX" autocomplete="username" maxlength="255" required
              data-login-identifier data-identifier-message="Enter a valid email address or Tunisian phone number."
              <?php echo $fieldError('login_identifier') !== null ? 'aria-invalid="true" aria-describedby="login_identifier-validation-message"' : ''; ?>>
            <?php if ($fieldError('login_identifier') !== null): ?>
              <p id="login_identifier-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError('login_identifier')); ?></p>
            <?php endif; ?>
          </div>

          <div class="login-field">
            <div class="login-field__heading">
              <label for="password">Password</label>
              <a href="<?php echo BASE_URL . 'forgot-password.php'; ?>">Forgot password?</a>
            </div>
            <div class="password-control" data-field-control>
              <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" maxlength="4096" required
                <?php echo $fieldError('password') !== null ? 'aria-invalid="true" aria-describedby="password-validation-message"' : ''; ?>>
              <button class="password-visibility-toggle" type="button" aria-controls="password" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle>
                <i class="password-visibility-toggle__show" data-lucide="eye" aria-hidden="true"></i>
                <i class="password-visibility-toggle__hide" data-lucide="eye-off" aria-hidden="true"></i>
              </button>
            </div>
            <?php if ($fieldError('password') !== null): ?>
              <p id="password-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError('password')); ?></p>
            <?php endif; ?>
          </div>

          <button class="login-submit" type="submit">Sign in<i data-lucide="arrow-right" aria-hidden="true"></i></button>
        </form>

        <div class="ui-state-group ui-state-group--compact auth-state-group" data-auth-states data-initial-state="<?php echo $escape($authInitialState); ?>" aria-live="polite" aria-atomic="true">
          <div class="ui-state" data-ui-state="empty" <?php echo $authInitialState !== 'empty' ? 'hidden' : ''; ?>><i data-lucide="user-round" aria-hidden="true"></i><div><h3>Ready when you are.</h3><p>Enter your account details to sign in.</p></div></div>
          <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner" aria-hidden="true"></span><div><h3>Signing you in…</h3><p>Checking your account details.</p></div></div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" <?php echo $authInitialState !== 'error' ? 'hidden' : ''; ?>><i data-lucide="x" aria-hidden="true"></i><div><h3>We could not sign you in.</h3><p data-ui-state-message><?php echo $escape($stateMessage); ?></p></div></div>
          <div class="ui-state ui-state--success" data-ui-state="success" <?php echo $authInitialState !== 'success' ? 'hidden' : ''; ?>><i data-lucide="check" aria-hidden="true"></i><div><h3>All set.</h3><p><?php echo $escape($successMessage ?? 'Welcome back.'); ?></p></div></div>
        </div>

        <p class="login-signup">New to Hom'As? <a href="<?php echo BASE_URL . 'signup.php'; ?>">Create account</a></p>
        <p class="login-note"><i data-lucide="sparkles" aria-hidden="true"></i>Light the moment, smell the memory.<i data-lucide="sparkles" aria-hidden="true"></i></p>
      </div>
    </div>
  </section>
</main>

<?php require_once __DIR__ . '/../app/Views/Layouts/footer.php'; ?>
