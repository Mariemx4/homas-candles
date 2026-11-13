# Repair batch 2 — data entry and workflow recovery

Scope: audit F06–F11, plus the status Save label (U06), cancellation explanation
(part of U07) and request detail heading order (part of U01).

## Delivered behavior

- Admin pages have independent, bounded form tokens. Saving or rejecting one
  form does not expire another page. A write consumes its submitted token, so
  replaying stock, payment, creation or conversion forms cannot repeat the write.
  Pricing previews validate without consuming the editable form. Signing out,
  changing administrator identity or reaching inactivity expiry invalidates the
  admin token pool. Customer/public forms retain their existing token strategy.
- Custom-request creation and correction transport product definitions as one
  JSON field, preserving the existing limit of 50 products with 50 components
  each. A 2 MiB bound is enforced before submission and on the server. A trailing
  completion marker rejects truncated native/no-JavaScript forms; malformed or
  ambiguous payloads cannot save a partial request. At the row limits, Add
  buttons become disabled. Long briefs require JavaScript to avoid PHP's nested
  variable limit; small native forms continue to work.
- Conversion displays server-calculated product subtotal, delivery, customer
  total and required deposit. The same rules produce the stored order. Changed
  inputs invalidate the reviewed total; late previews cannot restore an older
  quote. Review totals also works without JavaScript. Creation checks the
  reviewed total again and preserves the existing atomic conversion lock.
- Shared fulfilment behavior now works with or without the customer picker.
  Pickup excludes address fields and charges zero; delivery requires its address
  and defaults to 9.000 TND. Switching methods retains the entered address and
  delivery fee. Server validation remains authoritative.
- Admin cancellation is available for Requested/Confirmed orders only if
  production has never started, including when a displayed status was corrected
  backwards. Cancellation is terminal. It records the actor, leaves stock and
  payment history intact, and explains separate refund review. The shared
  confirmation dialog explains these consequences; Save has visible text.
- Requests with separate product definitions offer **Correct working brief**
  while Submitted, Contacted or Discussing. The same definition validator is
  used for creation and correction. A reason is required. Stale submissions
  cannot overwrite a newer brief; failed inputs remain available for review.
  Corrections and their before/after history commit or roll back together.
- Agreement, conversion, archive and closure lock brief correction. Website
  enquiries without separate product definitions retain Contact history for
  clarifications and the final agreement fields during conversion. This batch
  does not turn customer-submitted messages into editable product definitions.
- Request details put their heading and feedback before product content and
  expose revision history with actor, date, reason and prior specifications.
  History is paginated so large older briefs remain accessible without loading
  every full snapshot into one page.

## Migration and local delivery

Migration `029_custom_request_brief_revisions.sql` adds a revision table and
restrictive references to requests and administrators. It changes no existing
business records. Existing lifecycle dependency discovery includes the new
history automatically.

The tested changes are synchronized into the local `homas-test` app, which uses
a built source copy rather than a workspace bind mount. Workspace files remain
the source for rebuilds. Refresh existing admin pages once to load the new form
tokens and scripts.

## Verification

`verify-admin-workflow-recovery.php` builds the current PostgreSQL schema using
minimal synthetic fixtures, starts its own authenticated HTTP server with
`max_input_vars=1000`, and removes its schema and sessions in `finally`. It
does not require the missing reference seed.

| Check | Result |
| --- | --- |
| New workflow recovery | 39 assertions passed in the delivered app |
| First-batch reliability | 65 assertions passed in the delivered app |
| Existing sign-in/out | 12 assertions passed in the isolated copy |
| Existing payments | 46 assertions passed in the isolated copy |
| Existing lifecycle | 95 assertions passed in the isolated copy |
| Customer-mode/fulfilment and status script checks | Passed |
| New conversion/transport/confirmation browser fixture | Passed in Chromium |
| Existing manual selector browser fixture | Passed in Chromium |
| Existing confirmation browser fixture | Passed at 1440, 768, 390 and 320px |
| Changed PHP syntax | 24 files passed |

The runtime matches all 31 delivered source/test files by SHA-256. Migration 029
is recorded, and the real revision table remains empty. No real customer,
administrator, order, payment or stock record was changed. Test output is saved
in [repair-batch-2-checks.txt](repair-batch-2-checks.txt). Older test suites marked
incomplete below remain incomplete; this table does not claim a fully green
repository suite.

The new check covers independent tabs, invalid/replayed stock tokens, Unicode
brief loading, original/corrected revision snapshots, stale/expired forms,
rollback on failed history persistence, state locks, all 2,500 components in a
maximum-size HTTP submission, malformed/oversize/incomplete payloads, exact
conversion totals, native review, delivery validation, deposit boundaries,
duplicate conversion, cancellation/payment/stock/history behavior and sign-out
token invalidation.

Browser fixtures use the actual PHP form markup and shared scripts with
synthetic quote responses. They verify method switching, late/error previews,
real browser FormData serialization and cancellation confirmation. They do not
claim visual acceptance of the authenticated live site.

Commands:

```powershell
docker exec homas-test php tests/Integration/verify-admin-workflow-recovery.php
docker exec homas-test php tests/Integration/verify-admin-reliability.php
node tests/Unit/verify-order-customer-form.js
node tests/Unit/verify-order-status-control.js
node tests/Unit/verify-request-conversion-browser.js
```

Existing sign-in/out, payment and lifecycle suites also passed in the isolated
copy. Their full-schema helpers were supplied the Git reference seed only in
that temporary copy. The workspace seed deletion is preserved.

The older manual-order/customer suite still stops at its previously documented
customer-profile text assertion, after its admin request, conversion, production,
draft, profile-save and lifecycle journeys pass. Its HTTP fixtures now include
the completion marker and reviewed conversion total. The custom-request
controller fixture also includes the required reviewed total; its previously
documented strict PostgreSQL-ID comparison remains outside this batch. Neither
incomplete suite is counted as a fully passing regression suite.

Backup/setup modernization, business timezone, timeout copy, account concurrency
and actor audit, remaining shared navigation/design work, unsaved-change
protection and full rendered live-site acceptance remain in the audit backlog.
