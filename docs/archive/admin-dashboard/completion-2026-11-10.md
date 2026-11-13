# Admin workflow completion — 10 November 2026

Subsequent refinement: [Hom’As identity, action icons and workflow improvements](identity-and-actions-2026-11-10.md).

The changes in this record are implemented in the workspace and delivered to
the running local Docker website. This supersedes the remaining-item status in
the [9 November delivery record](redesign-2026-11-09/README.md).

## Working experience

- **Today** proposes an actual next task from deadlines and review queues, with
  a direct action. The surrounding order, payment, request, production and
  stock lists remain available for choosing other work.
- **Products** start as named private drafts. Photos, options, recipe/pricing,
  languages and publication have clear sections. Website-address and selling
  rules are secondary controls. The calculated price appears with costing work.
- **Orders and custom work** retain the guided entry, explicit review,
  private session drafts, connected record sections, production blockers,
  inline payment recording and context-preserving return paths from the first
  implementation. Payment validation now links the summary to the exact field
  and preserves the entered amount.
- **Customers** have separate contact and delivery-address sections, secondary
  email editing, additional-address and removal controls using the existing
  one-to-three-address rules. Saving returns to the same customer. Older order
  contact/address snapshots remain unchanged.
- **Account history** shows who changed a profile or access, when it happened
  and safe before/after values. Older pages are accessible. Credential values
  are excluded. History writes and profile changes commit or roll back together.
- **Recovery** retains explicit saved order/request drafts, safe failed input,
  linked field errors, stale-edit checks, and warnings before leaving edits.
  Drafts remain private to the signed-in session and are not permanent records.
- **Responsive/keyboard use** includes local navigation icons, focus isolation
  in the mobile drawer, keyboard record tabs, focused form steps and contained
  scrolling for dense information.

## Previously unfinished items closed

| Finding | Implementation and evidence |
| --- | --- |
| F14: concurrent profile edits | Submitted profile versions protect administrator and customer self-service forms; failed entries remain available. A stale customer edit is rejected after another profile change. |
| F15: account actor/change history | Migration 031, transactional safe snapshots, actor references, paged history, and update/delete protection. Includes profiles, access changes, administrator-created accounts and permitted deletion. An injected history failure rolls back its profile update. |
| F16: PostgreSQL backup/restore | Real `pg_dump` custom archives, checksums and table digests from an exported snapshot; restore checks target only generated disposable databases. The actual pre-update archive restored all 40 tables; the updated archive restored all 41 tables with matching row hashes. |
| F17: misleading backup availability | Settings requires a matching successful restore receipt and displays its verification time. A modified archive loses verified status. The real web-server user can read the current receipt. |
| F18: inconsistent business dates | Explicit `Africa/Tunis` calendar dates with UTC timestamp storage. Quotas, reports, booking windows, promotion validity and local follow-up/payment inputs use the appropriate boundaries. PHP and database checks cover Tunisia midnight across a year change. |
| F19: timeout copy | Sign-in displays the effective inactivity duration. README now matches the configured fallback and environment override. |
| V01: missing seed/setup and runtime mismatch | Canonical `reference.sql`, PostgreSQL preflight, ICU bootstrap, PostgreSQL test helper, current configuration example, required PHP extensions/client tools in Docker, and tested clean installation. |

Existing timestamps and business records were preserved. Account history starts
at deployment; earlier changes have not been fabricated. The prior deletion of
`app/database/seed.sql` remains intact; the supported reference file is now
`app/database/reference.sql`.

## Acceptance evidence

The 12 selected integration suites listed in the previous delivery record all
completed against the current PostgreSQL source: **697 assertions**. The new
completion, clean-installation and backup/restore suites add **29 assertions**,
for **726 selected integration assertions**. These now use the repository's
reference file without a temporary replacement seed.

The final real-Chrome run completed **93 assertions**, including:

- Named product creation and the connected product sections.
- Saving and resuming an incomplete order, choosing options, reviewing the
  authoritative total and creating the order.
- A rejected payment retaining its amount with an accessible field-error link.
- Editing a customer, returning to that customer, viewing the recorded actor,
  and saving additional address/secondary-email details.
- Request progress, production blockers, native quick search, stock previews,
  unsaved-edit warnings and keyboard tabs.
- All tabs/form steps across **16 screens** at 1024, 390 and 320px, plus Today
  at desktop and 768px and mobile-drawer focus checks. No page overflow or
  unhandled JavaScript exceptions occurred in these checks.

These checks use synthetic accounts and disposable schemas/databases. No shop
account is used as a test fixture. They establish the recorded behavior and
coverage, rather than a claim that every historical test or possible input has
been exhaustively certified.

Commands for the new checks:

```powershell
docker exec homas-test php tests/Integration/verify-admin-completion.php
docker exec homas-test php tests/Integration/verify-clean-installation.php
docker exec homas-test php tests/Integration/verify-backup-restore.php
```

The browser fixture is now reproducible through
`tests/Browser/prepare-admin-preview.php`; it must run in a separate preview
container. Prepare a fresh fixture for a full run, expose that container only
on `127.0.0.1:8787`, set its `PGOPTIONS` to the generated schema, and pass its
private state file to `HOMAS_PREVIEW_STATE`. Cleanup mode removes only its
validated disposable schema. The preview used for this acceptance run was
removed after verification.

## Delivery and recovery evidence

- All 61 changed/new runtime/test/setup files passed syntax checks and were
  compared against the expected current files before delivery. Delivered hashes
  match the tested candidate. Other workspace changes were preserved.
- Migrations 030 and 031 were applied after a verified pre-update backup. A full
  migration run subsequently skipped all 31 versions with matching checksums.
- Read-only fingerprints of 15 existing business tables match before and after
  delivery and the verification runs. Existing orders, payments, products,
  stock, requests and accounts were not rewritten by this work.
- Current runtime preflight passes. The served sign-in and storefront return
  HTTP 200; anonymous admin access redirects to sign-in.
- A current private database backup and verified restore receipt are retained
  in `app/storage/backups` inside `homas-test`. The post-update archive is
  `homas_candles_20261110_092221_7a409d72.dump`.
- Runtime source rollback copies are retained at
  `/tmp/homas-admin-final-runtime-backup-20261110` inside `homas-test`.
  Additional pre-form-recovery versions are retained beneath that directory.
- The host backup/stage/evidence directory is
  `%TEMP%\homas-admin-final-20261109`. It contains private recovery material and
  is outside the public web root.
- The reproducible final image is tagged
  `homas-candles:admin-final-20261110`. Its private configuration is provided
  separately. Existing runtime data is preserved when recreating containers.

Use the [current Docker operations guide](../../local-docker.md) for setup,
backup verification and recovery into a separate database. A database archive
does not replace backups of uploaded payment evidence, product images and
private configuration. Routine off-computer backup scheduling and hosted
deployment are operating tasks, not actions performed by this local update.

## Screenshots

All records shown here are synthetic:

- [Today](completion-2026-11-10/today-desktop.png)
- [Today at 320px](completion-2026-11-10/today-320.png)
- [Order review](completion-2026-11-10/order-review.png)
- [Product basics](completion-2026-11-10/product-basics.png)
- [Account editing and history](completion-2026-11-10/account-history.png)
