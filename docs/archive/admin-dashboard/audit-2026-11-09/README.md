# Admin dashboard diagnostic — 9 November 2026

This is an audit, not an implementation change. Existing workspace changes and shop records were preserved.

**Repair status:** The subsequently approved [first repair batch](repair-batch-1.md)
addresses F01–F05 and F12–F13. The findings below describe the original audited
state; the linked repair record contains the current implementation and checks.
The [second repair batch](repair-batch-2.md) covers admin cancellation,
independent forms across tabs, large request payloads, conversion review and
fulfilment, and correction/history for separate requested-product definitions
(F06–F11). Its record explains the boundaries for legacy website enquiries.

## Scope and evidence

- Reviewed the ten existing admin module specifications, entry points, templates, handlers, services, repositories, shared CSS, and JavaScript.
- Connected to the running `homas-test` application at `http://localhost`. It uses PHP 8.3.35 and PostgreSQL 17.11.
- Sampled source hashes for the profile service, request JavaScript, admin layout, and schema test helper: all matched the workspace. The container uses a built source copy, with no bind mounts.
- Compared live database table/column metadata with a fresh schema from current migrations: no missing columns or differing column types. This comparison does not prove every constraint, index, or function is identical.
- Authenticated HTTP checks used real application entry points on a separate PHP server with synthetic accounts and a disposable schema. They did not sign into or mutate existing shop accounts.
- The working tree and container both lack `app/database/seed.sql`. The reference seed from Git HEAD was supplied **only to a temporary test copy** so workflow checks could proceed. Passing results below are conditional on that temporary repair.
- Browser connection failed with “The browser access host is unavailable.” No current screenshots, rendered mobile inspection, pointer testing, or visual approval is claimed.
- `http-results.json` records the HTTP and repository diagnostics. `schema-results.json` records the metadata comparison. Neither file contains passwords or session tokens.

Evidence labels: **Runtime** means reproduced with the current application code and PostgreSQL runtime; **Source** means established by tracing the implementation; **Review** means a UX recommendation requiring rendered validation.

Priority: **P1** blocks a normal business operation or makes reliability claims untrustworthy; **P2** causes failed work, misleading behavior, or substantial friction; **P3** is a consistency improvement.

## What the dashboard should support

| Area | Complete user journey |
| --- | --- |
| Overview | Identify urgent work, open the correctly filtered list, distinguish a loading failure from no work. |
| Orders | Create catalogue orders, identify the customer, review totals, save, edit, progress/correct status, handle cancellation, print, move to Trash and restore. |
| Custom Requests | Receive website enquiries, record offline briefs, search, assign, follow up, correct a brief, agree a quote, convert once, finish production, create catalogue drafts, archive/restore. |
| Payments | Record actual receipts, upload evidence, approve/reject D17, see verified/pending/remaining/credit amounts independently of fulfilment status. |
| Catalogue | Find records, configure translations/options/images/recipes/prices, understand activation blockers, publish/deactivate safely. |
| Inventory | Find materials, edit allowed fields, record stock changes with a ledger, recover from errors without retyping. |
| Production | Search/filter the queue, see dates and blockers, inspect material feasibility, print usable preparation sheets. |
| Customers | Find an account, review history, edit current details, return to the original customer/search, create a linked order. |
| Reports | Filter and export clearly defined totals, preserve historical records, distinguish sales from collected cash and estimates. |
| Accounts and Settings | Save every offered field, manage access with clear consequences/history, see truthful operational capabilities. |

## Functional findings

### F01 — P1 — Search fails across eight admin surfaces [Runtime]

**Affected:** Products, Categories, Product Types, Colors, Scents, Materials, Custom Requests, and Production Queue.

**Reproduce:** Populate the relevant list and search for `Audit`. PostgreSQL rejects the query with SQLSTATE `0A000`: `LIKE` is used against the configured nondeterministic collation. Products, taxonomy, materials, and a populated production queue return HTTP 503. Custom Requests displays its unavailable state with HTTP 200. Empty tables can hide the defect because the query need not evaluate a row.

**Cause:** Incomplete PostgreSQL search conversion. Orders, payments, and customers already use explicit deterministic collation in their search expressions; these repositories still use plain `LIKE`.

**Needed:** Apply a consistent, explicit search/case/Unicode policy and test populated lists, literal `%`/`_`, case differences, and Arabic/French names.

