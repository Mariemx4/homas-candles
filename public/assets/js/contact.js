/*
 * FILE PURPOSE: Browser-side behavior for Contact.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: client-side help for the contact form on contact.php.
// WHY:  same pattern as auth.js — validates, shows a loading panel, then
//       performs a normal POST. The server (ContactRequestService and
//       ContactAbuseGuard) re-checks everything and redirects back with the
//       success or error state already rendered into data-initial-state.
//       This script also keeps the conditional fields honest: a callback
//       makes the message optional and limits replies to phone/WhatsApp,
//       email replies need an address, and social replies need a handle.
import { createStateController, fieldsAreEmpty } from "./state_controller.js";
import { createFormValidation } from "./form_validation.js";

const contactForm = document.querySelector("[data-contact-form]");

if (contactForm) {
  const stateContainer = document.querySelector("[data-contact-states]");
  const stateController = createStateController(stateContainer);
  // Only fields a person types into count toward the "empty" state.
  const fields = [...contactForm.querySelectorAll("input:not([type='hidden']):not([type='radio']):not([tabindex='-1']), textarea, select")];
  const submitButton = contactForm.querySelector('button[type="submit"]');
  const submitLabel = contactForm.querySelector("[data-submit-label]");
  const validation = createFormValidation(contactForm);

  const message = contactForm.querySelector("#contact-message");
  const messageOptional = contactForm.querySelector("[data-message-optional]");
  const email = contactForm.querySelector("#contact-email");
  const emailOptional = contactForm.querySelector("[data-email-optional]");
  const handleField = contactForm.querySelector("[data-social-handle]");
  const handle = contactForm.querySelector("#contact-handle");

  /** FUNCTION PURPOSE: Return the checked value of a radio group, or an empty string. */
  const checkedValue = (name) => contactForm.querySelector(`input[name="${name}"]:checked`)?.value || "";

  /**
   * FUNCTION PURPOSE: Apply the rules that depend on the request type and reply method.
   * WHY: toggling required (not disabled) keeps form_validation.js's field list valid.
   */
  const applyChoices = () => {
    const isCallback = checkedValue("request_type") === "CALLBACK";

    // A callback can only be answered by phone or WhatsApp.
    contactForm.querySelectorAll("[data-message-only]").forEach((radio) => {
      radio.disabled = isCallback;
      if (isCallback && radio.checked) {
        contactForm.querySelector('input[name="preferred_contact_method"][value="PHONE"]').checked = true;
      }
    });

    message.required = !isCallback;
    if (isCallback) {
      message.removeAttribute("minlength");
    } else {
      message.minLength = 10;
    }
    if (messageOptional) messageOptional.hidden = !isCallback;
    if (submitLabel) submitLabel.textContent = isCallback ? "Request callback" : "Send message";

    const method = checkedValue("preferred_contact_method");
    const isSocial = contactForm.querySelector("[data-contact-method]:checked")?.hasAttribute("data-social-method") ?? false;
    email.required = method === "EMAIL";
    if (emailOptional) emailOptional.hidden = method === "EMAIL";
    if (handleField && handle) {
      handleField.hidden = !isSocial;
      handle.required = isSocial;
    }
  };

  contactForm.querySelectorAll("[data-request-type], [data-contact-method]").forEach((radio) => {
    radio.addEventListener("change", applyChoices);
  });
  applyChoices();

  fields.forEach((field) => {
    field.addEventListener("input", () => {
      if (contactForm.getAttribute("aria-busy") === "true") return;
      stateController.show(fieldsAreEmpty(fields) ? "empty" : null);
    });
  });

  contactForm.addEventListener("submit", (event) => {
    event.preventDefault();

    if (fieldsAreEmpty(fields)) {
      stateController.show("empty");
      validation.validateForm();
      return;
    }

    if (!validation.validateForm()) {
      stateController.show("error", "Please check the highlighted fields and try again.");
      return;
    }

    contactForm.setAttribute("aria-busy", "true");
    submitButton.disabled = true;
    stateController.show("loading");

    window.setTimeout(() => contactForm.submit(), 120);
  });

  stateController.show(stateContainer.dataset.initialState || "empty");
}
