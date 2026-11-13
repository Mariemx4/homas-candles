# Payments implementation report

## 1. Files changed

Application wiring and configuration:

- `app/Bootstrap/admin/admin-payments.php`
- `app/Bootstrap/customer-payments.php` (new)
- `app/config/payments.php` (new)
- `app/database/migrations/022_payment_receipt_audit.sql` (new)

Existing services, controller and repositories:

- `src/Controllers/PaymentController.php`
- `src/Services/PaymentService.php`
- `src/Services/PaymentEvidenceStorage.php`
- `src/Repositories/PaymentRepository.php`
- `src/Services/OrderService.php`
- `src/Repositories/OrderRepository.php`
- `src/Services/PublicMetadata.php`

Routes, views and styling:

- `public/order-payment.php` (new customer payment/evidence route)
- `public/order-confirmation.php`
- `public/admin/payments/evidence.php`
- `app/Views/Pages/payment/customer-payment.php` (new)
- `app/Views/Pages/admin/payments/index.php`
- `app/Views/Pages/admin/payments/order.php`
- `app/Views/Pages/admin/payments/review.php`
- `app/Views/Pages/admin/orders/detail.php`
- `app/Views/Pages/customer-order.php`
- `app/Views/Layouts/header.php`
- `public/assets/css/admin/pages/payments.css`
- `public/assets/css/pages/account.css`
- `public/assets/js/admin/payment-record.js` (new)

Verification and documentation:

- `tests/Integration/verify-payments-workflow.php`
- `tests/Integration/verify-customer-payments.php` (new)
- `tests/Support/payment-upload-endpoint.php` (new, capability-protected test router outside public)
- `tests/Unit/verify-payment-evidence-upload.js` (new)
- `docs/admin-dashboard/05-payments/README.md`
- This report.

Earlier uncommitted workspace changes, including Orders improvements, were retained. This list describes payment-task changes rather than the entire diff from Git HEAD.

The existing admin index, order-payment, review and evidence routes are reused. A customer payment route was necessary because no existing route accepted customer proof or displayed configured D17 instructions. It uses the same PaymentController, PaymentService, PaymentRepository and PaymentEvidenceStorage, rather than a second payment system. The protected customer bootstrap wires those same classes. No new payment statuses or gateway integration were introduced.

## 2. Existing payment rules reused

The schema still uses `D17` and `CASH_ON_DELIVERY`; the latter is labelled Pay on delivery / pickup in the UI. Types remain DEPOSIT, BALANCE and FULL_PAYMENT. Individual payment statuses remain PENDING_VERIFICATION, VERIFIED and REJECTED. Unpaid, partially paid, paid and credit/refund due are derived order-summary labels, not duplicate database workflow statuses.

The existing verified-only payment totals, three-decimal TND arithmetic, required-deposit snapshots, remaining-capacity checks and no-overpayment verification rule are retained. Pending payments reserve recording capacity without reducing the verified remaining balance. Existing deposit requirements before order confirmation are unchanged. No refund transactions were added.

Payment summaries are shared between Payments and Orders. The admin queue has real pending-D17 and verified-record counts, with filter shortcuts. Filters still accept order/customer, status, method and recorded-date ranges; Reset filters clears them. The table offers order payments, View order, Review and protected View proof links without embedding proof images. Pending records show the current amount due for their payment type alongside the submitted amount. This is current order context, not a fabricated historical expected-amount snapshot.

Database testing exposed an existing PostgreSQL compatibility issue: LIKE cannot operate on the project's nondeterministic collation on the installed PostgreSQL version. Payment search now uses lowercased expressions with deterministic C collation and retains escaped literal wildcard handling.

## 3. D17 instructions stored and displayed

`app/config/payments.php` reads protected environment configuration or the ignored `app/config/payments.local.php`. Supported keys are account_holder, number and optional account_information. Required holder and an eight-digit number determine whether D17 is available. No personal recipient values were inserted into templates or committed configuration.

The customer chooses instructions on `order-payment.php`, reached from the saved order confirmation or account order page. The D17 card displays configured recipient details, order number, and the authoritative next deposit/balance amount. It explains opening D17, transferring, capturing proof, uploading and submitting for manual review. Missing configuration disables submission and directs customers to HOM’AS. The page is private, uncached and excluded from indexing.

Choosing Pay on delivery / pickup displays handover/collection instructions. Orders that require a deposit still explain that it must be arranged before confirmation. Choosing instructions alone never records payment or claims a transfer succeeded.

## 4. Evidence upload and storage

Customer proof is mandatory for D17 submission. The existing storage service accepts JPEG, PNG and WEBP; existing PDF support was explicitly retained. Uploads require a genuine PHP HTTP upload, valid server-detected MIME, valid image metadata for images, and both reported and actual size within 5 MB. Client MIME, name, extension and amount are not trusted.

Storage retains random 40-hex-character filenames with extensions derived from content under `app/storage/uploads/payments`, outside the public root. Failed persistence discards newly stored files. Protected admin evidence access remains authenticated. Customer evidence is streamed through the payment route only after explicit account or server-side checkout-session authorization, and the proof's order must match the authorized order. Responses use private/no-store caching, nosniff and a sandbox content policy.

Customer ownership is never inferred from names, email or phone. Trashed orders are excluded from customer access/new payments; historical administrator payment records and review remain available under the existing financial workflow.

## 5. Administrator verification

Pending D17 records open the existing review route. Review shows customer, current expected amount, recorded amount, type, status, submission time and evidence access. Approve retains the existing VERIFIED value, administrator and verification timestamp. Reject requires the existing nonempty reason of at most 500 characters, retains administrator identity, and now stores a dedicated rejected_at timestamp.

