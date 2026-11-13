# Customer accounts and order management

The customer workspace at `account.php` now includes a next-order spotlight,
status-based guidance, order counters and paginated cards filtered by all,
in-progress, completed or cancelled orders. Contact details and addresses share
consistent navigation at `profile.php`. Customers can select their own default
saved address, replace an address at the three-address limit in one save, and
see field errors without losing their entries. See the
[workspace implementation and screenshots](customer-account-workspace.md).

Customers can edit their own active account at `profile.php`: a primary email,
primary Tunisian phone number and one address are required. A second email and
phone are optional, and one to three addresses may be saved. Profile updates do
not rewrite historical order contacts. English, French and Arabic labels are
provided.

Each linked customer account can place at most three orders per database calendar
day. Cancelled and trashed orders still count. Checkout, manual orders and custom
request conversion share the limit. Account-row locks and a database trigger
protect against concurrent submissions. Guest orders have no linked account and
are outside this account quota.

`account-order.php` displays the shared order status, status history and payment
figures. It checks for updates every 15 seconds while visible. Private admin notes
are not exposed. If data changes, old edit/cancel controls are hidden until refresh;
the server independently checks status, ownership, history and version.

`account-order-edit.php` supports quantities, delivery/pickup, address, required
date and customer notes. Only Requested and Confirmed orders can be edited or
cancelled. Any history of preparation or later progress permanently closes this
window, even if an administrator corrects the displayed status back to Requested.

Changed catalogue quantities use current catalogue quantity tiers and minimums;
custom negotiated lines retain their agreed unit price. Existing promotion and
manual-discount terms remain attached to the sale and reductions are recalculated.
The merchandise minimum remains 40 TND, delivery starts at 9 TND and pickup is
free. A final total of at least 100 TND requires a 40% deposit. An explicit server
preview must be confirmed before saving. The saved draft is session-bound; client
totals cannot override it. Changed prices, order data or payments invalidate it.

Verified payments remain credited. Pending payments are shown separately. Both
admin and customer see the new total, deposit still due, remaining balance and
overpayment for refund review. A Confirmed order needing additional verified
deposit returns to Requested. Cancellation preserves payment records, requests
no further payment and identifies verified funds for refund review; refunds are
not automatically issued.

Migrations 027 and 028 add the optional email, address/daily quota guards,
Cancelled status and revision audit. The original sale and every customer edit
are preserved in `customer_order_edits`. A transaction-local audit authorization
permits only an eligible customer's revision of price snapshots. Existing recipe
snapshots and direct-SQL protections remain intact.

Validation: `tests/Integration/verify-customer-order-management.php` uses a random
PostgreSQL schema and an isolated PHP HTTP server. It covers profile limits,
ownership, pricing/deposit changes, credit, pending payments, stale reviews,
concurrent daily limits, CSRF, cancellation and administrator status visibility.
Run with `docker exec homas-test php tests/Integration/verify-customer-order-management.php`.
