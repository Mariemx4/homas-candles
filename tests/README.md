# Verification

The application runtime is PHP 8.3/Apache with PostgreSQL 17 in Docker. Node.js
and a local Chromium browser run the browser fixtures; no frontend build step
or runtime npm dependency is required by the website.

## Start with structural checks

```powershell
php scripts/check-project.php
node scripts/sync-local.js --dry-run
```

The first check validates template/asset imports and namespace locations without
connecting to PostgreSQL. The second previews the source sync, excluding private
configuration and runtime files. Use the same current source in the application
container before running integration checks.

## Isolated integration suites

```powershell
docker exec homas-test php tests/Integration/verify-admin-redesign.php
docker exec homas-test php tests/Integration/verify-admin-reliability.php
docker exec homas-test php tests/Integration/verify-admin-workflow-recovery.php
docker exec homas-test php tests/Integration/verify-catalogue-workflow.php
docker exec homas-test php tests/Integration/verify-customer-payments.php
docker exec homas-test php tests/Integration/verify-admin-lifecycle.php
docker exec homas-test php tests/Integration/verify-login-http.php
docker exec homas-test php tests/Integration/verify-google-auth.php
docker exec homas-test php tests/Integration/verify-customer-auth.php
docker exec homas-test php tests/Integration/verify-payments-workflow.php
docker exec homas-test php tests/Integration/verify-order-pricing-snapshots.php
docker exec homas-test php tests/Integration/verify-customer-order-management.php
docker exec homas-test php tests/Integration/verify-order-edit.php
docker exec homas-test php tests/Integration/verify-manual-order-customers.php
docker exec homas-test php tests/Integration/verify-product-translation-review.php
docker exec homas-test php tests/Integration/verify-customer-directory.php
php tests/Unit/verify-money.php
```

Run `verify-product-image-upload.php` the same way, but only in a preview container (see Environment notes).

These selected suites create/remove generated schemas or databases and synthetic
accounts. They do not use the shop account as a fixture. HTTP checks start their
own temporary PHP server. Fixtures use the current reference data or deliberate
minimal test data; they do not require the removed legacy seed file.

### Isolation status (check before running anything)

A suite is isolated when it uses `create_throwaway_database()`,
`create_throwaway_auth_schema()`/`isolate_application_database()` from
`tests/Support/`, or an in-memory SQLite database. Suites that load an admin
bootstrap must call `isolate_application_database()` straight after it:
`database()` returns one shared connection, so the services the bootstrap
built follow the generated schema. That helper also refuses to continue
unless the connection is on a generated schema, and drops the schema on exit
even when an assertion fails.

`verify-customer-directory`, `verify-custom-request-workflow`,
`verify-order-status-workflow`, `verify-production-workflow`,
`verify-admin-orders`, `verify-admin-reports`, `verify-administrator-auth`,
`verify-password-reset` and `verify-repositories` were converted on
2026-11-12. `verify-contact` already used SQLite and now asserts it.

**Enforced:** `php scripts/check-project.php` fails when any suite in
`tests/Integration/` lacks one of these isolation mechanisms. Only the two
read-only legacy suites below are exempt, by name.

`verify-database.php` and `verify-migrations.php` are **obsolete MySQL-era
checks** (`DATABASE()`, a `mysql:` connection). They only read, cannot run on
PostgreSQL, and are kept until someone decides to port or retire them.

### Writing a suite: shared helpers

Use the helpers in `tests/Support/` instead of copying code between suites:

- `TestCheck.php` gives the `$check(condition, message)` function. A failing
  check throws `RuntimeException`, which stops the suite while its `finally`
  block still cleans up. Choose `TestCheck::quiet()`, `TestCheck::printing()`,
  `TestCheck::printing($checks)` (also counts) or
  `TestCheck::collecting($results)` (prints later).
- `TestPhpServer.php` starts a temporary `php -S` server for HTTP suites:
  `TestPhpServer::reserveAddress()`, then `TestPhpServer::start(...)` with the
  environment changes (always point `PGOPTIONS` at the generated schema; a
  `null` value removes a variable) and `$server?->stop()` in `finally`. It
  throws if the server is not accepting connections after about 8 seconds.
- `HttpTestClient.php` sends requests and follows session-ID changes;
  `throwaway-auth-schema.php` / `throwaway-database.php` provide isolation.

`verify-application` and `verify-admin-password-recovery` still start their
processes by hand: one uses a different launch sequence, the other drives an
interactive terminal.

### Environment notes

