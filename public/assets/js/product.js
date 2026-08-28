// WHAT: the product detail page's image gallery (thumbnails + color swap)
//       and quantity stepper.
// WHY:  only runs if `.product-page[data-product-available="true"]` is on
//       the page — i.e. product.php actually found a real product — so
//       this never tries to wire up a gallery that doesn't exist.
import { createStateController } from "./state_controller.js";

const productPage = document.querySelector('.product-page[data-product-available="true"]');

if (productPage) {
  const mainImage = productPage.querySelector("[data-product-main-image]");
  const thumbnailButtons = productPage.querySelectorAll("[data-gallery-image]");
  const colorInputs = productPage.querySelectorAll("[data-color-image]");
  const quantityInput = productPage.querySelector("[data-product-quantity]");
  const states = createStateController(productPage.querySelector("[data-product-states]"));

  // WHAT: swaps the main image, updates which thumbnail looks "active",
  //       and reports loading/success/error via the state panel.
  function showImage(imageUrl) {
    if (!mainImage || !imageUrl) return;

    states?.show("loading");
    mainImage.addEventListener("load", () => {
      states?.show("success", "The product image now matches your selected colour.");
    }, { once: true });
    mainImage.addEventListener("error", () => {
      states?.show("error", "That product image could not be loaded.");
    }, { once: true });
    mainImage.src = imageUrl;

    thumbnailButtons.forEach((button) => {
      const isActive = button.dataset.galleryImage === imageUrl;
      button.classList.toggle("active", isActive);
      button.setAttribute("aria-pressed", String(isActive));
    });
  }

  thumbnailButtons.forEach((button) => {
    button.addEventListener("click", () => showImage(button.dataset.galleryImage));
  });

  // WHAT: picking a color swatch also swaps the main image to that color's photo.
  colorInputs.forEach((input) => {
    input.addEventListener("change", () => {
      if (input.checked) showImage(input.dataset.colorImage);
    });
  });

  if (quantityInput) {
    const minimum = Number.parseInt(quantityInput.min, 10) || 1;
    const maximum = Number.parseInt(quantityInput.max, 10) || 20;
    const decreaseButton = productPage.querySelector('[data-product-action="decrease"]');

    function normalizeQuantity() {
      const requested = Number.parseInt(quantityInput.value, 10);
      const quantity = Number.isFinite(requested)
        ? Math.min(maximum, Math.max(minimum, requested))
        : minimum;

      quantityInput.value = quantity;
      decreaseButton.disabled = quantity <= minimum;
    }

    productPage.addEventListener("click", (event) => {
      const button = event.target.closest("[data-product-action]");
      if (!button) return;

      normalizeQuantity();
      const current = Number.parseInt(quantityInput.value, 10);

      if (button.dataset.productAction === "increase") {
        quantityInput.value = Math.min(maximum, current + 1);
      }

      if (button.dataset.productAction === "decrease") {
        quantityInput.value = Math.max(minimum, current - 1);
      }

      normalizeQuantity();
      states?.show("success", `Quantity updated to ${quantityInput.value}.`);
    });

    quantityInput.addEventListener("change", () => {
      normalizeQuantity();
      states?.show("success", `Quantity updated to ${quantityInput.value}.`);
    });
    normalizeQuantity();
  }

  productPage.addEventListener("product:error", (event) => {
    states?.show("error", event.detail?.message || "The product could not be updated.");
  });
}
