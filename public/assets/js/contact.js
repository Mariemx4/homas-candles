// WHAT: submit handling for the contact form on contact.php.
// WHY:  same pattern as auth.js — validates, shows a loading/success/error
//       panel, then calls the real submit(). The "contact:success"/
//       "contact:error" events are scaffolding for a future backend/fetch
//       flow; nothing dispatches them yet, so today every submit ends up
//       showing the fixed "not available yet" error from contact.php.
import { createStateController, fieldsAreEmpty } from "./state_controller.js";
import { createFormValidation } from "./form_validation.js";

const contactForm = document.querySelector("[data-contact-form]");

if (contactForm) {
  const stateContainer = document.querySelector("[data-contact-states]");
  const stateController = createStateController(stateContainer);
  const fields = [...contactForm.querySelectorAll("input, textarea")];
  const submitButton = contactForm.querySelector('button[type="submit"]');
  const validation = createFormValidation(contactForm);

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

  contactForm.addEventListener("contact:success", () => {
    contactForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    stateController.show("success");
  });

  contactForm.addEventListener("contact:error", (event) => {
    contactForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    stateController.show("error", event.detail?.message || "Something went wrong. Please try again.");
  });

  stateController.show(stateContainer.dataset.initialState || "empty");
}
