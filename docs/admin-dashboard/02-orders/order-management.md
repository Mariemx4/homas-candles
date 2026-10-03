# Order management

- **Delete** opens a confirmation page, then moves the order to **Trash**. Restore returns it to the active orders, customer history, and production lists. Payment and material records are retained; deletion does not issue refunds or put consumed materials back into stock.
- **Edit order → Method** can switch between delivery and pickup at any status. Pickup uses a zero fee; delivery requires a complete address and a fee of at least 9.000 TND. Waiting/dispatched and completed statuses are mapped to the corresponding fulfilment method. Item prices and material usage are preserved. Verified overpayments are shown as a credit/refund due, without creating a refund transaction.
- **Link a customer account** searches by name, email, or phone when creating or editing an order. Choose the account, optionally copy its contact/address, then save. The order's `customer_id` powers the customer's administrator-visible order history. Contact snapshots are independent; matching a name or phone alone never silently assigns ownership.
- **Create manual order** on a customer detail page opens the order form with that account selected and its contact/address filled in. To create an account instead, select **Create a new customer account with this order**, enter email, phone and a complete primary address, and optionally enter a name and second phone. The address is saved even for pickup; an omitted name defaults to the email's local part. No password is requested. Customers can set their own password through **Forgot password**. Duplicate contact identities prompt you to select the existing account. Account, address, order, items and initial history are saved in one transaction.

## Database update

Migration `014_add_order_trash.sql` adds deletion metadata and the `active_orders` view used by operational queries. Run `php scripts/migrate.php` for both new installations and upgrades. Migrations 001–013 have been restored to their original bytes; the runner also recognizes the exact historical checksums of a comment-only revision without changing database records. New schema changes belong in new numbered migrations.

## Verification

`php tests/Integration/verify-manual-order-customers.php` checks account creation/linking, optional secondary phones, pickup and delivery, duplicate contacts, rollback, password setup and authenticated HTTP routes in a disposable PostgreSQL schema. It requires PDO PostgreSQL, cURL, Argon2id and permission to create schemas and ICU collations. `node tests/Unit/verify-order-customer-form.js` checks the create/edit form behavior.

Run `tests/Integration/verify-order-edit.php`, `scripts/verify-order-status-workflow.php`, `scripts/verify-production-workflow.php`, and `scripts/verify-customer-directory.php` with the XAMPP PHP executable.
