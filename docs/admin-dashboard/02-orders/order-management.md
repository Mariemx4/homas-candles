# Order management

- **Delete** opens a confirmation page, then moves the order to **Trash**. Restore returns it to the active orders, customer history, and production lists. Payment and material records are retained; deletion does not issue refunds or put consumed materials back into stock.
- **Edit order → Method** can switch between delivery and pickup at any status. Pickup uses a zero fee; delivery requires a complete address and a fee of at least 9.000 TND. Waiting/dispatched and completed statuses are mapped to the corresponding fulfilment method. Item prices and material usage are preserved. Verified overpayments are shown as a credit/refund due, without creating a refund transaction.
- **Link a customer account** searches by name, email, or phone when creating or editing an order. Choose the account, optionally copy its contact/address, then save. The order's `customer_id` powers the customer's administrator-visible order history. Contact snapshots are independent; matching a name or phone alone never silently assigns ownership.

## Database update

Migration `014_add_order_trash.sql` adds deletion metadata and the `active_orders` view used by operational queries. Run `php scripts/migrate.php` for both new installations and upgrades. Migrations 001–013 have been restored to their original bytes; the runner also recognizes the exact historical checksums of a comment-only revision without changing database records. New schema changes belong in new numbered migrations.

## Verification

Run `tests/Integration/verify-order-edit.php`, `scripts/verify-order-status-workflow.php`, `scripts/verify-production-workflow.php`, and `scripts/verify-customer-directory.php` with the XAMPP PHP executable.
