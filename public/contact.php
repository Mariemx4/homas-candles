<?php

declare(strict_types=1);


// WHAT: the contact / request form. Every accepted submission becomes a
//       SUBMITTED custom request in the administrator queue (admin/requests).
// WHY:  POST/Redirect/GET — the outcome is stored in the session and shown
//       after a redirect, so refreshing the page never sends a message twice.
//       ?product=<slug> carries the product a customer came from; the server
//       re-resolves it on every request instead of trusting the URL.
require_once __DIR__ . '/../app/Bootstrap/contact.php';

use App\Security\ContactAbuseGuard;
use App\Services\ContactRequestService;

$customerId = $customerSession->customerId();
$productSlug = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? ($_POST['product'] ?? null) : ($_GET['product'] ?? null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = ['success' => false, 'errors' => [], 'values' => []];
    try {
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!$csrf->validate($token)) {
            throw new DomainException('Your form expired. Please review your message and send it again.');
        }
        $verdict = $contactGuard->inspect($_POST);
        if ($verdict === ContactAbuseGuard::BOT) {
            // Pretend it worked: a bot that sees an error simply adapts and retries.
            $result = ['success' => true, 'errors' => [], 'values' => [], 'requestNumber' => null];
        } elseif ($verdict === ContactAbuseGuard::TOO_FAST) {
            throw new DomainException('That was quick! Please check your details and press send again.');
        } elseif ($verdict === ContactAbuseGuard::RATE_LIMITED) {
            throw new DomainException('You have sent several messages recently. Please wait a few minutes, or contact us directly by phone or WhatsApp.');
        } else {
            $result = $contactService->submit($_POST, $customerId);
            if ($result['success']) {
                $contactGuard->recordSubmission();
            }
        }
    } catch (DomainException $error) {
        $result['errors'] = ['form' => $error->getMessage()];
    } catch (Throwable) {
        error_log('Contact request could not be saved.');
        $result['errors'] = ['form' => 'Your message could not be sent right now. Please try again, or contact us directly by phone or WhatsApp.'];
    }

    if ($result['success']) {
        $_SESSION['contact_form'] = ['sent' => true, 'request_number' => $result['requestNumber'] ?? null];
        header('Location: ' . BASE_URL . 'contact.php', true, 303);
        exit;
    }

    // Keep the customer's typing (never the honeypot or token) for the redisplay.
    $_SESSION['contact_form'] = ['errors' => $result['errors'], 'values' => $result['values'] !== [] ? $result['values'] : array_intersect_key(
        array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $_POST),
        $contactService->defaultsFor(null)
    )];
    $query = is_string($productSlug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $productSlug) === 1
      ? '?product=' . rawurlencode($productSlug) : '';
    header('Location: ' . BASE_URL . 'contact.php' . $query, true, 303);
    exit;
}

$flash = is_array($_SESSION['contact_form'] ?? null) ? $_SESSION['contact_form'] : [];
unset($_SESSION['contact_form']);
$sent = ($flash['sent'] ?? false) === true;
$requestNumber = is_string($flash['request_number'] ?? null) ? $flash['request_number'] : null;
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];

try {
    $product = $contactService->productContext($productSlug);
    $topics = $product === null ? $contactService->topics() : [];
    $values = array_merge($contactService->defaultsFor($customerId), is_array($flash['values'] ?? null) ? $flash['values'] : []);
    $contactAvailable = true;
} catch (Throwable) {
    error_log('Contact form data could not be loaded.');
    $product = null;
    $topics = [];
    $values = $contactService->defaultsFor(null);
    $contactAvailable = false;
}
// Start the minimum-fill timer for this rendering of the form.
$contactGuard->stampForm();

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field]) ? $errors[$field] : null;
// WHAT: these helpers keep each field's accessible server error markup consistent.
// WHY:  matching message IDs let aria-describedby announce the exact problem,
//       and form_validation.js reuses the same element for browser checks.
$serverError = static function (string $field, string $id) use ($fieldError, $escape): void {
    $message = $fieldError($field);
    if ($message !== null) {
        echo '<p id="' . $escape($id) . '-validation-message" class="field-validation-message" role="alert">'
          . $escape($message) . '</p>';
    }
};
$errorAttributes = static function (string $field, string $id) use ($fieldError, $escape): string {
    return $fieldError($field) === null ? '' : ' aria-invalid="true" aria-describedby="' . $escape($id) . '-validation-message"';
};

