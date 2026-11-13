# Admin workflow redesign — delivered 9 November 2026

**Historical record:** the [10 November completion record](../completion-2026-11-10.md)
supersedes the remaining-item status below and includes the subsequent workflow,
account-history, setup, backup and timezone fixes.

Implemented in the workspace and applied to the running local `homas-test`
Docker container. This record supersedes the implementation status in the
[workflow proposal](../workflow-redesign-proposal-2026-11-09.md).

## What changed

- **Shared workspace:** grouped navigation, recognizable local SVG icons, a
  global New menu, a warm sage/caramel/ink theme, deliberate system fonts,
  clearer form boundaries, readable working text and consistent actions.
  Stylesheets have individual content versions so edited child CSS refreshes.
- **Today:** connected order deadlines, pending transfers, open requests,
  production readiness and supplies. Each attention item opens a useful task.
  Archived requests are excluded; failed data loads remain unavailable rather
  than looking like healthy zero counts.
- **Orders:** Overview, Products, Production, Payments and History sections;
  a named next action with deposit/recipe/stock blockers; secondary correction
  controls; payment recording inside the order; D17 review returns to that
  order's Payments section. Existing financial, fulfilment and stock rules
  remain authoritative.
- **Order entry:** Customer → Products → Pickup & delivery → Review & save.
  The last step shows the customer, products/options, date and calculated total.
  Discounts are optional expandable controls. Native form submission and
  server-side pricing still work.
- **Custom work:** visible business progress and a current task; focused Brief,
  Conversation and History sections; guided request entry and order conversion.
  Existing brief correction, quote review, cancellation and history protections
  from the previous repair batch are retained.
- **Products:** start with a name and category, save a private draft, then open
  Photos. Basics, Photos, Options, Pricing, Languages and Publish organize the
  editor. Drafts can be renamed before a description is complete. Category
  controls show relevant fields; publication requirements link to their fixes.
  Publish includes a product summary and the real publication control. Reusable
  recipe/pricing tools remain in the product workspace.
- **Production:** queue readiness identifies missing saved recipes and material
  or accessory shortages. Date shortcuts, a Needs a recipe view and preparation
  sheet access help daily planning. Checks are per order against current stock;
  they do not reserve stock across the queue. Transitions recheck actual rules.
- **Stock:** Receive stock, Correct a count and Record waste actions, with exact
  three-decimal before/after previews and required correction reasons. Blocker
  routes retain a return to the original order's Production section.
- **Customers:** heading and actions first, retained directory search, return to
  the same customer after saving, and stale-edit checks in administrator account
  forms. Failed entries stay available for review.
- **Work recovery:** explicit private session drafts for unfinished orders and
  requests, saved/resume/discard controls, linked error summaries, field errors,
  hidden-section error reveal and unsaved-change warnings. Browser storage holds
  panel identifiers only. Drafts expire after three hours and are cleared when
  authentication ends; they are not permanent cross-session records.
- **Accessibility and small screens:** inert closed mobile navigation, isolated
  focus in its open drawer, keyboard record tabs, labelled controls, reduced
  motion, semantic calendar day links and scrollable dense tables. All sections
  remain visible without JavaScript and in print.
- **Settings:** plain operating-rule explanations, matching effective inactivity
  copy at sign-in, and an honest warning about the unverified legacy backup.

## Verification

The exact delivered source passed the new 55-check authenticated redesign suite
and the existing 65-check reliability and 39-check workflow-recovery suites in
the real Docker runtime. These three checks create and remove their own
PostgreSQL schemas and do not need the missing application seed:

```powershell
docker exec homas-test php tests/Integration/verify-admin-redesign.php
docker exec homas-test php tests/Integration/verify-admin-reliability.php
docker exec homas-test php tests/Integration/verify-admin-workflow-recovery.php
```

The tested candidate also completed these selected integration regressions:

