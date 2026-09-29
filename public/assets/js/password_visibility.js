/*
 * FILE PURPOSE: Browser-side behavior for Password Visibility.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: the eye/eye-off toggle button next to a password field.
// WHY:  exported as a function you call per-form (see auth.js) rather than
//       running automatically on page load, since it needs to be re-run
//       for a specific form's fields on the pages that have one.
export function initPasswordVisibility(root = document) {
  root.querySelectorAll("[data-password-toggle]").forEach((button) => {
    const inputId = button.getAttribute("aria-controls");
    const input = inputId ? root.querySelector(`#${inputId}`) : null;
    if (!input) return;

    button.addEventListener("click", () => {
      const willShowPassword = input.type === "password";
      input.type = willShowPassword ? "text" : "password";
      button.setAttribute("aria-pressed", String(willShowPassword));
      button.setAttribute("aria-label", willShowPassword ? "Hide password" : "Show password");
      button.title = willShowPassword ? "Hide password" : "Show password";
    });
  });
}
