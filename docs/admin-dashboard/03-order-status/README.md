# Module 03: Order-Status Workflow

## Objective

Add secure order-status transitions while preserving history, payment rules, and material consistency.

## Build

- Server-defined list of legal next statuses based on the schema workflow.
- Confirmation UI showing the current state, proposed state, consequences, and validation failures.
- Atomic status update plus `order_status_history` record with administrator identity.
- Enforce required-deposit/payment rules before confirmation.
- Perform material deductions or restocks only at the business-approved transitions, using the existing stock ledger and a single transaction.
- Prevent duplicate submissions and duplicate stock movements.
- Administrator notes/reason where a transition requires explanation.

## Acceptance criteria

1. Only authenticated, active, authorized administrators can transition an order.
2. Mutations use POST, valid CSRF, strict identifiers, and Post/Redirect/Get.
3. The server rejects skipped, reversed, repeated, unknown, or otherwise illegal transitions even if a crafted request bypasses the UI.
4. Payment/deposit requirements are enforced from authoritative database values.
5. Status, history, and any related stock movements commit together or all roll back.
6. Concurrent/repeated requests cannot create duplicate history effects or material deductions.
7. History records previous/new status, administrator, timestamp, and permitted note without allowing later editing.
8. Failure messages do not disclose SQL or internal data, and the order remains consistent after failure.
9. The UI explains disabled transitions and does not rely only on color.
10. Tests cover every legal/illegal transition, CSRF, authorization, payment blocking, rollback, and idempotency/concurrency protection.
