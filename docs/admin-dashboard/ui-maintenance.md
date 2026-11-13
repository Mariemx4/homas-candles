# Maintaining the Hom’As admin UI

The admin and storefront share the Hom’As identity. Keep decisions about color,
icons and navigation in the shared files below so individual screens cannot
gradually acquire a different theme or action vocabulary.

## Color ownership

`public/assets/css/tokens.css` owns the existing storefront identity:

| Role | Original token | Value |
| --- | --- | --- |
| Primary brown | `--color-primary` | `#6d3b07` |
| Chocolate | `--color-secondary` | `#391c0b` |
| Caramel | `--color-caramel` | `#96592d` |
| Paper | `--color-paper` | `#fffaf4` |
| Cream | `--color-off-white` | `#fff3e7` |
| Page | `--color-page-background` | `#f8ecdf` |

`admin/tokens.css` maps these to semantic admin roles. It also owns the
warm supporting neutrals, control sizes and semantic outcome colors.
`admin/layout/workspace.css` composes layouts using those roles; it must not introduce
another brand palette or redefine foundation tokens. Keep success, warning,
error and information colors for their respective meanings.

`admin/layout/responsive.css` loads last and owns phone/tablet composition and responsive
type sizes. Its portrait breakpoint is 40rem; simple record tables become cards
at 48rem. Coarse-pointer inputs retain 16px text in wider landscape layouts.
Avoid adding competing mobile overrides to individual page stylesheets.

Use `--admin-surface` for panels, `--admin-surface-soft` for secondary surfaces,
`--admin-accent` for primary controls and `--admin-muted` for supporting text.
Do not lower text opacity to create hierarchy. Current sampled contrast ratios
are 8.88:1 for primary brown on paper, 6.38:1 for muted text on paper, and
8.64:1 for secondary navigation text on chocolate. These are measured color
pairs, not an assertion of complete accessibility certification.

The stylesheet manifest is `public/assets/css/pages/admin.css`.
`AdminAssets::styles()` hashes each dependency separately; keep this mechanism
when adding a stylesheet so child-file edits cannot remain cached indefinitely.

## Icons and action controls

`src/View/AdminIcons.php` is the local SVG registry. It is autoloaded
when a view needs an icon. An icon is decorative: the control supplies its name.

```php
use App\View\AdminIcons;

// Familiar repeated actions in a directory: named 44px icon controls.
echo AdminIcons::recordActions($editUrl, 'products', (int) $product['id'], $product['name']);

// A single compact action with a record-specific accessible name and tooltip.
echo AdminIcons::actionLink($requestUrl, 'eye', 'View request '.$requestNumber);
```

Use visible text alongside icons for primary actions, unfamiliar decisions,
saving and destructive confirmation. Do not turn deletion into a one-click row
action. The settings control opens existing dependency-aware management;
confirmation, CSRF and server-side lifecycle rules remain authoritative.

The layout's `#admin-icon-library` template exposes a small subset of the same
registry to JavaScript. `setControlLabel()` clones an SVG and inserts plain text;
it never builds SVG/HTML from record names. No CDN is needed for admin icons.
Standalone PHP rendering fixtures must load `app/Bootstrap/autoload.php` so the
shared rendering classes resolve. Print-only views retain their separate presentation contract.

## Workspace and form contracts

`public/assets/js/admin/workspace.js` progressively arranges existing PHP forms.
It does not own business validation, prices or persistence.

- `data-workspace` names a record workspace; its direct
  `data-workspace-panel` children become keyboard-operable tabs.
- Panel IDs are stable link targets. User selection updates the URL fragment
  without adding a history entry. Nested error targets reveal the owning tab.
- `data-request-section="Conversation"` declares request section ownership;
  other values are `Overview`, `Brief` and `History`. Declare this attribute on
  every section or supporting paragraph that must survive request grouping.
  Never infer ownership from heading text or translated labels.