Sources: [products](../../../../src/Repositories/AdminProductRepository.php), [taxonomy](../../../../src/Repositories/TaxonomyRepository.php), [materials](../../../../src/Repositories/MaterialRepository.php), [requests](../../../../src/Repositories/CustomRequestRepository.php), [production](../../../../src/Repositories/ProductionRepository.php).

### F02 — P1 — Website enquiry intake calls an unavailable database function [Runtime + Source]

`CustomRequestRepository::countRecentWebRequests()` throws SQLSTATE `42883` because it calls MySQL's `UNIX_TIMESTAMP()`. `ContactRequestService::submit()` calls this before inserting a valid website enquiry. This breaks the intake path feeding the admin inbox; the complete browser submission was not exercised.

**Needed:** PostgreSQL-compatible time-window queries and an actual PostgreSQL intake test. The current contact test emulates the MySQL function in SQLite, so it cannot catch this incompatibility.

Sources: [request repository](../../../../src/Repositories/CustomRequestRepository.php), [intake service](../../../../src/Services/ContactRequestService.php), [contact test](../../../../tests/Integration/verify-contact.php).

### F03 — P2 — Administrator secondary email is silently discarded [Runtime]

Save a valid secondary email in Administrator Accounts. The POST redirects as successful, but `second_email` remains NULL. The form offers it; `AccountProfileService` removes it for administrators.

**Needed:** Persist the offered administrator field and verify it by reloading. Customer `second_email` exists through migration 027: the earlier suspected customer-column mismatch is **ruled out**.

Sources: [profile service](../../../../src/Services/AccountProfileService.php), [account form](../../../../app/Views/Pages/admin/accounts/index.php).

### F04 — P2 — Failed inventory movements erase the entered quantity and note [Runtime]

Submit an excessive stock adjustment with a meaningful note. Stock correctly remains unchanged, but the returned form drops the quantity and note. Movement type also lacks a submitted-value selection.

**Needed:** Restore all safe submitted fields and show the validation message next to the relevant field.

Source: [material editor](../../../../app/Views/Pages/admin/inventory/material-edit.php).

### F05 — P2 — Failed contact actions erase entered notes and follow-up time [Runtime]

Submit a contact action with an invalid outcome and valid notes/follow-up. The controller flashes safe input, but the detail form renders blank fields. Assignment/status forms have the same missing redisplay integration in source.

**Needed:** Refill the specific failed action form without changing unrelated forms.

Source: [request detail](../../../../app/Views/Pages/admin/requests/detail.php).

### F06 — P2 — Admin cancellation is inconsistent with the order model [Runtime]

The schema and customer workflow support `CANCELLED`. The admin dropdown disables it and calls it “pickup only.” A valid-token admin status POST requesting cancellation redirects without changing the order. Existing cancelled orders are also outside the status service's progress/correction path.

**Needed:** Define an explicit admin cancellation/correction policy, expose supported actions, explain payment/inventory consequences, and label unsupported actions accurately. This does not imply that all cancellation states should be permitted.

Sources: [status service](../../../../src/Services/OrderStatusService.php), [status control](../../../../app/Views/Components/Admin/order-status-select.php).

### F07 — P2 — Saving one tab expires forms in other tabs [Source]

Multiple controllers rotate the single session CSRF token after POST, including invalid submissions. Already-open order/request/material forms then contain obsolete tokens.

**Needed:** A deliberate multi-tab token strategy with separate duplicate-submission protection. Preserve edits and explain recovery when a token really expires.

Sources: [CSRF manager](../../../../src/Security/CsrfTokenManager.php), [material controller](../../../../src/Controllers/MaterialController.php), [request controller](../../../../src/Controllers/CustomRequestController.php).

### F08 — P2 — Supported request sizes exceed PHP's form-input limit [Runtime configuration + Source]

The UI allows up to 50 requested products with 50 components each. PHP is configured for 1,000 input variables. Supported forms can exceed that limit well before the UI maximum. Truncation was not submitted end to end, so the exact resulting error or partial record is unverified.

**Needed:** Bound the supported payload consistently, detect incomplete submissions, and test the actual maximum against server configuration.

Sources: [request JavaScript](../../../../public/assets/js/admin/custom-request.js), [request component](../../../../app/Views/Components/Admin/custom-request-products.php).

### F09 — P2 — Custom conversion lacks a total/deposit review [Runtime HTML + Source]

