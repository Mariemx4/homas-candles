# Customer account workspace — 11 November 2026

Scope: the signed-in customer's own account, profile and saved addresses.
The administrator customer directory is unchanged.

## Customer-facing behavior

- `account.php` greets the customer, summarizes active/completed orders and saved
  addresses, and highlights their next active order. Pickup-ready orders take
  priority; other active orders follow required date.
- The spotlight shows the current fulfillment stage and an appropriate next
  action. A pending transfer prompts verification rather than another deposit.
  The existing payment endpoint remains authoritative for amounts and methods.
- Order history uses cards that fit phone screens. Four URL-based filters keep
  pagination scoped to all, active, completed or cancelled orders. Every query
  remains bounded and tied to the authenticated customer's ID.
- Empty histories explain what to do next. Counters and the spotlight describe
  the full account even when a particular history filter is selected.
- `profile.php` groups primary contacts, optional contacts, addresses and security.
  Errors retain submitted values, identify the affected fields and receive focus.
  The browser warns before leaving a modified profile.
- Customers can choose an owned saved address as their default. At three saved
  addresses, marking one for removal reveals a replacement fieldset. One save
  can remove and replace it while preserving the one-to-three-address rule.
- Password recovery links to the existing email reset flow; no password is shown
  in account pages. Deactivation requires explicit confirmation and valid CSRF.
- English, French and Arabic copy is provided. CSS uses the current Hom’As palette
  and preserves clearance below the measured fixed storefront header.

## Maintenance boundaries

`CustomerAccountReadRepository` owns scoped read projections, aggregates, filters
and the next-order query. `CustomerAccountController` normalizes the URL filter
and page before passing data to the overview template.

`CustomerAccountView` supplies local SVG icons, translated error categories,
date presentation and display-only order metadata. It does not change order
state, calculate a selling price or authorize a payment. Its destinations are
relative internal routes; templates add `BASE_URL` so subdirectory installs work.

The navigation and address-field fragments under `app/Views/Components/` are
shared by the overview/profile templates. `public/profile.php` retains request
authorization, CSRF, flash data and POST/Redirect/GET, with markup in
`app/Views/Pages/customer-profile.php`.

`AccountProfileService` validates default-address ownership before any write,
holds the existing account lock, preserves the optimistic profile version and
records changes in the existing audit. `AdminLifecycleRepository::setPrimaryAddress`
clears the previous default and assigns the chosen owned address in that same
transaction. Omitted default selection preserves existing callers' behavior.
Historical order/address snapshots are untouched. No migration is required.

`customer-account.js` enhances hash navigation, address replacement, validation
visibility and unsaved-change protection. Authorization, ownership, address limits,
stale edits and persistence remain server responsibilities. Without JavaScript,
links, filters, profile editing and default selection still use ordinary HTTP;
replacing an address at the limit requires removing/saving before adding another.

## Validation and recovery

The customer integration suite covers profile/address rules, ownership, concurrency,
pricing, payment credit, cancellation, tracking, localized rendering, invalid CSRF,
default-address audit and stale edits. It uses a disposable PostgreSQL schema.

Run `docker exec homas-test php tests/Integration/verify-customer-order-management.php`.
The completed pass reports 76 checks. Run `php scripts/check-project.php` for the
470 read-only structural checks on view paths, assets, stylesheets and namespaces.

The browser review exercised populated/empty accounts at widths 320, 390, 430,
768, 1024 and 1440; French and Arabic on mobile; filter/pagination behavior;
real profile POSTs; validation; default selection; replacement; unsaved warnings;
and existing order detail/edit/payment pages. Fixed-header overlap and horizontal
overflow were explicitly checked. Screenshots below contain synthetic records.
The completed browser pass reports 65 checks with no JavaScript exceptions.

Before-change source copies and the delivery manifest are retained privately in
`homas-candles-archive/storage-backups/customer-account-20261111/`. Tests never require repopulating
the cleaned shop database. Runtime configuration, uploads and existing account
credentials are outside this source change.

## Screenshots

- [Desktop overview](customer-account-2026-11-11/account-1440.png)
- [Mobile overview](customer-account-2026-11-11/account-390.png)
- [Mobile profile](customer-account-2026-11-11/profile-390.png)
- [New customer empty state](customer-account-2026-11-11/account-empty-390.png)
