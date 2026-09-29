# Module 05: Payments

## Objective

Provide secure payment recording and D17 verification with an immutable administrator audit trail.

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