Conditional updates and locked pending-status checks still prevent second decisions. Recording and reviewing now acquire the order before payment locks consistently, reducing cross-operation deadlock risk. Actual review actions remain CSRF-protected POSTs with redirects and notices. Invalid or stale actions fail without changing payment state.

Rejected proof remains a historical record. Customers may upload new/corrected proof as a new pending payment once there are no pending transfers and an amount remains due. The previous rejection is retained.

## 6. Pay-on-delivery/pickup confirmation

The existing administrator order-payment page defaults to Pay on delivery / pickup, displays the selected method, remaining balance and available recording capacity, and suggests the available amount. The administrator can enter the amount actually received and must tick an explicit receipt confirmation before Mark payment as received. Server validation requires that confirmation even if JavaScript is disabled or method casing/whitespace changes.

The existing payment service validates a positive supported amount, locks the authoritative order/payments and prevents exceeding verified-plus-pending capacity. The receipt is VERIFIED with amount, method, order, administrator and timestamp. A server-derived submission key, unique in the database, prevents repeated partial-receipt requests from creating duplicates. D17 recording uses the existing pending workflow rather than this receipt confirmation. The UI hides irrelevant fields without relying on JavaScript for authorization or amount checks.

## 7. Payment versus fulfilment

No fulfilment or order-status mutation was added to the payment flow. The existing order-status service was not changed. Changing an order to PICKED_UP or DELIVERED creates no payment record. A completed unpaid or partially paid order remains valid until an administrator independently records payment. HTTP and database tests verify this explicitly.

## 8. Remaining balance

Remaining balance is `max(0, stored total - sum of VERIFIED amounts)` using integer thousandths. Pending and rejected amounts display separately. Recording capacity is `max(0, total - verified - pending)`; it prevents reserving the same outstanding amount twice. Overpayments caused by an existing order edit display as credit/refund due without issuing a refund.

For customer D17, the next amount is the unpaid part of the stored required deposit, capped at remaining balance; once the deposit is verified it becomes the remaining order balance. An existing pending payment blocks another customer proof submission until review. The service derives amount and type and rechecks authorization/current amounts under the order lock; hidden browser fields cannot choose the payment amount or turn it into cash.

The original order detail now includes pending/rejected amounts, derived payment state and recorded methods alongside its existing totals, deposit, verified amount, balance and credit. The order-payment page displays those fields as well.

## 9. Database migration

`022_payment_receipt_audit.sql` adds nullable rejected_at and request_key, a unique request-key constraint, and a constraint that rejection timestamps belong only to rejected payments. Existing rows keep unknown rejection times as null; no historical timestamps are invented. No existing migration was edited, and no order/payment statuses or amounts were changed.

The new migration was applied and tested only in disposable schemas. Before using this update in an application database, apply pending migrations through the existing runner: `php scripts/migrate.php`. Review any other pending workspace migrations as part of the project's usual upgrade process.

## 10. Verification and manual checks

Passed automated suites:

- PHP lint on changed PHP files and Node syntax check for receipt UI code.
- `tests/Integration/verify-payments-workflow.php`: isolated PostgreSQL schema, all payment-rule, audit, confirmation, replay, ownership and rejection-time checks passed.
- `tests/Integration/verify-customer-payments.php`: real HTTP customer upload, amount/method tampering, private proof access, administrator route guards, repeated POST, rejection/resubmission, approval, cash confirmation and extended order-detail checks passed.
- `tests/Unit/verify-payment-evidence-upload.js`: real multipart upload tests passed for content-derived names, independent random filenames, spoofed content, executable/SVG rejection, oversized-file rejection and retained PDF support.
- `tests/Unit/verify-orders-workspace.php`: existing Orders workspace checks passed after summary reuse.

Database and HTTP checks ran in a disposable container on the existing Docker network, using temporary PostgreSQL schemas, accounts and session directories. The checks remove their schemas and proof fixtures. The live application schema was not upgraded or populated with test payments. Visual checks in a full browser remain manual.

1. Apply pending migrations in the application environment and configure protected D17 holder/number. Open Payments as an active administrator; signed-out/inactive sessions must be denied.
2. Check queue counts and every filter/reset/date range. Open View order, Order payments, Review and View proof; no image should appear inside the records table. Test desktop/mobile overflow and keyboard focus.
3. Place a shop order, then follow Choose payment method from its confirmation. Open the same payment page from the owning account. Verify a different customer/session cannot access its details or proof just by changing IDs.
4. Choose Pay on delivery / pickup and confirm the correct instructions and existing deposit requirement. Choosing it must not create a payment or mark the order paid.
5. Choose D17. Compare holder, number, optional information, order number and next amount with configuration/order data. Remove the required configuration in a test environment and verify submission is unavailable.
6. Upload a valid image and submit. Verify PRG notice, PENDING_VERIFICATION, zero newly verified amount, and private proof access. Try fake MIME, unsupported formats, oversized files, invalid CSRF and a repeated submission; none should create a second payment.
7. Review pending proof as an administrator. Reject with a reason; check reviewer, rejection timestamp/reason, and unchanged remaining balance. Submit corrected proof, approve once, then repeat the decision; only one approval should take effect.
8. Verify a deposit first and check that the customer next amount becomes the remaining balance. Pending/rejected payments must never lower verified balance. Check credit/refund due after an existing fee-reducing edit.
9. Set a disposable order to delivered/picked up while unpaid or partially paid. Its payment state must remain unchanged. Open its order-payment page, enter the actual receipt amount, leave confirmation unchecked and submit: server must refuse. Tick confirmation and submit: one audited VERIFIED receipt should be created.
10. Refresh/repeat receipts and try competing review/record requests; history must contain one receipt per submission and one review per payment. Check amount precision, reviewer/time, payment summary, Trash restrictions and retained history.
