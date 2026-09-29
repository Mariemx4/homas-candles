/* Cart controls submit normal POST forms; the server owns prices and totals. */

const cartPage = document.querySelector(".cart-page");

if (cartPage) {
  cartPage.querySelectorAll("[data-cart-update-form]").forEach((form) => {
    const input = form.querySelector("[data-quantity]");
    const minimum = Number.parseInt(input.min, 10) || 1;
    const maximum = Number.parseInt(input.max, 10) || 1000;

    form.querySelectorAll("button[data-action]").forEach((button) => {
      button.addEventListener("click", () => {
        const current = Number.parseInt(input.value, 10) || minimum;
        const delta = button.dataset.action === "increase" ? 1 : -1;
        input.value = String(Math.min(maximum, Math.max(minimum, current + delta)));
        form.requestSubmit();
      });
    });
  });
  // The gift note moved to checkout.php, where it is saved as the order's customer notes.
}