$contactInitialState = $sent ? 'success' : ($errors !== [] || !$contactAvailable ? 'error' : 'empty');
$isCallback = $values['request_type'] === 'CALLBACK';
$methodLabels = [
  'PHONE' => \App\Services\Locale::text('contact.phone_call'), 'WHATSAPP' => 'WhatsApp', 'EMAIL' => \App\Services\Locale::text('footer.email'),
  'INSTAGRAM' => 'Instagram', 'FACEBOOK' => 'Facebook', 'TIKTOK' => 'TikTok',
];
// WHAT: a pre-written WhatsApp message that names the product.
// WHY:  a customer who prefers to chat directly still keeps the product context.
$whatsappText = $product === null
  ? "Hello Hom'As, I have a question."
  : "Hello Hom'As, I'm interested in \"" . $product['name'] . '".';
$channelLinks = array_filter([
  'WHATSAPP' => $contactSettings['channels']['WHATSAPP'] === null ? null
    : $contactSettings['channels']['WHATSAPP'] . '?text=' . rawurlencode($whatsappText),
  'INSTAGRAM' => $contactSettings['channels']['INSTAGRAM'],
  'FACEBOOK' => $contactSettings['channels']['FACEBOOK'],
  'TIKTOK' => $contactSettings['channels']['TIKTOK'],
]);
$pageTitle = \App\Services\Locale::text('nav.contact') . " | Hom'As Candles";

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="contact-page">
  <header class="contact-hero">
    <p class="contact-eyebrow"><?php echo site_text($product === null ? 'contact.eyebrow' : 'contact.request_eyebrow'); ?></p>
    <div class="contact-hero__heading">
      <h1><?php echo site_text('contact.heading_1'); ?> <em><?php echo site_text('contact.heading_2'); ?></em></h1>
      <p><?php echo site_text('contact.intro'); ?></p>
    </div>
  </header>

  <section class="contact-shell" aria-labelledby="contact-form-title">
    <div class="contact-form-card">
      <div class="contact-form-card__heading">
        <p class="contact-eyebrow"><?php echo site_text('contact.send'); ?></p>
        <h2 id="contact-form-title"><?php echo site_text('contact.hear'); ?></h2>
        <p><?php echo site_text('contact.share'); ?></p>
      </div>

      <?php if ($product !== null): ?>
        <!-- WHAT: the product the customer came from, so they know what the request is about. -->
        <div class="contact-product">
          <img src="<?php echo BASE_URL . $escape($product['image']); ?>" alt="">
          <div>
            <span><?php echo $escape($product['category_name']); ?></span>
            <strong><?php echo $escape($product['name']); ?></strong>
            <a href="<?php echo BASE_URL . 'contact.php'; ?>"><?php echo site_text('contact.something_else'); ?></a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Submit contact form with POST; ContactRequestService re-validates every value before saving a request. -->
      <form class="contact-form" action="<?php echo BASE_URL . 'contact.php'; ?>" method="POST"
        data-contact-form data-send-label="<?php echo site_text('contact.send'); ?>"
        data-callback-label="<?php echo site_text('contact.callback'); ?>"
        data-validation-error="<?php echo site_text('contact.validation_error'); ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <?php if ($product !== null): ?>
          <input type="hidden" name="product" value="<?php echo $escape($product['slug']); ?>">
        <?php endif; ?>

        <?php // WHAT: honeypot — invisible to people, tempting to form-filling bots.?>
        <?php // WHY:  ContactAbuseGuard silently discards any submission that fills it.?>
        <div class="contact-honeypot" aria-hidden="true">
          <label for="contact-website">Leave this field empty</label>
          <input type="text" id="contact-website" name="<?php echo ContactAbuseGuard::HONEYPOT_FIELD; ?>" value="" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="contact-choice">
          <legend><?php echo site_text('contact.what_do'); ?></legend>
          <label class="contact-chip">
            <input type="radio" name="request_type" value="MESSAGE" <?php echo $isCallback ? '' : 'checked'; ?> data-request-type>
            <span><i data-lucide="mail" aria-hidden="true"></i><?php echo site_text('contact.send'); ?></span>
          </label>
          <label class="contact-chip">
            <input type="radio" name="request_type" value="CALLBACK" <?php echo $isCallback ? 'checked' : ''; ?> data-request-type>
            <span><i data-lucide="phone" aria-hidden="true"></i><?php echo site_text('contact.callback'); ?></span>
          </label>
          <?php $serverError('request_type', 'contact-request-type'); ?>
        </fieldset>

        <div class="contact-form__row">
          <div class="contact-field">
            <label for="contact-name"><?php echo site_text('contact.name'); ?></label>
            <input type="text" id="contact-name" name="customer_name" autocomplete="name" value="<?php echo $escape($values['customer_name']); ?>"
              placeholder="<?php echo site_text('contact.name_hint'); ?>" minlength="2" maxlength="201" required<?php echo $errorAttributes('customer_name', 'contact-name'); ?>>
            <?php $serverError('customer_name', 'contact-name'); ?>
          </div>

          <div class="contact-field">
            <label for="contact-number"><?php echo site_text('checkout.phone'); ?></label>
            <input type="tel" id="contact-number" name="customer_phone" autocomplete="tel" value="<?php echo $escape($values['customer_phone']); ?>"
              placeholder="<?php echo site_text('contact.phone_hint'); ?>" inputmode="tel" maxlength="20"
              pattern="(?:\+216|00216)?[\s-]*(?:\d[\s-]*){8}"
              data-pattern-message="Enter an 8-digit Tunisian phone number, with or without +216."
              required<?php echo $errorAttributes('customer_phone', 'contact-number'); ?>>
            <?php $serverError('customer_phone', 'contact-number'); ?>
          </div>
        </div>

        <div class="contact-field">
          <label for="contact-email"><?php echo site_text('checkout.email'); ?> <span data-email-optional>(<?php echo site_text('checkout.optional'); ?>)</span></label>
          <input type="email" id="contact-email" name="customer_email" autocomplete="email" value="<?php echo $escape($values['customer_email']); ?>"
            placeholder="you@example.com" maxlength="255"<?php echo $values['preferred_contact_method'] === 'EMAIL' ? ' required' : ''; ?><?php echo $errorAttributes('customer_email', 'contact-email'); ?>>
          <?php $serverError('customer_email', 'contact-email'); ?>
        </div>

        <fieldset class="contact-choice contact-choice--methods">
          <legend><?php echo site_text('contact.reply'); ?></legend>
          <?php foreach (ContactRequestService::CONTACT_METHODS as $method): ?>
            <?php $callbackOnly = !in_array($method, ContactRequestService::CALLBACK_METHODS, true); ?>
            <label class="contact-chip">
              <input type="radio" name="preferred_contact_method" value="<?php echo $method; ?>"
                <?php echo $values['preferred_contact_method'] === $method ? 'checked' : ''; ?>
                <?php echo $callbackOnly ? 'data-message-only' : ''; ?>
                <?php echo in_array($method, ContactRequestService::SOCIAL_METHODS, true) ? 'data-social-method' : ''; ?>
                data-contact-method>
              <span><?php echo $escape($methodLabels[$method]); ?></span>
            </label>
          <?php endforeach; ?>
          <?php $serverError('preferred_contact_method', 'contact-method'); ?>
        </fieldset>

        <div class="contact-field" data-social-handle <?php echo in_array($values['preferred_contact_method'], ContactRequestService::SOCIAL_METHODS, true) ? '' : 'hidden'; ?>>
          <label for="contact-handle"><?php echo site_text('contact.handle'); ?></label>
          <input type="text" id="contact-handle" name="social_handle" value="<?php echo $escape($values['social_handle']); ?>"
            placeholder="@yourname" maxlength="100"
            <?php echo in_array($values['preferred_contact_method'], ContactRequestService::SOCIAL_METHODS, true) ? 'required' : ''; ?><?php echo $errorAttributes('social_handle', 'contact-handle'); ?>>
          <?php $serverError('social_handle', 'contact-handle'); ?>
        </div>

        <?php if ($product === null): ?>
          <div class="contact-field">
            <label for="contact-topic"><?php echo site_text('contact.topic'); ?></label>
            <select id="contact-topic" name="category_id" required<?php echo $errorAttributes('category_id', 'contact-topic'); ?>>
              <option value=""><?php echo site_text('contact.choose_topic'); ?></option>
              <?php foreach ($topics as $topic): ?>
                <option value="<?php echo (int) $topic['id']; ?>" <?php echo $values['category_id'] === (string) $topic['id'] ? 'selected' : ''; ?>><?php echo $escape($topic['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <?php $serverError('category_id', 'contact-topic'); ?>
          </div>
        <?php else: ?>
          <!-- WHAT: optional request details, agreed manually for personalised pieces. -->
          <fieldset class="contact-request-details">
            <legend><?php echo site_text('contact.your_request'); ?> <span>(<?php echo site_text('contact.request_optional'); ?>)</span></legend>
            <div class="contact-form__row">
              <div class="contact-field">
                <label for="contact-quantity"><?php echo site_text('product.quantity'); ?></label>
                <input type="number" id="contact-quantity" name="requested_quantity" value="<?php echo $escape($values['requested_quantity']); ?>"
                  min="<?php echo (int) $product['minimum_quantity']; ?>" max="100000" inputmode="numeric"
                  placeholder="<?php echo site_text('contact.at_least'); ?> <?php echo (int) $product['minimum_quantity']; ?>"<?php echo $errorAttributes('requested_quantity', 'contact-quantity'); ?>>
                <?php $serverError('requested_quantity', 'contact-quantity'); ?>
              </div>
              <div class="contact-field">
                <label for="contact-date"><?php echo site_text('contact.date'); ?></label>
                <input type="date" id="contact-date" name="required_date" value="<?php echo $escape($values['required_date']); ?>"
                  min="<?php echo $escape((new DateTimeImmutable('tomorrow'))->format('Y-m-d')); ?>"<?php echo $errorAttributes('required_date', 'contact-date'); ?>>
                <?php $serverError('required_date', 'contact-date'); ?>
              </div>
            </div>
            <div class="contact-form__row">
              <div class="contact-field">
                <label for="contact-budget"><?php echo site_text('contact.budget'); ?> (DT)</label>
                <input type="text" id="contact-budget" name="budget" value="<?php echo $escape($values['budget']); ?>"
                  inputmode="decimal" pattern="[0-9]{1,9}(?:[.,][0-9]{1,3})?" data-pattern-message="Enter an amount such as 120 or 120.500."
                  placeholder="e.g. 150"<?php echo $errorAttributes('budget', 'contact-budget'); ?>>
                <?php $serverError('budget', 'contact-budget'); ?>
              </div>
              <div class="contact-field">
                <label for="contact-packaging"><?php echo site_text('contact.packaging'); ?></label>
                <input type="text" id="contact-packaging" name="packaging_preferences" value="<?php echo $escape($values['packaging_preferences']); ?>"
                  maxlength="500" placeholder="Gift box, ribbon, card…"<?php echo $errorAttributes('packaging_preferences', 'contact-packaging'); ?>>
                <?php $serverError('packaging_preferences', 'contact-packaging'); ?>
              </div>
            </div>
            <div class="contact-form__row">
              <div class="contact-field">
                <label for="contact-colors"><?php echo site_text('contact.colors'); ?></label>
                <input type="text" id="contact-colors" name="color_preferences" value="<?php echo $escape($values['color_preferences']); ?>"
                  maxlength="500" placeholder="Ivory and blush"<?php echo $errorAttributes('color_preferences', 'contact-colors'); ?>>
                <?php $serverError('color_preferences', 'contact-colors'); ?>
              </div>
              <div class="contact-field">
                <label for="contact-scents"><?php echo site_text('contact.scents'); ?></label>
                <input type="text" id="contact-scents" name="scent_preferences" value="<?php echo $escape($values['scent_preferences']); ?>"
                  maxlength="500" placeholder="Vanilla, unscented…"<?php echo $errorAttributes('scent_preferences', 'contact-scents'); ?>>
                <?php $serverError('scent_preferences', 'contact-scents'); ?>
              </div>
            </div>
          </fieldset>
        <?php endif; ?>

        <div class="contact-field">
          <label for="contact-message"><?php echo site_text('contact.help'); ?> <span data-message-optional <?php echo $isCallback ? '' : 'hidden'; ?>>(<?php echo site_text('checkout.optional'); ?>)</span></label>
          <textarea id="contact-message" name="message" rows="6" maxlength="<?php echo (int) $contactSettings['message_max_length']; ?>"
            placeholder="<?php echo site_text('contact.message_hint'); ?>"
            <?php echo $isCallback ? '' : 'minlength="10" required'; ?><?php echo $errorAttributes('message', 'contact-message'); ?>><?php echo $escape($values['message']); ?></textarea>
          <?php $serverError('message', 'contact-message'); ?>
        </div>

        <div class="contact-form__footer">
          <p id="contact-form-note">
            <i data-lucide="shield-check" aria-hidden="true"></i>
            <?php echo site_text('contact.privacy'); ?>
          </p>
          <button type="submit" aria-describedby="contact-form-note" <?php echo $contactAvailable ? '' : 'disabled'; ?>>
            <span data-submit-label><?php echo site_text($isCallback ? 'contact.callback' : 'contact.send'); ?></span>
            <i data-lucide="send" aria-hidden="true"></i>
          </button>
        </div>
      </form>

      <div class="contact-form-states" data-contact-states
        data-initial-state="<?php echo $contactInitialState; ?>" aria-live="polite" aria-atomic="true">
        <div class="ui-state contact-form-state contact-form-state--empty" data-ui-state="empty"
          <?php echo $contactInitialState !== 'empty' ? 'hidden' : ''; ?>>
          <i data-lucide="mail" aria-hidden="true"></i>
          <div>
            <h3><?php echo site_text('contact.ready'); ?></h3>
            <p><?php echo site_text('contact.ready_body'); ?></p>
          </div>
        </div>

        <div class="ui-state contact-form-state contact-form-state--loading" data-ui-state="loading" hidden>
          <span class="ui-state__spinner contact-state-spinner" aria-hidden="true"></span>
          <div>
            <h3><?php echo site_text('contact.sending'); ?></h3>
            <p><?php echo site_text('contact.sending_body'); ?></p>
          </div>
        </div>

        <div class="ui-state ui-state--error contact-form-state contact-form-state--error" data-ui-state="error"
          role="alert" <?php echo $contactInitialState !== 'error' ? 'hidden' : ''; ?>>
          <i data-lucide="x" aria-hidden="true"></i>
          <div>
            <h3><?php echo site_text('contact.error'); ?></h3>
            <p data-ui-state-message>
              <?php if (!$contactAvailable): ?>
                <?php echo site_text('contact.unavailable'); ?>
              <?php else: ?>
                <?php echo $escape($fieldError('form') ?? 'Please check the highlighted fields and try again.'); ?>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <div class="ui-state ui-state--success contact-form-state contact-form-state--success" data-ui-state="success"
          role="status" <?php echo $contactInitialState !== 'success' ? 'hidden' : ''; ?>>
          <i data-lucide="check" aria-hidden="true"></i>
          <div>
            <h3><?php echo site_text('contact.success'); ?></h3>
            <p>
              <?php echo site_text('contact.success_body'); ?>
              <?php if ($requestNumber !== null): ?>
                <?php echo site_text('contact.reference'); ?> <strong><?php echo $escape($requestNumber); ?></strong>.
              <?php endif; ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <aside class="contact-details" aria-labelledby="contact-details-title">
      <p class="contact-eyebrow"><?php echo site_text('contact.direct'); ?></p>
      <h2 id="contact-details-title"><?php echo site_text('contact.direct_title'); ?></h2>
      <p class="contact-details__intro"><?php echo site_text('contact.direct_body'); ?></p>

      <ul class="contact-details__list">
        <li>
          <i data-lucide="mail" aria-hidden="true"></i>
          <div>
            <span><?php echo site_text('footer.email'); ?></span>
            <a href="mailto:<?php echo $escape($contactSettings['email']); ?>"><?php echo $escape($contactSettings['email']); ?></a>
          </div>
        </li>
        <li>
          <i data-lucide="phone" aria-hidden="true"></i>
          <div>
            <span><?php echo site_text('footer.phone'); ?></span>
            <a href="tel:+<?php echo $escape($contactSettings['phone_international']); ?>"><?php echo $escape($contactSettings['phone_display']); ?></a>
          </div>
        </li>
        <li>
          <i data-lucide="map-pin" aria-hidden="true"></i>
          <div>
            <span><?php echo site_text('footer.delivery'); ?></span>
            <p><?php echo site_text('footer.delivery_body'); ?></p>
          </div>
        </li>
      </ul>

      <?php // WHAT: the documented social-contact options; unconfigured channels are omitted.?>
      <?php if ($channelLinks !== []): ?>
        <ul class="contact-socials" aria-label="Message us on social media">
          <?php foreach ($channelLinks as $channel => $url): ?>
            <li>
              <a href="<?php echo $escape($url); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo $escape($methodLabels[$channel]); ?>
                <i data-lucide="arrow-right" aria-hidden="true"></i>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div class="contact-details__closing">
        <i data-lucide="sparkles" aria-hidden="true"></i>
        <p><?php echo site_text('contact.closing'); ?></p>
      </div>
    </aside>
  </section>
</main>

<?php
require_once VIEW_PATH . '/Layouts/footer.php';
?>
