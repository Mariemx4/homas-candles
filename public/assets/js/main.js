/*
 * FILE PURPOSE: Browser-side behavior for Main.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: the one script loaded on every page (see app/Views/Layouts/footer.php).
// WHY:  a composition root — it doesn't do anything itself, it just pulls
//       in the modules every page needs regardless of which page it is.
// Anything page-specific (cart.js, product.js, auth.js, ...) is loaded
// separately in footer.php, only on its own page.
import "./header_layout.js";
import "./carousel.js";
import "./floating_menus.js";
import "./icons.js";
import "./search_states.js";
