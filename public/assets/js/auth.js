// WHAT: shared submit handling for both login.php and signup.php.
// WHY:  gives immediate, accessible feedback before the normal POST reaches
//       the PHP controller. The server repeats every check because JavaScript
//       can be disabled or bypassed.
import { createStateController, fieldsAreEmpty } from "./state_controller.js";
import { createFormValidation } from "./form_validation.js";
import { initPasswordVisibility } from "./password_visibility.js";
import { initCustomSelects } from "./custom_select.js";

const authForm = document.querySelector("[data-auth-form]");
const stateContainer = document.querySelector("[data-auth-states]");
const states = createStateController(stateContainer);

if (authForm && states) {
  const fields = [...authForm.querySelectorAll("input:not([type='hidden']):not([type='checkbox']):not([type='button']):not([type='submit']), textarea, select")];
  const submitButton = authForm.querySelector('button[type="submit"]');
  const validation = createFormValidation(authForm);
  initPasswordVisibility(authForm);
  // WHAT: reuse the same styled dropdown as the shop's product-type list.
  // WHY:  the real <select> still submits governorate while the custom layer
  //       provides the project's consistent look and keyboard interaction.
  initCustomSelects(authForm);

  const codeDigits = [...authForm.querySelectorAll("[data-code-digit]")];
  const firstCodeDigit = codeDigits[0] || null;

  // WHAT: coordinate the six visual reset-code boxes as one logical value.
  // WHY:  typing advances automatically, Backspace moves naturally, and
  //       pasting/autofill distributes all six digits without extra work.
  const updateCodeValidity = () => {
    if (!firstCodeDigit) return;
    const completeCode = codeDigits.map((input) => input.value).join("");
    firstCodeDigit.setCustomValidity(/^\d{6}$/.test(completeCode)
      ? ""
      : firstCodeDigit.dataset.codeMessage);
    if (firstCodeDigit.dataset.validationTouched === "true") {
      validation.validateField(firstCodeDigit);
    }
  };

  const distributeCode = (digits, startIndex = 0) => {
    [...digits.replace(/\D/g, "")].slice(0, 6 - startIndex).forEach((digit, offset) => {
      codeDigits[startIndex + offset].value = digit;
    });
    const nextIndex = Math.min(startIndex + digits.replace(/\D/g, "").length, 5);
    codeDigits[nextIndex]?.focus();
    updateCodeValidity();
  };

  codeDigits.forEach((input, index) => {
    input.addEventListener("input", () => {
      const digits = input.value.replace(/\D/g, "");
      if (digits.length > 1) distributeCode(digits, index);
      else {
        input.value = digits;
        if (digits !== "") codeDigits[index + 1]?.focus();
        updateCodeValidity();
      }
    });
    input.addEventListener("paste", (event) => {
      event.preventDefault();
      distributeCode(event.clipboardData?.getData("text") || "", index);
    });
    input.addEventListener("keydown", (event) => {
      if (event.key === "Backspace" && input.value === "" && index > 0) {
        codeDigits[index - 1].focus();
      } else if (event.key === "ArrowLeft" && index > 0) {
        event.preventDefault();
        codeDigits[index - 1].focus();
      } else if (event.key === "ArrowRight" && index < 5) {
        event.preventDefault();
        codeDigits[index + 1].focus();
      }
    });
  });
  updateCodeValidity();

  // WHAT: recognize either an email or a reasonably formatted Tunisian phone.
  // WHY:  a text input is necessary for two identity types, so type="email"
  //       cannot provide the correct browser validation by itself.
  const loginIdentifier = authForm.querySelector("[data-login-identifier]");
  const updateIdentifierValidity = () => {
    if (!loginIdentifier) return;
    loginIdentifier.setCustomValidity("");
    if (loginIdentifier.value.trim() === "") {
      if (loginIdentifier.dataset.validationTouched === "true") validation.validateField(loginIdentifier);
      return;
    }
    const value = loginIdentifier.value.trim();
    const isEmail = value.length <= 255 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    const compactPhone = value.replace(/[\s-]+/g, "").replace(/^(?:\+216|00216)/, "");
    const isPhone = /^(?:\+)?[0-9\s-]+$/.test(value) && /^\d{8}$/.test(compactPhone);
    loginIdentifier.setCustomValidity(isEmail || isPhone ? "" : loginIdentifier.dataset.identifierMessage);
    if (loginIdentifier.dataset.validationTouched === "true") validation.validateField(loginIdentifier);
  };

  loginIdentifier?.addEventListener("input", updateIdentifierValidity);
  updateIdentifierValidity();

  // WHAT: compare normalized primary/secondary numbers during signup.
  // WHY:  "+216 12 345 678" and "12-345-678" look different but represent
  //       the same number; the server performs this same check authoritatively.
  const primaryPhone = authForm.querySelector('[name="phone_number"]');
  const secondaryPhone = authForm.querySelector("[data-secondary-phone]");

  // WHAT: keep Tunisian signup numbers readable as "+216 XX XXX XXX".
  // WHY:  formatting while the student/customer types is easier to scan than
  //       an uninterrupted series of digits; PHP still stores only 8 digits.
  const formatTunisianPhone = (input) => {
    const rawValue = input.value.trim();
    let digits = rawValue.replace(/\D/g, "");

    if (digits.startsWith("00216")) digits = digits.slice(5);
    else if (rawValue.startsWith("+216")) digits = digits.slice(3);

    digits = digits.slice(0, 8);
    const groups = [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 8)]
      .filter(Boolean);
    input.value = `+216${groups.length ? ` ${groups.join(" ")}` : " "}`;
  };

  authForm.querySelectorAll("[data-tunisian-phone]").forEach((input) => {
    formatTunisianPhone(input);
    input.addEventListener("input", () => formatTunisianPhone(input));
    input.addEventListener("focus", () => {
      if (input.value.trim() === "") formatTunisianPhone(input);
    });
  });

  const compactTunisianPhone = (value) => value.trim()
    .replace(/[\s-]+/g, "")
    .replace(/^(?:\+216|00216)/, "");
  const updateSecondaryPhoneValidity = () => {
    if (!primaryPhone || !secondaryPhone) return;
    secondaryPhone.setCustomValidity("");
    if (secondaryPhone.value.trim() === "") {
      if (secondaryPhone.dataset.validationTouched === "true") validation.validateField(secondaryPhone);
      return;
    }

    const numbersMatch = compactTunisianPhone(primaryPhone.value)
      === compactTunisianPhone(secondaryPhone.value);
    secondaryPhone.setCustomValidity(numbersMatch
      ? secondaryPhone.dataset.samePhoneMessage
      : "");
    if (secondaryPhone.dataset.validationTouched === "true") validation.validateField(secondaryPhone);
  };

  primaryPhone?.addEventListener("input", updateSecondaryPhoneValidity);
  secondaryPhone?.addEventListener("input", updateSecondaryPhoneValidity);
  updateSecondaryPhoneValidity();

  fields.forEach((field) => {
    field.addEventListener("input", () => {
      if (authForm.getAttribute("aria-busy") === "true") return;
      states.show(fieldsAreEmpty(fields) ? "empty" : null);
    });
  });

  authForm.addEventListener("submit", (event) => {
    event.preventDefault();
    updateIdentifierValidity();
    updateSecondaryPhoneValidity();
    updateCodeValidity();

    // The untouched visible prefix is not a real secondary number. Clearing it
    // just before submission lets native optional-field validation skip it.
    if (secondaryPhone && compactTunisianPhone(secondaryPhone.value) === "") {
      secondaryPhone.value = "";
      secondaryPhone.setCustomValidity("");
    }

    if (fieldsAreEmpty(fields)) {
      states.show("empty");
      validation.validateForm();
      return;
    }

    if (!validation.validateForm()) {
      states.show("error", "Please check the highlighted fields and try again.");
      return;
    }

    authForm.setAttribute("aria-busy", "true");
    submitButton.disabled = true;
    states.show("loading");
    window.setTimeout(() => authForm.submit(), 120);
  });

  authForm.addEventListener("auth:success", () => {
    authForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    states.show("success");
  });

  authForm.addEventListener("auth:error", (event) => {
    authForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    states.show("error", event.detail?.message || "Please try again.");
  });

  states.show(stateContainer.dataset.initialState || "empty");
}
