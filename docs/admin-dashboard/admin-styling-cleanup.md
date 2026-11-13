# HOM’AS admin styling cleanup

The existing admin was refactored in place. Storefront CSS, controllers, services,
pricing, payment, inventory and lifecycle behavior were left untouched by this
task. Pre-existing unfinished workspace changes were preserved.

## 1–3. CSS files

Changed under `public/assets/css/admin/`: `foundation.css`, `shell.css`,
`components.css`, `controls.css`, `login.css`, `dashboard.css`, `orders.css`,
`requests.css`, `payments.css`, `catalogue.css`, `inventory.css`, `production.css`,
`customers.css`, and `reports-settings.css`. `public/assets/css/pages/admin.css`
is now an import-only manifest again.

Added in the existing admin directory: `layout.css`, `buttons.css`, `tables.css`,
`badges.css`, `alerts.css`, `searchable-select.css`, `details.css`,
`composition.css`, and `lifecycle.css`.

No CSS files were removed. Admin no longer imports the storefront's entire
`components.css` and its header/footer/page components. The public storefront
continues to use those original files unchanged.

## 4–5. Consolidation and dead code

- Buttons previously owned by Orders and Login now have one shared definition.
- Native inputs, selects, textareas, enhanced controls, radios and checkboxes
  share control sizing, borders, focus, invalid and disabled states. Duplicate
  Translation, Payment and status-form control declarations were removed.
- Generic headers moved out of Dashboard; forms/filters moved out of Orders and
  Requests. Tables/pagination, badges, alerts, detail layouts and product grouping
  each have a shared owner. Page files retain their specific layouts.
- The manifest's newer product total, profit, component and select patches moved
  into their owning files instead of becoming another override layer.
- Usage searches across PHP views and JavaScript confirmed no references to
  `admin-manual-recipe`, `admin-manual-recipe__line`,
  `admin-manual-account-choice`, or `admin-price-override`. Their obsolete rules
  were removed. Historical order production recipe styling remains because it
  is still rendered. Existing calculated-price outputs remain supported.
- Duplicate responsive form patches and the conflicting grid display on table
  cells were removed. Names/secondary text stack inside real table cells.

## 6–7. Brand tokens and shared components

Reused existing `--color-paper`, `--color-ink`, `--color-caramel`, `--color-line`,
`--color-page-background`, `--color-text-muted`, `--color-error`,
`--font-family-body`, `--font-family-heading`, and `--space-md`.

Admin-specific tokens inherit from `.admin-body`: opaque surfaces, control
border/surface, 44px standard and 36px compact control heights, 14px body and
12px helper text, spacing, radii, dropdown layer, content/form widths, and shared
warning/success/info/danger colors. The storefront tokens themselves were not
changed. Cards use subtle surfaces and borders; shadows are reserved for menus
and the existing shell/login treatment.

Standardized page headers, buttons, forms, filters, table scroll regions,
pagination, detail panels, product grouping, badges, alerts, empty-state text and
searchable selects. Permanent deletion has a danger variant; recoverable Trash
and archive actions have a warning variant. Customer total stays more prominent
than the internal estimate.

## 8. Pages affected

Source-level styling corrections cover Dashboard, order lists/create/edit/detail,
status and Trash confirmations, custom request composition and conversion,
Products, Categories/Types/Colors/Scents, Promotions, Materials/Inventory,
Customers, administrator accounts, Payments, Reports/Settings and Production.
Shared selectors continue to cover archive and existing detail pages.

Administrator creation moved into the page header. Lifecycle review gained a
bounded, padded panel using shared actions. The production calendar preserves
seven readable columns in its own keyboard-accessible horizontal scroll region.
These are implemented corrections, **not claims that every live page was
visually reviewed**.

## 9–11. Selects, clipping and responsive behavior

- Enhanced controls use the same control styling as native inputs and selects.
  Native IDs/values remain the submission source.
- Menus are opaque, bounded and internally scrollable, with selected/active
  states, wrapped primary names and secondary context.
- Panels and filters allow overflow. Open selectors receive a modest stacking
  layer; the admin topbar/sidebar remain above page content.
- Placement now excludes the sticky admin topbar, fixing an upward menu that
  the mobile geometry test found behind that bar. Storefront fixed-bar handling
  remains supported.
- Headers, action rows, filters, customer-mode choices and composition headings
  wrap; grid children can shrink. Long names and badges wrap instead of being
  hidden. Simple forms have a separate maximum width from data views.
- Tables retain table-cell layout and scroll within focusable regions. Monetary
  cells in the generic table use tabular numerals and avoid wrapping.
- Long catalogue variant text and upload forms can shrink. Calendar navigation
  stacks on narrow screens without reducing day text to unreadable sizes.

## 12–14. Specificity, JavaScript and markup

Admin retains one documented `!important`: `.admin-body [hidden]` must override
component grid/flex display as a behavioral contract. Earlier page-specific
hidden rules, error-color escalation and unnecessary print escalations were
removed. No new inline product styles were introduced.

Existing JavaScript selectors, data attributes and generated classes are
preserved. The only JavaScript edit for this task adds `.admin-topbar` to the
shared select's viewport obstruction detection and supports its sticky position.
The mobile menu's expected `.admin-menu-button__label` was restored as visually
hidden text; it supplies an accessible name and prevents drawer code from
throwing on the missing label.

Markup changes: header placement for the administrator action, lifecycle and
Trash button variants, lifecycle panel class, keyboard access/names for previously
unnamed table scroll regions, generic money-cell class, the calendar scroll
wrapper, and Promotions' empty-row colspan corrected from five to six columns.

## 15–16. Verification and limits

Passed:

- `node tests/Unit/verify-admin-layout-browser.js`: real Chromium geometry checks
  at **1440×1000, 1024×900, 390×844 and 320×740**. An exact-size iframe avoids
  Chromium on Windows' 500px minimum top-level window. Checks cover page width,
  native/enhanced control heights, compact radios, hidden states, validation
  color, real table-cell alignment, keyboard scroll regions, calendar scrolling,
  opaque menus, menu hit-testing, internal menu scrolling, Escape, focus,
  mobile drawer open/close and customer-total hierarchy.
- `node tests/Unit/verify-manual-selector-browser.js`: Chromium manual-order
  scenarios A–E, Product/Color/Scent changes, native submission, taxonomy sync,
  option refresh, pricing previews and added rows.
- Existing searchable-select, order-customer-form, order-product-selection,
  order-status-control, public-drawers and PHP orders-workspace checks.
- PHP lint for every view and `git diff --check`.

Both available browser entry attempts reported that the browser was unavailable.
No screenshots or live visual page review were performed. The Chromium tests
use isolated presentation fixtures, not authenticated routes or production data.
No known failure remains in the executed checks. Live data-heavy pages, actual
upload widgets, print appearance and end-to-end custom-request composition still
need a visual pass once a connected browser is available; fixture checks cannot
prove that every record and every page renders correctly.
