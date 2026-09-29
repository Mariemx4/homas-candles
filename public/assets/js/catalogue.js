/*
 * FILE PURPOSE: Browser-side behavior for Catalogue.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: the type/sort filter form's loading/success/error status, shared
//       by shop.php and every category page.
// WHY:  "catalogue:success"/"catalogue:error" are scaffolding for a future
//       fetch-based filtering flow — nothing dispatches them yet, so today
//       a filter submit just does a normal full-page GET reload.
import { createStateController } from "./state_controller.js";
import { initCustomSelects } from "./custom_select.js";

const filters = document.querySelector("[data-catalogue-filters]");
const stateContainer = document.querySelector("[data-catalogue-states]");
const states = createStateController(stateContainer);

if (filters) initCustomSelects(filters);

if (filters && states) {
  filters.addEventListener("submit", () => {
    filters.setAttribute("aria-busy", "true");
    filters.querySelector('button[type="submit"]')?.setAttribute("disabled", "");
    states.show("loading");
  });

  filters.addEventListener("catalogue:success", () => {
    filters.removeAttribute("aria-busy");
    states.show("success");
  });

  filters.addEventListener("catalogue:error", (event) => {
    filters.removeAttribute("aria-busy");
    states.show("error", event.detail?.message || "Please try again in a moment.");
  });

  states.show(stateContainer.dataset.initialState || null);
}
