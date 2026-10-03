# Module 05: Payments

## Objective

Provide secure payment recording and D17 verification with an immutable administrator audit trail.

HOM’AS uses manual D17 transfers and payment on delivery/pickup. There is no automatic gateway. Payment and fulfilment are independent: a delivered or picked-up order can still be unpaid. The existing deposit requirement before order confirmation remains in force.

## Configuration and upgrade

Apply migration `022_payment_receipt_audit.sql` with the existing migration runner before using the updated module. It adds rejection timestamps and receipt-submission replay protection without adding statuses or changing historical payment amounts.

Configure `HOMAS_D17_ACCOUNT_HOLDER`, `HOMAS_D17_NUMBER` (eight digits), and optional `HOMAS_D17_ACCOUNT_INFORMATION`, or use the ignored protected `app/config/payments.local.php` file with keys `account_holder`, `number`, and `account_information`. Never store passwords, PINs or authentication secrets in the payment information. Without holder/number configuration, customer D17 submissions are disabled and the page asks the customer to contact HOM’AS.

Order confirmation and account order pages link to `order-payment.php`. Customers choose payment instructions there, after the authoritative order has been saved. Account access uses the explicit customer ID; guest access reuses the checkout session’s placed-order capability. This choice itself records no receipt and creates no payment preference/status record. Only actual receipt recording or proof submission creates a payment record.

See [implementation report and manual checks](implementation-report.md).

## Verification

- `php tests/Integration/verify-payments-workflow.php`: disposable PostgreSQL schema; amounts, audit, cash confirmation, replay, customer scope and review rules.
- `php tests/Integration/verify-customer-payments.php`: real HTTP customer upload, protected evidence access, administrator authentication, review, resubmission and cash confirmation in a disposable schema.
- `node tests/Unit/verify-payment-evidence-upload.js`: genuine multipart uploads against the existing storage service, including MIME spoofing, invalid formats and size limits. Requires Node with native Fetch and PHP with Fileinfo.
- `php tests/Unit/verify-orders-workspace.php`: Orders listing regression checks.

## Build

- Paginated payment queue with status/method/date filters and links to associated orders.
- Order payment summary showing required deposit, verified amount, pending amount, rejected amount, and remaining balance.
- Record supported payment methods according to the existing schema.
- Verify or reject pending D17 payments, recording the reviewing administrator and timestamp; require a safe rejection reason if supported by the model.
- Secure payment-evidence access only when evidence exists and the current schema supports it.
- Prevent overpayment or invalid payment/status combinations according to documented business rules.

## Acceptance criteria

1. Payment pages require an active administrator; all mutations use POST, CSRF, strict validation, and Post/Redirect/Get.
2. Only pending D17 payments can be verified or rejected, and every decision retains the responsible administrator.
3. A payment cannot be reviewed twice through repeated or concurrent requests.
4. Amounts use authoritative database totals and consistent three-decimal TND rounding.
5. Invalid, negative, zero, unsupported, or excessive amounts are rejected server-side as applicable.
6. Payment evidence is never exposed by a public predictable path; access is authorized and served with safe content handling.
7. Lists do not expose evidence, unnecessary customer data, credentials, or internal storage paths.
8. Payment changes and related order effects commit atomically where both are required.
9. Empty, not-found, already-reviewed, and database-failure states are safe and understandable.
10. Tests cover authorization, CSRF, method/status rules, amount boundaries, double review, administrator audit identity, and rollback.