Unlike manual-order entry, conversion provides no order summary before creation. Administrators enter batch/unit prices without an equivalent displayed final total and required deposit.

**Needed:** A clear customer total, delivery, deposit, and internal estimate where available, confirmed again by the server.

### F10 — P2 — Custom conversion has inconsistent delivery controls [Runtime HTML + Source]

The conversion form has no fulfilment enhancement script or customer-mode root. Changing pickup to delivery does not provide the same fee/address behavior as manual-order entry. Address fields remain visible for pickup.

**Needed:** Reuse fulfilment behavior independently of customer selection, including server validation and no-JavaScript recovery.

Source for F09–F10: [conversion page](../../../../app/Views/Pages/admin/requests/convert.php).

### F11 — P2 — Submitted custom briefs lack a clear correction flow [Source / workflow gap]

The reviewed detail screen manages assignment, contact, status, conversion and drafts, but does not edit the requested-product definitions created earlier. Correcting a mistaken working name, quantity or component has no clear supported path.

**Needed:** Define when a brief can change, preserve revision history, and lock agreement/production data at the correct stages.

Source: [request detail](../../../../app/Views/Pages/admin/requests/detail.php).

### F12 — P2 — Dashboard failures display misleading zeros and empty-success messages [Source]

When dashboard loading fails, the controller supplies empty collections. Cards default to zero and several sections say no urgent orders/no payments, alongside the failure alert. Zero and unavailable are different business states.

**Needed:** Render unavailable metrics/sections distinctly and retain a clear retry action. Stock already has a partial unavailable-state distinction that can be reused.

Sources: [dashboard controller](../../../../src/Controllers/AdminDashboardController.php), [dashboard view](../../../../app/Views/Pages/admin/dashboard/index.php).

### F13 — P2 — Custom-request failures return HTTP 200 and lose filter context [Runtime + Source]

The failing request search returns HTTP 200, resets filters/search to empty values, and reports zero rows. That hides the error from basic health checks and makes recovery harder.

**Needed:** Correct failure status, preserve the attempted safe filters, and distinguish failure from an empty result.

Sources: [request controller](../../../../src/Controllers/CustomRequestController.php), [inbox entry point](../../../../public/admin/requests/index.php).

### F14 — P2 — Profile edits can overwrite concurrent edits [Source]

Profiles have no submitted version comparison equivalent to order editing. Row locks serialize writes but do not detect an administrator submitting an old form after another edit.

**Needed:** Detect stale forms and preserve submitted changes for review.

### F15 — P2 — Account management lacks a durable actor/change audit [Source / requirement gap]

The reviewed profile/status handlers authorize the administrator but do not persist that actor and before/after values for account creation, edits and suspension. Existing order/payment actor references do not provide this account-change history.

**Needed:** Define permissions and persist relevant account-change history without credential values.

Sources for F14–F15: [profile service](../../../../src/Services/AccountProfileService.php), [lifecycle repository](../../../../src/Repositories/AdminLifecycleRepository.php).

### F16 — P1 — Backup implementation is incompatible with this deployment [Source]

The app uses PostgreSQL in Docker. The backup script invokes a fixed Windows XAMPP `mysqldump.exe` path with MySQL options. It cannot perform the advertised backup for this deployment.

**Needed:** PostgreSQL-compatible operator backup/restore instructions and verification against a disposable database. No real backup or restore was executed during the audit.

### F17 — P2 — Settings can advertise an unusable backup as available [Source]

Capability detection checks script existence and storage placement, not whether a compatible backup executable or tested mechanism exists.

**Needed:** Truthful capability/status copy and an explicit distinction between configured, available, last succeeded, and restore-tested where supported.

Sources for F16–F17: [backup script](../../../../scripts/backup-database.php), [settings service](../../../../src/Services/AdminSettingsService.php), [settings page](../../../../app/Views/Pages/admin/settings/index.php).

### F18 — P2 — Business dates use UTC rather than Tunisia's calendar day [Runtime configuration + Source]

PHP reports UTC and PostgreSQL reports Etc/UTC. Dashboard `today`, deadlines and database daily-order limits use those clocks without an explicit business timezone. Tunisia's day begins one hour before UTC's day; around local midnight these date-based behaviors can disagree with the owner's calendar day. No midnight-time simulation was performed.

**Needed:** An explicit business timezone for date rules and consistent timestamp storage/display. Test boundaries rather than changing every timestamp indiscriminately.

