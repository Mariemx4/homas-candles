// WHAT: turns the browser's built-in HTML5 form validation into visible,
//       accessible error messages instead of the default browser tooltip.
// WHY:  every form on this site uses novalidate + this module (see
//       login.php, signup.php, contact.php) so error styling/wording is
//       consistent everywhere, and screen readers get a real
//       aria-describedby'd message instead of a native popup they might miss.

// WHAT: the human-readable name for a field, used inside its error message.
// WHY:  prefers an explicit data-validation-label, then the field's real
//       <label>, then its name attribute — always producing *something*
//       readable instead of a blank "is required." message.
function getFieldLabel(field) {
  if (field.dataset.validationLabel) return field.dataset.validationLabel;

  const label = field.id
    ? field.form?.querySelector(`label[for="${field.id}"]`)
    : null;

  return label?.textContent.trim() || field.name || "This field";
}

// WHAT: maps the field's native ValidityState flags to a specific sentence.
// WHY:  the browser knows *that* a field is invalid (field.validity.*) but
//       not what to tell the user — this is the one place translating each
//       failure reason into a message, so every form gets the same wording.
function getValidationMessage(field) {
  const label = getFieldLabel(field);
  const validity = field.validity;

  if (validity.valueMissing) return `${label} is required.`;
  if (validity.typeMismatch && field.type === "email") return "Enter a valid email address, such as name@example.com.";
  if (validity.patternMismatch) return field.dataset.patternMessage || `${label} is not in the expected format.`;
  if (validity.tooShort) return `${label} must contain at least ${field.minLength} characters.`;
  if (validity.tooLong) return `${label} must contain no more than ${field.maxLength} characters.`;
  if (validity.rangeUnderflow) return `${label} must be at least ${field.min}.`;
  if (validity.rangeOverflow) return `${label} must be no more than ${field.max}.`;
  if (validity.stepMismatch) return `Enter a valid value for ${label.toLowerCase()}.`;
  if (validity.badInput) return `Enter a valid value for ${label.toLowerCase()}.`;
  if (validity.customError) return field.validationMessage;

  return `${label} is not valid.`;
}

export function createFormValidation(form) {
  if (!form) return null;

  // WHAT: every real, enabled input/textarea/select in the form.
  // WHY:  checkboxes/radios are excluded — their "invalid" state doesn't
  //       map to a single field the same way a text input's does.
  const fields = [...form.querySelectorAll("input:not([type='hidden']):not([type='submit']):not([type='button']), textarea, select")]
    .filter((field) => !field.disabled && field.type !== "checkbox" && field.type !== "radio");

  // WHAT: finds (or creates) the <p> that holds this field's error text.
  // WHY:  also wires it into aria-describedby so a screen reader announces
  //       the error message when the field receives focus.
  function getMessageElement(field) {
    const messageId = `${field.id || field.name}-validation-message`;
    let message = form.querySelector(`#${messageId}`);

    if (!message) {
      message = document.createElement("p");
      message.id = messageId;
      message.className = "field-validation-message";
      message.setAttribute("aria-live", "polite");
      message.hidden = true;
      const fieldControl = field.closest("[data-field-control]") || field;
      fieldControl.insertAdjacentElement("afterend", message);
    }

    const describedBy = new Set((field.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean));
    describedBy.add(messageId);
    field.setAttribute("aria-describedby", [...describedBy].join(" "));

    return message;
  }

  // WHAT: cross-field check for any field with data-match (e.g. "confirm
  //       password" pointing at #password via data-match="#password").
  function updateMatchingFields() {
    fields.filter((field) => field.dataset.match).forEach((field) => {
      const target = form.querySelector(field.dataset.match);
      const isMismatch = field.value !== "" && target && field.value !== target.value;
      field.setCustomValidity(isMismatch
        ? field.dataset.matchMessage || "The values do not match."
        : "");
    });
  }

  function validateField(field, reveal = true) {
    const message = getMessageElement(field);
    const isValid = field.checkValidity();

    if (!reveal) return isValid;

    field.dataset.validationTouched = "true";
    field.setAttribute("aria-invalid", String(!isValid));
    // WHAT: mirror validity onto a custom select's visible trigger button.
    // WHY:  the native select is visually clipped after enhancement, so the
    //       control customers can see must receive the same accessible state.
    const customSelectTrigger = field.parentElement?.querySelector(".custom-select__trigger");
    customSelectTrigger?.setAttribute("aria-invalid", String(!isValid));
    message.textContent = isValid ? "" : getValidationMessage(field);
    message.hidden = isValid;

    return isValid;
  }

  // WHAT: validates every field, focuses the first invalid one, and
  //       returns whether the whole form is valid.
  function validateForm() {
    updateMatchingFields();
    const results = fields.map((field) => validateField(field));
    const firstInvalidField = fields.find((field) => !field.checkValidity());
    updateErrorSummary();
    const firstInvalidControl = firstInvalidField?.dataset.customSelectReady === "true"
      ? firstInvalidField.parentElement?.querySelector(".custom-select__trigger")
      : firstInvalidField;
    firstInvalidControl?.focus();
    return results.every(Boolean);
  }

  // WHAT: summarize multiple client-side failures at the top of the form.
  // WHY:  this mirrors the PHP error summary, helping keyboard and screen-
  //       reader users understand the whole form without hunting field by field.
  function updateErrorSummary() {
    const invalidFields = fields.filter((field) => !field.checkValidity());
    let summary = form.querySelector("[data-client-error-summary]");

    if (invalidFields.length < 2) {
      summary?.remove();
      return;
    }

    if (!summary) {
      summary = document.createElement("div");
      summary.className = "auth-error-summary";
      summary.dataset.clientErrorSummary = "";
      summary.setAttribute("role", "alert");
      summary.tabIndex = -1;
      form.prepend(summary);
    }

    summary.replaceChildren();
    const heading = document.createElement("h2");
    heading.textContent = "Please check the following:";
    const list = document.createElement("ul");
    invalidFields.forEach((field) => {
      const item = document.createElement("li");
      const link = document.createElement("a");
      link.href = `#${field.id}`;
      link.textContent = getValidationMessage(field);
      item.append(link);
      list.append(item);
    });
    summary.append(heading, list);
  }

  // WHAT: re-validate on blur always, and on input only after a field has
  //       already been "touched" once — so errors don't appear while the
  //       user is still mid-typing their first pass through a field.
  fields.forEach((field) => {
    getMessageElement(field);

    field.addEventListener("blur", () => {
      updateMatchingFields();
      validateField(field);
    });

    field.addEventListener("input", () => {
      updateMatchingFields();

      fields.filter((candidate) => candidate.dataset.match).forEach((candidate) => {
        if (candidate.dataset.validationTouched === "true") validateField(candidate);
      });

      if (field.dataset.validationTouched === "true") validateField(field);
    });

    // Custom dropdowns dispatch "change" on their hidden native select.
    // Validate immediately so choosing a governorate clears its old error.
    field.addEventListener("change", () => {
      updateMatchingFields();
      validateField(field);
    });
  });

  return { validateField, validateForm };
}
