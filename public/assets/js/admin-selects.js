/*
 * FILE PURPOSE: Browser-side behavior for Admin Selects.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

import { initCustomSelects } from "./custom_select.js";

const adminContent = document.querySelector(".admin-main");

if (adminContent) {
  initCustomSelects(adminContent);
}