- HTTP suites that start a temporary PHP server (`verify-login-http`,
  `verify-admin-workflow-recovery`, `verify-admin-redesign`,
  `verify-admin-reliability`, `verify-manual-order-customers`,
  `verify-customer-order-management`, `verify-customer-payments`, …) expect Google
  sign-in to be **unconfigured**. With `google-auth.local.php` present, every
  admin page redirects to the configured origin and they fail before testing
  anything. Run them in a disposable preview container without that file.
  `verify-google-auth` and `verify-product-image-upload` set their own fixture
  Google values and work either way.
- `verify-product-image-upload` writes real files into
  `public/assets/products` and removes them; run it in a preview container.

### Status of previously failing suites (classified 2026-11-12)

Every suite was run in a disposable container against both the current code
and the pre-refactor source. All failures except one came from tests, not the
application, and the tests were updated to the documented current rules:

| Suite | Cause | Kind |
| --- | --- | --- |
| `verify-application` | storefront search used `LIKE` on a nondeterministic collation (SQLSTATE 0A000) — latent on live (empty catalogue) | **real defect, fixed** in `ProductRepository::catalogueFilter()` |
| `verify-accessory-stock` | expected Trash to return consumed stock (rule changed 2026-11-10); product ID sequence not advanced | stale expectation, broken fixture |
| `verify-order-status-workflow`, `verify-production-workflow` | items inserted without the recipe snapshot / `recipe_source` that real orders save; markup paths from before the redesign | broken fixture, stale expectation |
| `verify-inventory-workflow` | expected silent discarding of locked material fields; the service refuses them explicitly | stale expectation |
| `verify-custom-request-workflow`, `verify-admin-orders` | compared PostgreSQL NUMERIC IDs (strings) with `===` to integers | broken assertion |
| `verify-admin-reports` | month boundaries written in UTC instead of the Tunisia business day; clean-up FK order | stale expectation, broken clean-up |
| `verify-admin-dashboard` | view variables overwrote the test's `$check`; links/summary from before the "Today" redesign | test harness, stale expectation |
| `verify-manual-order-production`, `verify-manual-order-customers` | catalogue lines no longer take recipe input; self-deactivation needs the confirmation checkbox | stale expectation |
| `verify-password-reset` | clean-up FK order | broken clean-up |

Still failing, by decision: `verify-migration-upgrade` simulates an upgrade
from MySQL-era migration checksums, which no PostgreSQL database can have.
See `docs/refactor-2026-11-12.md` ("Migration checksums") before changing it.
Migration line endings are pinned by `.gitattributes`; a migration that looks
"modified" after a fresh clone usually means its line endings were converted.

Additional maintenance/recovery checks:

```powershell
docker exec homas-test php tests/Integration/verify-admin-completion.php
docker exec homas-test php tests/Integration/verify-clean-installation.php
docker exec homas-test php tests/Integration/verify-backup-restore.php
docker exec homas-test php tests/Integration/verify-admin-password-recovery.php
```

The PostgreSQL role needs the creation privileges used by the throwaway helpers.
Backup restore checks target their own newly generated database, never the shop.

## Unit and browser fixtures

```powershell
docker exec homas-test php tests/Unit/verify-orders-workspace.php
docker exec homas-test php tests/Unit/verify-google-oidc.php
node tests/Unit/verify-admin-layout-browser.js
node tests/Unit/verify-admin-confirmation-browser.js
node tests/Unit/verify-manual-pricing-browser.js
node tests/Unit/verify-request-conversion-browser.js
```

`Unit/` includes pure checks and synthetic browser fixtures. The latter render
actual PHP form markup with local PHP (`HOMAS_PHP` overrides its path) and use a
temporary Chromium profile. They test geometry/events with synthetic responses;
they are separate from authenticated application acceptance.

## Full authenticated browser run

`Browser/prepare-admin-preview.php` must run in a separate preview container.
It creates a generated fixture schema and a private state file. The preview's
`PGOPTIONS` must select that schema; expose it only on `127.0.0.1:8787`.

Set `HOMAS_PREVIEW_STATE` to a private local copy of the state file and optionally
set `HOMAS_RENDER_DIR`, then run:

```powershell
node tests/Browser/verify-admin-redesign.js
```

Use a fresh fixture for a full run: it creates an order/product and adds customer
address data with real cardinality limits. Cleanup mode removes only the fixture's
validated generated schema. Delete its temporary state/configuration copies and
container afterward. Do not point the browser suite at the actual shop schema.

The browser suite covers 22 screens, all tabs/steps, mobile touch interaction,
card/table accessibility structure, landscape navigation and failed-input
recovery. It uses Chromium emulation; physical device testing is separate.

## Formatting

PHP formatting rules are in `.php-cs-fixer.dist.php`. JavaScript/CSS formatting
uses `.prettierrc.json`; editor defaults are in `.editorconfig`. Formatting tools
are developer tools, kept outside the runtime dependency graph. Applied SQL
migrations and private `*.local.php` files are excluded from PHP formatting.