### F19 — P2 — Sign-in timeout copy disagrees with the active fallback [Source]

The sign-in screen promises 15 minutes. The bootstrap passes a 9,000-second fallback, while the session class has another default. An environment override may change the effective timeout further.

**Needed:** One authoritative effective timeout and matching copy.

Sources: [auth bootstrap](../../../../app/Bootstrap/admin/administrator-auth.php), [session class](../../../../src/Auth/AdministratorSession.php), [sign-in page](../../../../public/admin/login.php).

## Interface, interaction and design findings

These are based on current markup/styles and HTTP-rendered HTML. Visual sizing, clipping and pointer behavior still require a connected browser.

| ID | Priority / evidence | Problem | Needed direction |
| --- | --- | --- | --- |
| U01 | P2 / Source | Customer management actions and request product content precede the record heading/feedback. | Consistent order: breadcrumb, title/state, primary actions, feedback, working content. |
| U02 | P2 / Review | Labels and helper/action text are frequently 12px; body text is 14px; sidebar abbreviations are approximately 9px at a 16px root. | Test a readable hierarchy on real desktop/mobile screens; avoid tiny operational labels and unclear abbreviations. |
| U03 | P2 / Source | Cormorant Garamond and Lato are declared but not loaded by the reviewed admin layout/styles. | Intentionally load/self-host chosen fonts or choose deliberate system fallbacks, then verify wrapping. |
| U04 | P2 / Calculated CSS | Control border `#cdbbaa` has approximately 1.86:1 contrast against its white interior; nearby cream surfaces also provide little separation. | Strengthen the boundary where it identifies an empty input. W3C requires 3:1 for essential non-text cues; context determines applicability. |
| U05 | P2 / Source | The mobile sidebar is translated offscreen without inert/hidden handling. Links can remain in the keyboard order while closed. | Make the closed drawer inaccessible to focus; maintain focus trapping/return when open. |
| U06 | P2 / Source | Status Save has only an externally generated icon as visible content. | Provide local SVG or visible Save text; an external icon failure must not produce a blank-looking action. CDN availability was not established. |
| U07 | P2 / Source | Advancing the status dropdown can run intermediate transitions and material deductions; correcting backwards preserves physical usage. The compact control does not explain those consequences. | Separate ordinary progress from correction where useful and explain material/price/payment effects before consequential changes. |
| U08 | P2 / Source | “Delete order” means a recoverable move to Trash; other areas use archive, deactivate, and “Manage status / deletion.” | Use effect-specific wording and consistent warning/danger treatments. Explain reversibility. |
| U09 | P2 / Review | Settings and forms expose schema/transaction/query terminology that does not help daily shop operations. | Use business language, with technical operator guidance kept separately. |
| U10 | P2 / Source | Long forms have no consistent unsaved-change warning or draft recovery. | Preserve work and warn on navigation when edits would be lost. |
| U11 | P2 / Source | Customer editing drops the original directory search context, and its editor lacks a clear return-to-customer action. | Carry a validated return destination and provide Back/Cancel consistently. |
| U12 | P2 / Source | Field-level error association/focus is inconsistent; account errors are collapsed into a general paragraph. | Error summary linked to fields, preserved values, inline messages, and focus on the actionable error. |
| U13 | P3 / Review | The shared enhancement replaces simple selects as well as long searchable lists when their markup permits it. | Decide deliberately where native versus searchable controls help; verify keyboard and mobile behavior for each. |
| U14 | P2 / Source | The production calendar declares a grid with gridcells but lacks the corresponding row structure and grid keyboard interaction. | Use coherent grid semantics/behavior or simpler linked calendar markup; include full dates in accessible day-link names. |
| U15 | P2 / Source | CSS cache versioning tracks the import manifest, while imported component URLs are unversioned. Editing a child stylesheet alone does not change those request URLs. | Deploy/version the stylesheet dependency set consistently. Visible staleness depends on cache headers and was not reproduced. |

Design sources: [foundation](../../../../public/assets/css/admin/layout/foundation.css), [controls](../../../../public/assets/css/admin/components/controls.css), [navigation](../../../../public/assets/js/admin/navigation.js), [status control](../../../../app/Views/Components/Admin/order-status-select.php), [calendar](../../../../app/Views/Pages/admin/production/calendar.php), [admin layout](../../../../app/Views/Layouts/admin.php).

