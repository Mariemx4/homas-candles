# Repair batch 1 — functional reliability

Approved scope: the eight broken admin searches, website enquiry intake,
administrator secondary-email saving, failed-form recovery, and misleading
dashboard/inbox failure states.

## Changes

- Products, Categories, Product Types, Colors, Scents, Materials, Requests and
  Production use deterministic PostgreSQL search expressions with case folding.
  User `%`, `_` and `=` characters remain literal. No schema changes were needed.
- Website enquiry rate windows use the database clock with PostgreSQL interval
  arithmetic. The SQLite expression retains the existing isolated contact tests;
  no MySQL time-function emulation is needed.
- Administrator secondary email is persisted, validated, and retained when an
  older form omits the optional field.
- Stock adjustments retain type, quantity and safely escaped notes after errors.
  Their field errors are associated with the affected controls.
- Request assignment, contact and status forms recover their own submitted
  values after validation, expired-token and persistence failures. Unavailable
  choices stay explicit; values are not copied into another action's form.
- A failed dashboard returns private HTTP 503, displays unavailable metrics,
  offers retry, and omits misleading empty-success sections.
- A failed request inbox returns private HTTP 503 and preserves safe filters,
  including selected category/administrator placeholders. It offers retry and
  does not claim zero matching records. Long Unicode input is shortened safely.

## Validation

The new `tests/Integration/verify-admin-reliability.php` check uses PostgreSQL,
current migrations, synthetic records and a separate authenticated HTTP server.
It has its own minimal reference fixtures and does not need the missing seed.

The unfixed container code failed the new check with SQLSTATE `0A000`. The final
repaired test copy passed **65 assertions**, including:

- Case-insensitive, French/Arabic and literal-wildcard searches in every affected
  area, checking both counts and rows, plus the authenticated search pages.
- A real website contact POST after the actual minimum-fill delay, appearing in
  the authenticated inbox; per-phone limiting remains enforced.
- Secondary-email save/reload, omitted-field preservation and invalid-value
  rejection.
- Failed and expired stock/contact/status forms, stock/ledger rollback,
  output escaping, an unavailable assignment and a database write failure.
- Forced inbox/dashboard database failures, correct 503/private responses,
  filter retention, Unicode handling, truthful counts and recovery.
- No PHP warnings or fatal errors during the HTTP journeys.

Existing checks also passed in the temporary copy:

| Check | Result |
| --- | --- |
| `verify-admin-dashboard.php` | 24 assertions passed |
| `verify-catalogue-workflow.php` | 43 assertions passed |
| `verify-admin-lifecycle.php` | 95 assertions passed |
| `verify-payments-workflow.php` | 46 assertions passed |
| `verify-contact.php` | Passed; SQLite fixture aligned with the existing active-account lookup |

The four full-schema checks above were supplied the reference seed **only in the
temporary test copy**. The pre-existing workspace seed deletion was preserved.

Older checks still do not complete:

- Custom-request workflow: strict comparison between a PostgreSQL numeric ID
  returned as a string and an integer fixture ID.
- Production workflow: 13 checks pass, including queue searches/filters; its
  older aggregation fixture/expectation and restrictive-FK cleanup fail.
- Inventory workflow: its fixture attempts to change locked identity fields and
  expects success. The current service correctly rejects that operation.

These are reported as failed checks, not counted as passing suites. Their
remaining assertions are not verified by this batch.

## Local delivery and remaining scope

The repaired PHP files and new regression test are synchronized into the running
local `homas-test` container after verification. Workspace files remain the
durable source for a future rebuild. The final container check output is saved
in [repair-batch-1-checks.txt](repair-batch-1-checks.txt).

Run the scoped check from the workspace:

```powershell
docker exec homas-test php tests/Integration/verify-admin-reliability.php
```

No shop accounts, orders, payments or stock records were changed. Tests use and
remove their own schemas. No database migration, credential change, public
deployment or visual/browser automation was performed.

Cancellation, conversion/brief editing, broader navigation/design changes,
backup/setup modernization, global token strategy and remaining audit findings
are outside this approved batch. The original audit is a historical baseline;
F01–F05 and F12–F13 are the findings addressed here.
