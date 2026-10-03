# Orders workspace implementation report

## 1. Files changed for this task

- `app/Views/Pages/admin-order/admin-orders.php`: header actions, reset, table content, empty states and accessible row actions.
- `app/Views/Pages/admin-order/admin-order-trash.php`: clear deleted-only context, status badges, accessible table and restore labels.
- `app/Views/Layouts/admin.php`: remove the HTML comment surrounding the main content.
- `public/assets/css/admin/orders.css`: scoped header, row-action and empty-action spacing, including mobile wrapping.
- `src/Repositories/OrderRepository.php`: include the explicit customer ID and pending-payment count in the existing list query.
- `src/Services/OrderService.php`: prepare payment summaries, deadline labels and active-filter state.
- `src/Controllers/OrderController.php`: provide active-filter fallback during load failures.
- `tests/Unit/verify-orders-workspace.php`: payment and rendered-layout checks without a database.
- `tests/Integration/verify-admin-orders.php`: verify real list data includes pending payments, explicit ownership and deadlines.
- This implementation report.

The workspace already contained uncommitted changes. Those changes were retained; this list describes this task's additions, not every difference from Git HEAD.

## 2. UI changes

The existing admin shell and brand remain. Create order and Trash are grouped in the page header and remain visible with zero results. The result count sits in Order records rather than a separate header card. All eight existing filter controls, their query parameters, Apply filters, sorting and pagination remain. Reset filters opens the unfiltered index.

The table now shows Order, Customer, Required date, Fulfilment, Total, Payment, Order status and Actions. The source remains beneath the order number. The real required date remains visible alongside the existing reusable calendar-day label; completed orders keep a Complete label. Total uses the stored order snapshot and the application's TND currency (the current payment schema restricts currency to TND). The existing status badge and inline status-selection workflow are retained.

Payment presentation uses verified totals: unpaid, partially paid, paid or credit/refund due. Pending verification appears when pending payment records exist, including alongside a verified balance state. Rejected payments do not count as paid. Calculations use exact integer thousandths in the service. No payment, refund or order-total writes were added.

Scrollable tables are keyboard focusable and labelled. Table headings have column scope; repeated action links/buttons identify their order. Header actions and row actions wrap, while the existing table scroll container handles narrow screens. Browser visual and keyboard verification remain part of the manual steps below.

## 3. Routes reused

- `admin/orders/index.php`: active orders, filters, reset and pagination.
- `admin/orders/create.php`: existing manual-order creation.
- `admin/orders/detail.php`: order number and View.
- `admin/orders/edit.php`: Edit.
- `admin/orders/delete.php`: delete confirmation and existing POST workflow.
- `admin/orders/trash.php`: deleted orders, pagination and existing restore POST.
- `admin/customers/detail.php`: explicitly linked customer account.

View and Edit preserve the normalized list return query. Delete retains its existing redirect to Trash.

## 4. Existing business logic reused

The existing order controller/service/repository still own list normalization, sorting, pagination and data loading. `DeadlinePresentation` supplies date context. The existing manual create, edit, customer picker, status controller, delete confirmation and transactional trash controller handle all mutations.

No changes were made to fulfilment switching, address/fee validation, status mappings, account creation/linking, duplicate detection, password setup, ownership rules, frozen item prices, material snapshots or transaction boundaries. Consumed materials and payment/history records continue to follow the existing trash policy. The existing accessory-stock cancellation/restoration policy also remains intact.

## 5. New components or routes

None. Existing button, badge, status select, alert, empty-state, table styling and pagination components are reused. The only new executable is a verification script; the additional service method is read-only payment presentation.

## 6. Empty-state detection

The service checks normalized search, status, source, method and required-date filters. If there are no matching rows and any of these filters is active, the view shows No matching orders, guidance and Reset filters. With no active filters, zero rows means No orders yet and Create first order. Sorting and page selection alone do not count as restrictive filters. Load failures show Orders unavailable instead of suggesting there are no orders.

## 7. Trash access

The permanent header Trash action opens the existing trash route. Its existing repository query selects only `deleted_at IS NOT NULL`; active lists continue excluding deleted orders. Trash displays the stored order number, customer snapshot, status and deletion timestamp, with Restore. A failed load is distinguished from an empty trash.

## 8. View, Edit and Delete

View opens the existing details page. Edit opens the existing workflow and its validation. Delete opens the existing confirmation page; the listing itself performs no deletion. Confirming uses the existing CSRF/version checks and transactional soft-delete controller. Restore posts to that same controller with the existing restore flag. Customer-account links appear only when the stored `customer_id` is present; snapshot matching never establishes ownership.

## 9. Visible comment marker cause

The shared layout wrapped the complete `<main>` element and its PHP-required view in an HTML comment. PHP still renders included templates inside HTML comments. The filter-form comment in the Orders view closed the outer comment early because HTML comments cannot nest. The layout's remaining closing marker then appeared as visible `-->` text. Removing the outer wrapper fixes the actual markup and restores the main landmark; no CSS hiding was used.

## 10. Verification and manual tests

Automated checks passed: PHP lint on modified PHP files, `php tests/Unit/verify-orders-workspace.php` (19 checks), and `php tests/Unit/verify-deadline-presentation.php`. The workspace script renders the actual layout and checks its main landmark, visible comment leakage, empty states, routes, escaped snapshots, explicit customer links and exact payment states.

Database integration could not run here. The default PHP configuration lacks PDO PostgreSQL; explicitly loading `C:/php/ext/php_pdo_pgsql.dll` resolves that driver issue, but the configured host `homas-postgres` does not resolve in this execution environment. Run the updated `tests/Integration/verify-admin-orders.php` in the configured application environment, followed by the existing order-edit/status/trash workflow checks. Existing unrelated whitespace warnings in `admin-order-detail.php` were left intact.

1. Sign in as an administrator and open Orders. Verify Create order and Trash on both populated and empty lists.
2. Exercise every filter and both sort directions; paginate and follow View/Edit back to the original list. Reset filters should clear the query.
3. Search for a nonexistent order: verify No matching orders and Reset filters. In a disposable empty database, verify No orders yet and Create first order. Verify a database outage displays Orders unavailable.
4. Compare a row's snapshot, real date, fulfilment and TND total with its detail page. Check today, tomorrow, future and overdue date context, and Complete for completed orders.
5. Check unpaid, pending-only, partial, paid and overpaid fixtures. Verify pending/rejected payments never increase verified paid totals; a pending notice can coexist with partial/paid/credit states.
6. Open a linked customer account. Verify a guest with the same contact snapshot has no account link. Create/edit customer selection remains in its existing form.
7. Create a manual order through the header and empty-state actions. Edit pickup/delivery, checking the existing address/minimum-fee validations and mapped statuses; confirm prices and material snapshots remain preserved.
8. Open Delete, cancel, then reopen and confirm on a disposable order. Verify it leaves active lists, appears in Trash, and retains payments/material/history records. Restore and verify it returns through the existing workflow.
9. Tab through header actions, filters, table scroll region, status controls, row actions and pagination. Verify visible focus and order-specific accessible names.
10. Check desktop and narrow mobile widths. Actions should wrap, tables should scroll within their panels, the shell should remain consistent, and no literal comment marker should appear below the panel.