| Suite | Passed assertions |
| --- | ---: |
| Admin redesign | 55 |
| Admin reliability | 65 |
| Workflow recovery | 39 |
| Catalogue | 43 |
| Customer payment upload/review | 20 |
| Business lifecycle | 95 |
| HTTP login/logout | 12 |
| Payment workflow | 46 |
| Pricing and recipe snapshots | 129 |
| Customer order management | 50 |
| Order editing | 44 |
| Manual orders/customer/custom-request HTTP journeys | 99 |
| **Total** | **697** |

Full-schema legacy checks above used the reference-only seed from Git HEAD in
the **temporary candidate copy only**. The pre-existing workspace deletion of
`app/database/seed.sql` was preserved. These selected passing checks do not imply
that every older suite in the repository is green.

`tests/Browser/verify-admin-redesign.js` completed **80 assertions in real
headless Chrome against a separate authenticated preview container**, including:

- A new named product draft, Basics/Photos/Publish and publication requirements.
- Saving an incomplete order, resuming it, selecting configured options,
  reviewing the real server price, and creating its connected order workspace.
- Inline payments, request tasks, production blockers, native quick search,
  keyboard tabs, stock preview/reason/return context, and the actual browser
  warning before leaving edited work.
- All tabs and guided steps across 15 principal screens at 1024, 390 and 320px.
  Today additionally passed at 1440 and 768px; mobile drawer focus was checked.
  Page overflow checks account for the browser's vertical scrollbar.
- No unhandled JavaScript exceptions during these journeys.

The browser check requires an isolated preview at `127.0.0.1:8787`, synthetic
records and `HOMAS_PREVIEW_STATE` pointing to its private fixture state. It
intentionally does not sign into a shop account. Its fixture state has `schema`,
`email`, `password`, `orders` and `request` keys; `schema` must be a disposable
`homas_auth_check_<16 hex characters>` schema. Browser credentials are not saved
in this repository. The temporary preview and schema were removed after use.

Five existing Chrome fixture suites also passed: shared layout, searchable
manual selectors, manual pricing, request conversion and confirmations. Focused
PHP checks passed for order presentation, CSV/report safety, deadline labels,
pricing, money, product error responses and the error boundary. Changed PHP/JS
files passed syntax checks. Obsolete fixed-height/color and profile-copy
assertions were updated to the current design and actual form/data contracts.

## Rendered evidence

These screenshots show synthetic records, not shop data:

- [Today, desktop](today-desktop.png)
- [Today, 320px](today-320.png)
- [Order review](order-review.png)
- [Product publication](product-publish.png)
- [Stock preview](stock-preview.png)

## Local delivery and recovery

- All 60 changed/new files were compared with the pre-change runtime hashes
  before delivery. All matched the expected originals. Delivered hashes then
  matched the tested candidate.
- Previous runtime files and their manifest remain in
  `/tmp/homas-admin-redesign-runtime-backup-20261109` inside `homas-test`.
- The host's pre-redesign source backup, staged source, manifest and additional
  captures remain under `%TEMP%\homas-admin-redesign-20261109`.
- Read-only hashes of 15 business tables matched before and after delivery and
  the final regressions. No shop order, payment, product, stock, account or
  request was changed by this delivery. No migration was added for this redesign.
- The served sign-in and storefront returned HTTP 200. Anonymous admin access
  still redirects to sign-in. Apache was reloaded after delivery.
- The workspace contains the changes for future builds. The running container
  was updated directly; the existing Docker image itself was not rebuilt.

## Remaining project boundaries

This completes the admin workflow/design implementation described above. It
does not certify every legacy feature, a clean installation, production hosting
or an error-free project. Owner usability acceptance and exhaustive print/upload
permutations are not claimed from automated checks.

Earlier audit infrastructure gaps remain separate: the PostgreSQL backup and
restore replacement (F16), the missing reference seed/setup path (V01), consistent
Tunisia business-day handling (F18), and durable account-change actor history
(F15). Settings now exposes the backup limitation accurately (F17). Administrator
profile forms detect stale edits (F14); customer self-service has not been given
the same submitted-version contract. The historical audit and its other test
maintenance notes remain available for those areas.
