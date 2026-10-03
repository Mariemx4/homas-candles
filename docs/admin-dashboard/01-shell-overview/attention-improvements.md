# Dashboard attention improvements

The existing PHP architecture, admin layout, component styles and workflow services are retained. The dashboard remains a read-only overview; actions open the established admin pages.

## Changed files

Paths below are relative to the repository root.

| Files | Change |
| --- | --- |
| `app/database/migrations/021_add_material_minimum_stock_level.sql` | Adds a nullable, nonnegative `DECIMAL(12,3)` material threshold; no fabricated backfill. |
| `src/Repositories/MaterialRepository.php` | Reads/writes thresholds, shares the low-stock predicate with the dashboard, adds low/unconfigured filters, and binds all editable material fields. Unreferenced tracking changes initialize or clear the tracking balance without replacing an existing tracked balance. |
| `src/Services/MaterialService.php` | Requires explicit numeric thresholds for tracked saves, validates decimal precision/range, allows referenced-material threshold edits, uses saved tracking for locked identities, and preserves thresholds during activation changes. |
| `src/Controllers/MaterialController.php` | Includes the new stock-attention filter in the safe error response. Existing CSRF and authorization remain in place. |
| `app/Views/Pages/admin-inventory/admin-inventory-material-edit.php` | Adds the threshold field, selected-unit label, validation feedback and tracking-dependent field state. |
| `public/assets/js/admin-material-form.js` | Updates the unit label as the unit changes; hides/disables the threshold for untracked materials. |
| `app/Views/Pages/admin-inventory/admin-inventory-materials.php` | Adds low-stock/unconfigured filters, minimum values and low-stock badges; removes advice to infer attention from quantities across units. |
| `src/Repositories/AdminDashboardRepository.php` | Counts all low-stock materials, lists up to five by name, counts missing thresholds, selects real request messages and related payment order IDs. |
| `src/Services/AdminDashboardService.php` | Builds the updated metrics, UTF-8-safe 100-character request summaries, urgent order identifiers and deadline context. |
| `src/Services/DeadlinePresentation.php` | Calculates Today, Tomorrow, In N days and Overdue by N days using calendar dates in the supplied application timezone. |
| `src/Controllers/AdminDashboardController.php` | Keeps the fallback view model aligned with the new stock fields. |
| `app/Views/Pages/admin-dashboard.php` | Links all five summary cards and detail identifiers, adds View order actions, shows threshold warnings/configuration guidance and contextual dates. Places urgent work, upcoming orders, stock, requests and payments in the requested order. |
| `app/Views/Components/Admin/stat-card.php`, `public/assets/css/admin/components.css` | Adds optional full-card semantic links with accessible names and visible keyboard focus; other stat-card consumers remain supported. |
| `app/Views/Components/Admin/data-table.php` | Adds configurable escaped detail links and optional deadline badges to the existing table component. |
| `public/assets/css/admin/dashboard.css` | Uses existing tokens/badges, allows unit/minimum wrapping and accommodates urgent action links. |
| `src/Services/OrderService.php`, `src/Repositories/OrderRepository.php`, `app/Views/Pages/admin-order/admin-orders.php` | Adds an INCOMPLETE list filter alongside existing status/date filters, keeping the due-soon destination consistent with its counter. This is a list filter, not a workflow status. |
| `src/Services/CustomRequestService.php`, `src/Repositories/CustomRequestRepository.php`, `app/Views/Pages/admin-custom-request/admin-custom-requests.php` | Adds an OPEN list filter. Replaces MySQL FIELD sorting with equivalent PostgreSQL-compatible CASE sorting so the destination list loads. OPEN is not a workflow status. |
| `src/Repositories/PricingRepository.php` | Fixes PostgreSQL locks used during material edits: locks only product rows on the outer join and uses FOR SHARE. Existing pricing formulas and historical order snapshots are preserved. |
| `tests/Integration/verify-admin-dashboard.php` | Uses a disposable PostgreSQL schema; checks stock rules, filtered destinations, summaries, escaping, migration safety and database constraints. |
| `tests/Integration/verify-inventory-workflow.php` | Uses a disposable PostgreSQL schema; verifies create/edit thresholds, locked identities, invalid inputs, activation preservation, filters, existing movement/pricing/security behavior. |
| `tests/Unit/verify-deadline-presentation.php` | Checks calendar-day labels, invalid/missing dates and a timezone with a DST boundary. |
| `tests/Integration/verify-pricing-workflow.php`, `tests/Integration/verify-readiness-workflow.php`, `tests/Integration/verify-production-workflow.php` | Supplies explicit thresholds in tracked-material fixtures to match the updated material-save contract. |
| `docs/database-design.md`, `docs/admin-dashboard/01-shell-overview/README.md`, `docs/admin-dashboard/07-inventory/README.md`, this file | Documents the field, attention rules, changed files and manual verification. |

## Schema and stock rules

Apply migration **021** using the existing runner after deploying the updated workspace files to the application runtime:

