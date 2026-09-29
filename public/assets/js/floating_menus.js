/*
 * FILE PURPOSE: Browser-side behavior for Floating Menus.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: open/close logic for the two header slide-out drawers — the
//       categories menu and the account menu (see app/Views/Layouts/header.php).
// WHY:  toggles [hidden] and aria-expanded together, so screen readers and
//       the visual state always agree on whether a drawer is open.
// Note: unlike header_layout.js/search_states.js, this file assumes these
// elements always exist (no null-check before use) — safe today because
// header.php is a global include, but worth guarding the same way if this
// script is ever reused somewhere the header markup might be missing.

//Category panel
const categoryMenuButton = document.querySelector(
  ".category-menu-trigger"
);

const categoriesDrawer = document.querySelector(
  "#categories-drawer"
);

const closeCategoryMenuButton = document.querySelector(
  ".close-category-menu"
);

/** FUNCTION PURPOSE: Open categories drawer and update its accessibility/focus state. */
function openCategoriesDrawer() {
  categoriesDrawer.hidden = false;
  categoryMenuButton.setAttribute("aria-expanded", "true");
}

/** FUNCTION PURPOSE: Close categories drawer and restore its neutral accessibility/focus state. */
function closeCategoriesDrawer() {
  categoriesDrawer.hidden = true;
  categoryMenuButton.setAttribute("aria-expanded", "false");
}

categoryMenuButton.addEventListener(
  "click",
  openCategoriesDrawer
);

closeCategoryMenuButton.addEventListener(
  "click",
  closeCategoriesDrawer
);


// Account panel
const accountMenuButton = document.querySelector(
  ".account-menu-trigger"
);

const accountDrawer = document.querySelector(
  "#account-drawer"
);

const closeAccountMenuButton = document.querySelector(
  ".account-drawer-button"
);

/** FUNCTION PURPOSE: Open account drawer and update its accessibility/focus state. */
function openAccountDrawer() {
  accountDrawer.hidden = false;
  accountMenuButton.setAttribute("aria-expanded", "true");
}

/** FUNCTION PURPOSE: Close account drawer and restore its neutral accessibility/focus state. */
function closeAccountDrawer() {
  accountDrawer.hidden = true;
  accountMenuButton.setAttribute("aria-expanded", "false");
}

accountMenuButton.addEventListener(
  "click",
  openAccountDrawer
);

closeAccountMenuButton.addEventListener(
  "click",
  closeAccountDrawer
);