- `data-form-journey` selects order, request or conversion guidance.
  `data-journey-step="0"` through the final zero-based step explicitly assigns
  content. Supporting unmarked nodes follow the preceding step. Templates,
  scripts and hidden identity/security inputs remain in the form.
- `data-order-discounts` identifies the optional discount fieldset without
  relying on its legend. New fields must remain inside the original POST form.

All sections remain readable without JavaScript. Continue validates its current
step before advancing; Back and Continue move keyboard focus to the new step.
Native validation can reopen hidden steps and collapsed details. Session storage
contains section identifiers only. Draft data stays in the existing private
server session; do not add personal information or prices to browser storage.

Quick search uses the original GET form via the HTML `form` attribute.
Clear filters returns to the same list route without filter/page parameters.
Do not create a second independent search endpoint or duplicate form values.

## Record management and promotions

`AdminRecordNavigation` owns the allowlisted internal list and record routes.
Use `recordPath()` when returning from status management and `listPath()` after
deletion. Customers return to their detail record; promotions use `edit`, not
`id`, and return to `#promotion-editor`. Never accept a raw return URL or a
Referer header as a redirect target.

Promotion editing keeps the existing code visible and read-only. Save retains
the edit identity and form anchor; Create another promotion explicitly opens
the creation state. Existing orders continue to use frozen promotion snapshots.

## Verification

Use a separate preview container and generated fixture schema, as described in
`tests/Browser/prepare-admin-preview.php`. Never point browser acceptance writes
at a shop account. The browser suite exercises actions, focus, linked tabs,
customer/request navigation, promotions and all record sections/form steps at
1024, 390 and 320px, with additional Today/mobile navigation checks.

The isolated fixture has bounded address limits and creates an order/product;
prepare a fresh fixture for a full run. Cleanup removes only its validated
generated schema. The suite enables headless focus emulation so `:focus` and
`:focus-visible` are tested with an active document.

Relevant checks:

```powershell
docker exec homas-test php tests/Integration/verify-admin-redesign.php
docker exec homas-test php tests/Integration/verify-admin-workflow-recovery.php
docker exec homas-test php tests/Integration/verify-admin-lifecycle.php
docker exec homas-test php tests/Integration/verify-catalogue-workflow.php
docker exec homas-test php tests/Unit/verify-orders-workspace.php
node tests/Unit/verify-admin-layout-browser.js
node tests/Unit/verify-admin-confirmation-browser.js
node tests/Unit/verify-manual-pricing-browser.js
node tests/Unit/verify-request-conversion-browser.js
```

The two PHP-rendering browser fixtures require a local PHP CLI (`HOMAS_PHP`
overrides its path). Full authenticated browser verification uses
`HOMAS_PREVIEW_STATE` and optional `HOMAS_RENDER_DIR` environment variables.

## Responsive record cards

`admin-responsive.js` runs after workspace initialization. It annotates simple,
single-header-row tables with phone labels derived from their real headers.
It moves no forms, clones no record values, and adds no persistence. Explicit
table/row/header/cell roles preserve browser accessibility associations when CSS
changes the display mode. Visual field labels have `aria-hidden="true"` to avoid
announcing each header twice.

Desktop rendering stays native. On phones/tablets, the same cells form cards:
the first field identifies the record, ordinary fields use two columns, and
actions, forms and long notes use the full width. No columns are hidden. Native
multi-level/matrix tables are left intact; `data-keep-table` explicitly opts out
when a future table needs horizontal comparison. Without JavaScript, the original
scrollable table remains usable. Print styles retain the original table layout.

The former scroll container loses its scroll-specific label/tab stop only in
card mode; both are restored if the viewport becomes wide again. Do not remove
the underlying headers or caption to implement a mobile layout.

The authenticated browser suite now enables mobile metrics and touch input,
hit-tests targets before sending touch events, and completes order draft,
product selection and creation through the phone interface. It checks all
sections at 320, 390, 430, 768 and 1024px, labeled card visibility, field widths,
input font sizes, the browser accessibility tree, and landscape drawer behavior.
This is Chromium emulation; the record does not claim physical iOS/Android
device testing.