```powershell
php scripts/migrate.php
```

For a Docker runtime containing the updated files:

```powershell
docker exec homas-test php scripts/migrate.php
```

The application schema has not been migrated as part of these checks. Migrations and fixtures were exercised only in disposable test schemas. The running Docker application contains a copy of the project, so it needs the updated files before running its migration command.

Each threshold is stored on its own material in the material's `unit`. Numeric input uses the existing decimal convention: a decimal point, up to three fractional digits, and a maximum of `999999999.999`. Negative, missing, non-scalar and unsupported numeric formats are rejected for tracked material saves. Zero is accepted only as an explicit administrator choice.

Low stock means `is_active = 1`, `tracks_stock = 1`, a non-NULL threshold, and `stock_quantity <= minimum_stock_level`. Equality counts as low stock. No global threshold, cross-unit ranking or approaching-low percentage is used. The dashboard count covers all matches even though its list shows only five; the card and View all low stock link open the full paginated inventory filter.

Existing materials migrate to NULL, meaning **unconfigured**, not zero. They generate no warning until configured. The dashboard reports how many active tracked materials need configuration and links to them. When editing a tracked legacy material, the administrator must supply a threshold before saving. Disabling tracking on an unreferenced material clears its threshold; referenced identities remain locked, while their threshold is editable.

## Clickable elements and assumptions

- Requested orders → `admin/orders/index.php?status=REQUESTED`.
- Due within 7 days → the same list with `status=INCOMPLETE` and the dashboard's `date_from`/`date_to`.
- Pending payments → `admin/payments/index.php?status=PENDING_VERIFICATION`.
- Open custom requests → `admin/requests/index.php?status=OPEN`.
- Low stock materials → `admin/inventory/materials.php?stock_status=low`.
- Urgent View order and upcoming order identifiers → existing order details.
- Request identifiers → existing request details.
- Pending-payment order identifiers → existing order payment details, with the established verification workflow.
- Material names → existing material edit/details; configuration guidance → `stock_status=unconfigured`.

The existing date window is preserved: today through today + seven calendar days, both inclusive. Overdue orders appear in urgent actions but not in the due-soon counter. The application's existing PHP timezone remains authoritative; no new timezone setting is introduced. Existing PREPARING/PREPARED and fulfilment status badges already expose workflow progress, so there is no new production field. Summaries use only `customer_message`; a missing message displays a dash.

## Manual verification

1. Deploy updated files and apply migration 021. Confirm existing material thresholds are NULL and stock balances are unchanged. Open the dashboard: unconfigured tracked materials should be reported separately.
2. Create active tracked materials: wax with 3.5 kg/minimum 2 kg, fragrance with 80 ml/minimum 100 ml, and jars with 4 pieces/minimum 10 pieces. The counter should increase for fragrance and jars only. Check each warning shows its SKU, stock, unit, minimum and Low stock badge.
3. Test threshold equality and explicit zero. A stock balance exactly at its minimum is low; zero stock with a configured zero minimum is also low. A NULL threshold is excluded.
4. Submit a blank, negative, text, over-range or four-decimal threshold. The tracked material must not save. Confirm three-decimal input persists exactly. Change the unit in a new/unreferenced material and check that the threshold label follows it.
5. Edit a material with an opening movement or recipe reference. Its identity fields remain locked; its minimum can change. Deactivate it: its warning disappears and threshold remains saved. Untracked materials produce no warnings.
6. Click every summary card and confirm the visible filter and row set match the counter. Apply/clear filters and move between pages; INCOMPLETE, OPEN and stock-attention filters should persist.
7. Open each urgent View order link, upcoming order identifier, request identifier, payment order identifier and material name. Confirm the correct existing details page loads. Use existing pages to perform status changes or payment verification.
8. Use incomplete orders required yesterday, today, tomorrow and in three days. Confirm overdue/day-context labels and real dates. Upcoming orders remain nearest-date first, and terminal orders are excluded.
9. Give an open request a long message containing accented text and HTML characters. Confirm it is truncated safely, displayed as text, and links to the correct request. A missing message shows a dash.
10. Configure all active tracked materials above their thresholds and confirm the healthy empty state. With unconfigured materials, confirm the missing-minimum guidance remains visible. With unavailable data, confirm the existing alert and the stock-unavailable state.
11. Tab through cards and links: each must be reachable and have a visible focus indicator. Check desktop/mobile widths for clipping and table scrolling. Browser visual inspection was unavailable in this session; rendered HTML, route targets and escaping were checked automatically.

Automated checks (run in a runtime with the current project files and PostgreSQL connection):

```powershell
docker exec homas-test php tests/Integration/verify-admin-dashboard.php
docker exec homas-test php tests/Integration/verify-inventory-workflow.php
php tests/Unit/verify-deadline-presentation.php
```

The two integration checks create random schemas and remove them in `finally`; they never use existing shop fixtures. The configured PostgreSQL role needs permission to create schemas and ICU collations.