Contrast assessment reference: [W3C non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html). The existing caramel text on paper computes to about 5.38:1; a blanket palette replacement is not justified. Retain the brand and improve semantic contrast, hierarchy and consistency. Text thresholds: [W3C contrast minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

## Verification and delivery problems

| ID | Priority | Finding | Evidence / correction needed |
| --- | --- | --- | --- |
| V01 | P1 | Missing reference seed blocks clean setup and full-schema checks. | Reproduced in the unmodified container. Decide whether to restore the reference seed or migrate all consumers to its replacement. |
| V02 | P1 | Runtime/maintenance/test infrastructure mixes PostgreSQL and MySQL assumptions. | Preflight still checks PDO MySQL/MariaDB; several integration helpers construct MySQL connections; intake and backup have actual engine incompatibilities. Standardize on the deployed engine. |
| V03 | P2 | Some tests assert obsolete behavior or fail in cleanup, obscuring what works. | Orders sorting returns the correct ID/value but test compares PostgreSQL's string ID strictly to an int. Inventory test tries to change locked identity fields. Customer directory/report checks pass their assertions then fail obsolete cascade cleanup. A profile HTTP assertion expects old English text. Repair the checks before claiming a green regression suite. |

## Checks that passed and what they prove

Nine integration suites completed successfully in isolated schemas after the seed was supplied only to the temporary copy:

| Suite | Passed checks |
| --- | ---: |
| Admin overview | 24 |
| Payments workflow | 46 |
| Catalogue workflow | 43 |
| Lifecycle management | 95 |
| Order editing | 44 |
| HTTP login/logout | 12 |
| Order pricing snapshots | 129 |
| Pricing workflow | 18 |
| Customer order management | 50 |
| **Total in these nine suites** | **561** |

Other results:

- Login throttling and password-upgrade checks passed in Docker.
- Pricing-rules and product-response unit checks that lacked a SQLite driver on Windows passed in Docker.
- The prior workspace pass linted 393 PHP files successfully and ran six JavaScript unit checks successfully. Those are syntax/selected-behavior results, not current visual proof.
- The HTTP audit exercised 45 admin route variants, then additional failed-form, cancellation and populated-production-search scenarios. Opening a page with HTTP 200 is not proof that all controls on it work.
- Reports completed 17 assertions and the customer directory completed 19 assertions before their outdated cleanup failed. These suites are **not reported as passing**.
- Manual-order/customer HTTP checks completed 94 assertions, including manual creation, pricing preview, request creation/conversion/drafts and lifecycle pages, before an old profile-text assertion failed. The suite is **not reported as passing**.
- Orders, order-status, inventory, production, custom-request, directory, reports, manual-order/customer, manual-production and readiness suites did not complete successfully. Causes include real search defects, obsolete fixtures/assertions, cleanup assumptions and MySQL-only helpers. No failed suite is treated as verification of its remaining checks.

## Proposed repair sequence and acceptance

1. **Database/runtime reliability:** Repair the eight searches, website enquiry intake, seed/setup/preflight/backup mismatch, and misleading error responses. Acceptance: populated list searches and valid enquiry submission succeed on PostgreSQL; genuine failures display unavailable states; clean isolated setup and compatible backup/restore verification work.
2. **Data entry and workflow recovery:** Repair secondary-email persistence, failed-form redisplay, supported payload limits, multi-tab behavior, cancellation policy, conversion totals/fulfilment, and brief corrections. Acceptance: entered work survives errors, each offered action has a supported outcome, and prices/payments/inventory remain consistent.
3. **Shared UX system:** Standardize hierarchy, action wording, error patterns, return navigation, fonts, readable type, essential contrast and drawer/calendar accessibility while retaining the Hom'As colors.
4. **Rendered acceptance:** Inspect real authenticated pages at 320/375/390px, tablet, laptop and desktop; keyboard-only use; long records; dropdown clipping; print views; empty/error/loading states; upload/review flows. Connect each visual finding to an observed screen before implementing appearance changes.
5. **Final regression:** Update obsolete tests, run the relevant suites against PostgreSQL, and rerun the actual journeys. Do not declare completion from lint or fixture geometry alone.

## Remaining boundaries

No application repair, deployment, production-data mutation, account change or backup/restore was performed. The browser connection remains a blocker for the visual portion. This report is a reproducible functional/source audit, not an exhaustive claim that every interaction or screen has been verified.
