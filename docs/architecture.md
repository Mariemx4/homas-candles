# Architecture and ownership

Start with [the beginner walkthrough](beginner-handbook.md). This page is the
short reference for deciding where a change belongs.

## Runtime boundaries

```text
public/                    HTTP entry points; Apache serves only this folder
  admin/                   Existing admin URLs (preserved during cleanup)
  categories/              Canonical category pages and legacy URL adapters
  assets/
    js/admin/              Admin browser behavior, grouped in one area
    css/
      tokens.css           Shared brand, type and spacing primitives
      admin/
        tokens.css         Admin semantic roles that alias brand primitives
        layout/            Foundation, shell, workspace, responsive rules
        components/        Buttons, controls, tables, details, shared surfaces
        pages/             Feature-specific admin layouts
app/
  Bootstrap/               Creates and connects dependencies
  config/                  Configuration; *.local.php is private machine data
  database/migrations/     Applied, checksum-protected schema history
  database/reference.sql   Reference data for a newly provisioned database
  Views/
    Layouts/               Shared page shells
    Components/Admin/      Reusable admin fragments
    Pages/admin/           Feature folders: catalogue, orders, requests, etc.
    Pages/                 Storefront/customer pages and payment views
  translations/            en/fr/ar UI dictionaries
  storage/                 Private runtime data, never public source
src/
  Auth/                    Authentication/session policies
  Controllers/             Validate a request and prepare a response shape
  Services/                Business operations and rules
  Repositories/            SQL and persistence
  Database/                Transaction orchestration
  Http/                    Error boundary and safe internal/legacy routing
  View/                    Shared rendering and presentation helpers
  Security/                CSRF and security primitives
  Mail/                    Mail delivery contracts/implementations
  Maintenance/             PostgreSQL backup and restore verification
scripts/                   Local maintenance and verification commands
tests/                     Isolated unit, HTTP/database and browser checks
docs/                      Current guides plus dated implementation history
```

## A request has one direction

```text
URL -> public entry -> bootstrap -> controller -> service -> repository -> PostgreSQL
                                  |
                                  +-> view data -> layout -> page -> components
```

The bootstrap constructs dependencies; it does not decide business policy. The
controller interprets HTTP input; it does not issue SQL. The service controls a
use case and its transaction. The repository binds SQL values and returns data.
The view renders data; it does not fetch a second version of a record, and a
template never creates a repository or calls `database()` itself.

Building blocks that several bootstrap files need (the transaction manager,
order/customer/payment repositories, pricing, readiness and the accessory and
scent stock services) come from `App\Container\SharedServices`:

```php
$shared = \App\Container\SharedServices::for($databaseConnection);
$orderRepository = $shared->orderRepository();
```

It builds each one once per database connection, so two bootstraps loaded on
the same page receive the same objects. Everything it returns keeps no state
of its own; do not add a class that does. Page-specific controllers and
services stay in their bootstrap file. Add a method there only when a second
bootstrap needs the same object.

