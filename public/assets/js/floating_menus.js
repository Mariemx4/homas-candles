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

function openCategoriesDrawer() {
  categoriesDrawer.hidden = false;
  categoryMenuButton.setAttribute("aria-expanded", "true");
}

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

function openAccountDrawer() {
  accountDrawer.hidden = false;
  accountMenuButton.setAttribute("aria-expanded", "true");
}

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
