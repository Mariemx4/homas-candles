/* Checkout enhancements. The form works without JavaScript; the server
   re-validates every field and recalculates every price when it is posted. */

const checkoutForm = document.querySelector("[data-checkout-form]");

if (checkoutForm) {
  const summary = checkoutForm.querySelector("[data-checkout-summary]");
  const addressFields = checkoutForm.querySelector("[data-address-fields]");
  const methodInputs = checkoutForm.querySelectorAll("[data-delivery-method]");
  const notes = checkoutForm.querySelector("#customer_notes");
  const noteCount = checkoutForm.querySelector("[data-note-count]");
  const submitButton = checkoutForm.querySelector("[data-checkout-submit]");

  // WHAT: format integer thousandths of a dinar as "12.345 DT".
  // WHY:  integer maths mirrors CheckoutService and avoids float rounding drift.
  const formatMoney = (millis) =>
    `${Math.trunc(millis / 1000)}.${String(millis % 1000).padStart(3, "0")} DT`;

  /**
   * FUNCTION PURPOSE: Show or hide the address block and refresh the previewed totals for the chosen method.
   */
  const applyDeliveryMethod = () => {
    const isPickup = checkoutForm.querySelector("[data-delivery-method]:checked")?.value === "PICKUP";

    // Disabled inputs are skipped by browser validation and not submitted.
    if (addressFields) {
      addressFields.hidden = isPickup;
      addressFields.querySelectorAll("input, select").forEach((field) => {
        field.disabled = isPickup;
      });
    }

    if (!summary) return;
    const subtotal = Number.parseInt(summary.dataset.subtotalMillis, 10) || 0;
    const fee = isPickup ? 0 : Number.parseInt(summary.dataset.deliveryFeeMillis, 10) || 0;
    const threshold = Number.parseInt(summary.dataset.depositThresholdMillis, 10) || 0;
    const percent = Number.parseInt(summary.dataset.depositPercent, 10) || 0;
    const total = subtotal + fee;
    // Same half-up rounding as CheckoutService::depositFor().
    const deposit = total >= threshold ? Math.floor((total * percent + 50) / 100) : 0;

    summary.querySelector("[data-checkout-delivery]").textContent = formatMoney(fee);
    summary.querySelector("[data-checkout-total]").textContent = formatMoney(total);
    summary.querySelector("[data-checkout-deposit]").textContent = formatMoney(deposit);
    summary.querySelector("[data-checkout-deposit-row]").hidden = deposit === 0;
    summary.querySelector("[data-deposit-note-small]").hidden = deposit > 0;
    summary.querySelector("[data-deposit-note-large]").hidden = deposit === 0;
  };

  methodInputs.forEach((input) => input.addEventListener("change", applyDeliveryMethod));
  applyDeliveryMethod();

  notes?.addEventListener("input", () => {
    if (noteCount) noteCount.textContent = String(notes.value.length);
  });

  // WHAT: block a second click while the first submission is in flight.
  // WHY:  the server also protects against duplicates (the cart is emptied
  //       after the first order), but this avoids a confusing error page.
  checkoutForm.addEventListener("submit", (event) => {
    if (!checkoutForm.checkValidity()) {
      event.preventDefault();
      checkoutForm.reportValidity();
      return;
    }
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.setAttribute("aria-busy", "true");
    }
  });
}