A service receives the services it uses through its constructor (see
`ManualOrderService`'s `customerModes`) instead of creating them inline. A
service that needs SQL gets its own repository: for example,
`CustomerOrderService` decides whether a customer may edit an order, and
`CustomerOrderEditRepository` holds the queries that save the edit.

Keep a public entry point thin, but retain its authorization, method checks,
response status, flash data and POST/Redirect/GET behavior. Moving a template
does not justify changing its URL or removing those checks.

## Services: folder and naming rules

`src/Services/` is deliberately one flat folder (about 50 classes). Names start
with their business area, so an alphabetical listing already groups them:
`Admin*`, `Custom*`, `Material*`, `Order*`, `Payment*`, `Pricing*`, `Product*`.
Moving them into sub-namespaces would change roughly 150 `use` lines, bootstrap
files and test fixtures, and stale Composer classmaps in an existing container
can keep loading an old copy of a moved class. That risk buys little
discoverability, so the 2026-11-12 refactor kept the folder flat and instead
split oversized classes by responsibility.

Rules for a new class:

| Kind of code | Where it goes | Example |
| --- | --- | --- |
| A use case an HTTP action triggers | `<Area>Service` in `src/Services/` | `PaymentService::record()` |
| A focused part of a large use case | `<Area><Responsibility>` next to it | `ProductCoreValidator` |
| A pure rule with no database access | small `final` class with static methods | `Money`, `Text`, `CatalogueCategory` |
| Reading untrusted list filters | `App\Http\ListQuery` | `ListQuery::date($query, 'date_from', $adjusted)` |
| SQL | the area's repository in `src/Repositories/` | `AdminProductRepository` |

When one service grows several unrelated workflows, extract the workflow into
its own class and keep the original public methods as one-line delegates.
Callers (controllers, bootstraps, tests) then keep working unchanged.

### Shared helpers (use these instead of private copies)

- `Money` — exact TND/quantity arithmetic in integer millis.
  `parseMillis`/`parseSignedMillis` validate typed input (null when invalid);
  `storedMillis`/`storedSignedMillis` read PostgreSQL DECIMAL text (0 when
  unreadable); `formatMillis`/`formatSignedMillis` produce `"12.345"`.
  `ContactRequestService` keeps its own parser on purpose: the public contact
  form also accepts a comma as the decimal separator.
- `Text::length()` — character (not byte) length for VARCHAR limits, so Arabic
  and accented names are measured correctly.
- `Money::remainingAmount()` (balance still owed, never below zero) and
  `Money::signedDifference()` (e.g. a profit that may be negative).
- `FormInput::string/trimmed/positiveId/csrfToken()` (`src/Http/`) — safe
  readers for POST fields. Controllers still call `$csrf->validate()` and
  `rotate()` themselves, because when a token rotates differs per form (the
  Google start button deliberately does not rotate it).
- `SqlFragments::escapeLike()` and `idPlaceholders()` (`src/Repositories/`) —
  literal LIKE search text (queries declare `ESCAPE '='`) and named `IN (...)`
  placeholders.
- `ListQuery::string/allowed/date()` — safe readers for `$_GET` list filters
  that report "a filter was reset" through `$adjusted`. Each service still
  owns its own allowed values and pagination state.
- `CatalogueCategory::CANDLES`, `SETS`, `DIFFUSERS`, `ACCESSORIES`, `BOUQUETS`,
  `EVENT_FAVORS` — database category slugs that business rules depend on.
  PHP logic uses the constants; the remaining literal slugs are inside SQL
  text in repositories, where they stay readable as SQL.

Comment style: explain ownership, invariants and *why*. Template comments
such as "VALIDATION IDIOM" or "Create X to apply this feature's business
rules" were removed on 2026-11-12 because they repeated the code; do not
reintroduce them.

### Catalogue product administration

`AdminProductService` is the only class `AdminProductController` calls for
`/admin/catalogue/products.php`. It keeps the list, the edit-screen data, core
saves (including the accessory stock-concurrency check), drafts, colors,
scents, set components and activation, and delegates:

- `ProductCoreValidator` — the core form and its category rules;
- `ProductTranslationService` — save, generate (PENDING/FAILED drafts) and
  review translations; approval is refused if the source text changed;
- `ProductImageService` — store new file → update row in a transaction →
  delete the new file on failure, or the old file after commit if unused;
- `PricingService` and `ProductReadinessService` — prices, and the
  transaction wrapper that rejects changes breaking an active product.

### Custom requests

`CustomRequestService` owns the request list/detail, recording a request,
brief corrections (with `briefVersion` concurrency and revision history),
assignment, status transitions and contact attempts.
`CustomRequestDefinitionValidator` validates requested products and their
components for both creation and correction; `CustomRequestIntakeValidator`
checks the rest of the "record a request" form. `CustomRequestListFilters`
reads and rebuilds the inbox filters, and `CustomRequestAttention` decides the
status badge. Conversion to an order belongs to `CustomRequestConversionService`.

### Manual orders and payments

The same pattern applies: the service runs the workflow and its transaction,
and a validator checks the form and returns `[values, errors]` without saving.

- `ManualOrderService` creates staff-entered orders and the price preview;
  `ManualOrderValidator` checks the order form (customer, date and delivery,
  addresses, each line, totals and notes, one method each).
- `PaymentService` records, verifies and rejects payments;
  `PaymentRecordValidator` checks the payment form and `PaymentListFilters`
  the payments list filters. Limits that depend on the order (amount due,
  duplicates) are checked by the service while the order is locked.

`AdminProductRepository` and `CustomRequestRepository` are long but made of
short single-purpose queries used by several services; they were deliberately
not split.

## Shared presentation and routing

- `App\View\Presentation` owns HTML escaping, readable enum labels, TND display
  and common date/time display. It never calculates a selling price or writes a
  business timestamp. Those remain service responsibilities.
- `App\View\AdminIcons` owns local SVG definitions and named row controls. Its
  generated HTML is trusted application markup; record labels/URLs are escaped.
- `App\View\SiteNavigation` supplies the public header's category drawer for
  pages that did not load categories themselves (catalogue pages pass theirs).
  A database outage gives an empty drawer instead of a broken page.
- `App\View\AdminNavigation` owns sidebar/tab definitions and their order.
  Fragments render these entries without duplicating route/icon maps.
- `App\Http\AdminRecordNavigation` owns allowlisted list/record destinations.
  Promotion editing uses `edit`; other record editors use `id`.
- `App\Http\LegacyCategoryRedirect` keeps four older category URLs working,
  including their query strings, with the existing 301 response.

`VIEW_PATH` points to `app/Views`. Use it for template includes instead of a
chain of `../../` segments. Public route files remain in their original places.
Namespaces follow PSR-4: `App\View\Presentation` lives in
`src/View/Presentation.php`. Renaming either side requires updating its callers.

## CSS ownership and cascade

`public/assets/css/pages/admin.css` is the ordered manifest.
`AdminAssets::styles()` versions each imported dependency independently.

1. Shared tokens and base rules establish site-wide primitives.
2. Admin tokens define semantic roles without creating a second brand.
3. Layout/components/pages establish the normal interface.
4. Workspace composition and responsive rules apply the established final layout.

Use an existing variable when its value and purpose match. For example, use
`--space-md` for the shared 1rem spacing and `--admin-border` for admin borders.
Do not replace a distinct color with a vaguely similar one: that changes the
design. A semantic alias may reference a brand primitive; it must not repeat
the raw value in several files. Distinct decorative colors remain distinct.

The cleanup removed exact-selector declarations that were always overridden,
grouped identical adjacent rules, and removed styles for proven-unused legacy
dashboard/loading elements. It did not reorder competing active selectors or
merge rules across incompatible responsive contexts.

### Token reference (public/assets/css/tokens.css)

| Need | Token |
| --- | --- |
| Text / headings | `--color-ink`, `--color-text-muted`, `--color-text-soft` |
| Brand accents | `--color-caramel`, `--color-primary`, `--color-secondary` |
| Surfaces | `--color-paper`, `--color-off-white`, `--color-page-background` |
| Lines | `--color-line`; on dark panels `--color-line-on-dark` |
| Selected/hover fill, page glow | `--color-gold-tint`, `--color-caramel-tint` |
| Sign-in/sign-up palette | `--color-auth-caramel`, `--color-auth-gold`, `--color-auth-line` |
| Spacing (padding, margin, gap) | `--space-xs` 0.25, `--space-sm` 0.5, `--space-md` 1, `--space-lg` 1.5, `--space-xl` 2 rem |

Page stylesheets use these directly. Per-page aliases (`--home-ink`,
`--cart-line`, …) were removed on 2026-11-12: they all resolved to the same
global tokens and only hid where a colour came from. The header keeps its own
`--header-*` tokens because its caramel/ink differ from the global ones.

### Known design drift (needs a design decision, not a code cleanup)

These values are close but not equal, so replacing them with a token would
change pixels. Decide the intended scale with screenshots first:

- spacing: 0.2, 0.35, 0.4, 0.45, 0.55, 0.6, 0.65, 0.7, 0.75, 0.8, 0.85, 1.1,
  1.2, 1.25 rem appear alongside the token scale (0.75rem alone is used 66 times);
- text colour: about 30 different opacities of the ink colour
  `rgba(50, 25, 13, …)` are used, e.g. 0.42, 0.45, 0.52, 0.55, 0.6, next to the
  `--color-text-soft` (0.58) and `--color-text-muted` (0.62) tokens;
- storefront corner radii: 0.5, 0.55, 0.6, 0.65, 0.7, 0.75, 0.85, 1, 1.25 rem.

## Browser behavior

`public/assets/js/admin/workspace.js` has named initialization functions for
request grouping, tabs, form journeys, guided steps, filters, edit recovery,
product settings, optional addresses, stock previews and production returns.
The order is intentional: groups exist before tabs, and steps exist before
validation/error handling tries to reveal them.

The file stays in one piece on purpose: the functions share one closure (the
`.admin-main` element, the session-storage helper and the icon cloner) and must
run in order. Splitting it would need a global namespace and several
order-dependent `<script>` tags. Find a behavior by its function name:

| Function | Markup it enhances |
| --- | --- |
| `groupRequestSections`, `initializeRecordTabs` | `data-workspace-panel` sections → tabs |
| `groupFormJourneys`, `initializeGuidedForms` | declared form steps |
| `initializeFilters` | list filter forms |
| `protectUnsavedWork`, `showFieldErrors` | unsaved-change warning, error summary |
| `initializeCreateMenus`, `initializeProductDetails` | create menus, product settings |
| `initializeOptionalFields`, `initializeStockPreview` | optional address, stock preview |
| `preserveProductionContext` | `data-return-order` round trip |

Browser scripts enhance existing PHP forms. They do not replace server pricing,
CSRF validation, lifecycle rules or stock transactions. Session storage contains
panel identifiers only; working drafts remain in the private server session.
See [the UI contracts](admin-dashboard/ui-maintenance.md) for data attributes.

## Data ownership

Private configuration, payment evidence, generated product images and database
backups are runtime data. They are not development artifacts to delete during a
source cleanup. Applied SQL migrations retain their original bytes and checksums.
Use a new migration when changing schema; never edit an already-applied one.

The requested local data reset preserved the admin account and categories. The
language and migration rows are infrastructure, not demo business records.
Tests create separate generated schemas/databases and never repopulate the shop.

## Keeping the structure healthy

```powershell
php scripts/check-project.php
node scripts/sync-local.js --dry-run
```

The first command checks literal view/asset references, stylesheet imports and
class namespace locations without connecting to PostgreSQL. Follow it with the
relevant [runtime checks](../tests/README.md) and use the
[Docker guide](local-docker.md) to sync or rebuild safely.

PHP uses the non-risky rules in `.php-cs-fixer.dist.php`; JavaScript/CSS use
`.prettierrc.json`. `.editorconfig` keeps indentation and line endings consistent.
`.gitattributes` pins the line endings of `app/database/migrations/*.sql` to the
exact bytes the live database checksummed (CRLF for 001–020, LF afterwards);
never re-save an applied migration with different line endings.
Formatting tools are development tools, not application runtime dependencies.
