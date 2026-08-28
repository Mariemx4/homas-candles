// WHAT: all client-side cart interaction on cart.php — quantity
//       steppers, remove buttons, live totals, gift-note character count.
// WHY:  entirely client-side math over whatever items are already in the
//       DOM — cart.php currently renders zero items (see that file's top
//       comment), so this recalculates totals correctly but has nothing to
//       act on yet until add-to-cart is wired to a real session/database.
import { createStateController } from "./state_controller.js";

const cartPage = document.querySelector(".cart-page");

if (cartPage) {
  const cartList = cartPage.querySelector("[data-cart-list]");
  const emptyState = cartPage.querySelector("[data-cart-empty]");
  const checkoutLink = cartPage.querySelector("[data-cart-checkout]");
  const states = createStateController(cartPage.querySelector("[data-cart-states]"));
  const giftNote = cartPage.querySelector("#gift-note");
  const noteCount = cartPage.querySelector("[data-note-count]");
  const deliveryFee = Number(cartPage.dataset.deliveryFee) || 0;

  const formatPrice = (value) => `${value.toFixed(3)} DT`;

  function getQuantity(item) {
    const input = item.querySelector("[data-quantity]");
    const quantity = Number.parseInt(input.value, 10);
    return Number.isFinite(quantity) ? Math.min(20, Math.max(1, quantity)) : 1;
  }

  // WHAT: recomputes every line total, the subtotal/delivery/total, and
  //       toggles the empty state — called after any quantity/remove change.
  function updateCart(message = "") {
    const items = [...cartList.querySelectorAll("[data-cart-item]")];
    let subtotal = 0;
    let itemCount = 0;

    items.forEach((item) => {
      const price = Number(item.dataset.price) || 0;
      const quantityInput = item.querySelector("[data-quantity]");
      const decreaseButton = item.querySelector('[data-action="decrease"]');
      const quantity = getQuantity(item);

      quantityInput.value = quantity;
      decreaseButton.disabled = quantity <= 1;
      item.querySelector("[data-line-total]").textContent = formatPrice(price * quantity);

      subtotal += price * quantity;
      itemCount += quantity;
    });

    const hasItems = items.length > 0;
    const delivery = hasItems ? deliveryFee : 0;

    cartPage.querySelector("[data-cart-count]").textContent = itemCount;
    cartPage.querySelector("[data-cart-count-label]").textContent = itemCount === 1 ? "piece" : "pieces";
    cartPage.querySelector("[data-cart-subtotal]").textContent = formatPrice(subtotal);
    cartPage.querySelector("[data-cart-delivery]").textContent = formatPrice(delivery);
    cartPage.querySelector("[data-cart-total]").textContent = formatPrice(subtotal + delivery);

    emptyState.hidden = hasItems;
    checkoutLink.setAttribute("aria-disabled", String(!hasItems));
    if (message) states?.show("success", message);
  }

  // WHAT: handles increase/decrease/remove clicks via event delegation on the list.
  cartList.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;

    const item = button.closest("[data-cart-item]");
    const quantityInput = item.querySelector("[data-quantity]");
    const action = button.dataset.action;

    if (action === "increase") {
      quantityInput.value = Math.min(20, getQuantity(item) + 1);
      updateCart("Quantity increased.");
    }

    if (action === "decrease") {
      quantityInput.value = Math.max(1, getQuantity(item) - 1);
      updateCart("Quantity decreased.");
    }

    if (action === "remove") {
      const productName = item.querySelector("h3").textContent.trim();
      item.classList.add("is-removing");
      states?.show("loading");

      window.setTimeout(() => {
        try {
          item.remove();
          updateCart(`${productName} removed from your cart.`);
        } catch {
          item.classList.remove("is-removing");
          states?.show("error", "The item could not be removed. Please try again.");
        }
      }, 180);
    }
  });

  cartList.addEventListener("change", (event) => {
    if (!event.target.matches("[data-quantity]")) return;
    updateCart("Quantity updated.");
  });

  giftNote.addEventListener("input", () => {
    noteCount.textContent = giftNote.value.length;
  });

  cartPage.addEventListener("cart:loading", () => states?.show("loading"));
  cartPage.addEventListener("cart:success", (event) => {
    states?.show("success", event.detail?.message || "Your cart has been updated.");
  });
  cartPage.addEventListener("cart:error", (event) => {
    states?.show("error", event.detail?.message || "Your cart could not be updated.");
  });

  updateCart();
}
