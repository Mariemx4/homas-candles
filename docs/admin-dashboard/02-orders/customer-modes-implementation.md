# Manual-order customer modes

Implemented in the existing create/edit workflow. The existing repositories, account creation, duplicate checks, password reset, order snapshots, and transaction manager remain responsible for persistence. No database migration or new HTTP route is required.

## 1. Files changed

- `app/Views/Components/Admin/order-customer-picker.php`: shared radio cards, exclusive panels, account summary, and order-contact disclosure.
- `app/Views/Pages/admin/orders/create.php`: customer component and separate account/order address presentation.
- `app/Views/Pages/admin/orders/edit.php`: shared Guest/Existing modes.
- `public/admin/orders/create.php`: safe Guest default and explicit Existing preselection from customer-directory links.
- `public/assets/css/admin/pages/orders.css`: scoped mode cards, compact summary, spacing, hidden groups, and responsive styling.
- `public/assets/js/admin/order-details.js`: mode switching, disabled inactive fieldsets, account selection, copying, fulfilment presentation, and stale response protection.
- `src/Services/OrderCustomerMode.php`: a small input adapter that maps the chosen mode onto existing service fields; it does not persist data.
- `src/Services/ManualOrderService.php`: mode adapter integration and separately validated account primary address.
- `src/Services/OrderEditService.php`: mode adapter integration without changing the edit version or persistence field list.
- `src/Controllers/ManualOrderController.php` and `src/Controllers/OrderEditController.php`: safe allowlists for the new form fields and required HTTP mode validation.
- `src/Repositories/OrderRepository.php`: deterministic PostgreSQL LIKE collation for customer and order searches. The isolated regression tests exposed the existing nondeterministic-collation error.
- `tests/Unit/verify-order-customer-form.js`: interaction checks.
- `tests/Integration/verify-manual-order-customers.php`: isolated account/order and authenticated HTTP checks.
- `tests/Integration/verify-order-edit.php`: mode-aware controller fixtures and isolated edit regression checks.

## 2. Customer-mode UI implementation

The first control in Customer is “Who is this order for?” with native, labelled radio cards for Existing customer, Guest order, and Create new customer account. Guest is checked on a fresh create form; a customer-directory link explicitly preselects Existing.

Only the active panel is visible and enabled. Inactive panels have both `hidden` and `disabled` on their fieldsets from the initial server render. Native radio keyboard behavior, visible focus, explicit labels, associated errors, and live search results are retained. Nested panel borders and backgrounds are removed; the existing admin shell and shared design tokens remain.

Editing offers Guest and Existing only. Account creation remains a create-order operation, matching the existing edit workflow.

## 3. Existing-customer flow

Search by name, email, or phone through the existing authenticated customer endpoint. Selecting a result replaces search with a compact profile summary and Change customer action. An address summary appears when available.

On creation, the selected profile initially supplies the order contact/address fields. Order contact customization is available in a collapsed disclosure. On editing, selecting a different account preserves the current order snapshots, including a blank optional email. “Use account contact and address” explicitly replaces them. Server validation errors open the contact disclosure.

## 4. Guest flow

Guest displays name, eight-digit phone, optional email, and order notes with the message “Guest order — no customer account will be created.” Delivery details remain in Fulfilment. Saving creates only the order and its existing related order records, with no account, customer address, credential, or account-history ownership link. Matching an existing phone/email does not attach an account.

## 5. New-account flow

New displays optional name, required email and eight-digit phone, optional distinct second phone, and required primary account address. An omitted name retains the existing email-name fallback. Password entry is absent; the established Forgot Password flow lets the customer set a password.

The account, primary address, order, items, recipes, and initial history are committed in the existing transaction. Duplicate identity checks remain in the existing account workflow. A failed order insert rolls back the newly created account/address as well.

For delivery, “Use the account primary address for this order's delivery” is checked initially. It avoids entering the same address twice while creating an independent order snapshot. Unchecking exposes a separate order delivery address in Fulfilment. Pickup still requires the account address but stores no delivery address on the order.

## 6. Exact customer_id behavior

| Mode | Saved order customer_id |
| --- | --- |
| Existing | Validated ID explicitly selected by the administrator |
| Guest | NULL, even if a stale or forged account ID is posted |
| New | ID returned by account creation; any posted old ID is ignored |

No name, email, or phone matching infers ownership. Existing mode without a selected valid ID fails validation. Edit rejects New mode.

## 7. Snapshot behavior

Contact/address values continue to be written into the existing order snapshot columns. Guest contact fields belong only to the order. Linked accounts retain both ownership and independent snapshots. Account primary addresses remain customer address records. Later profile changes do not rewrite historical order snapshots, and editing snapshots does not modify the customer profile.

## 8. Switching modes

Switching clears the selected account ID, search/results, and open contact disclosure. The selected panel is enabled; every other panel is hidden and disabled, preventing its inputs from being submitted. Inputs can remain available in their own mode while the administrator works, but the server reads only the active mode's prefixed contact fields. Pending search or profile responses are discarded after a mode/selection change. Address-copy controls apply only to New mode; fulfilment remains responsible for order delivery requirements and fees.

## 9. Server-side validation changes

HTTP create/edit submissions must supply a recognized string mode. Missing, unknown, or array modes fail validation. The input adapter derives the existing creation flag from mode, ignores inactive contact fields, clears IDs for Guest/New, and allows only Existing/Guest on edit. New validates its account address separately from any order delivery address. CSRF, administrator authorization, POST/redirect behavior, duplicate checks, transaction handling, and existing totals/deposit/item validation continue unchanged. Trusted internal service callers without a mode retain their established contract.

## 10. Verification and manual test steps

Passed:

- PHP syntax checks for modified services, controllers, routes, and views; JavaScript syntax check.
- `node tests/Unit/verify-order-customer-form.js`: exclusive panels, explicit selection/change, stale search/profile responses, edit snapshot preservation, explicit copy, address disclosure, and delivery-fee retention.
- `php tests/Integration/verify-manual-order-customers.php` in Docker: 44 checks covering all modes, primary/delivery address separation, pickup account address, duplicates, invalid/missing modes, forged fields, rollback, snapshot independence, password setup, authenticated customer endpoints, initial markup, redirects, and failed-submission redisplay.
- `php tests/Integration/verify-order-edit.php` in Docker: 44 checks covering edits, mode ownership, CSRF, concurrency, snapshots, fulfilment, payments, history, trash, and restore.

Integration tests create disposable PostgreSQL schemas and remove them in `finally`; they do not apply migrations to the live application schema. Browser visual verification was unavailable in this session. Use these manual steps to check the rendered layout and native keyboard interaction:

1. Existing: select the mode; search by each identity field; select a customer; check the summary and address; use Change customer; select again and save. Confirm the selected account history contains the order and the snapshots are populated.
2. Guest: enter required contact details, leave email blank, and save pickup. Repeat delivery with a complete address. Use a phone/email matching an account and confirm customer_id remains NULL and no customer/address record is added.
3. New: save pickup with blank optional name/second phone and a complete primary address. Confirm the account, address, and linked order exist. For delivery, test both the checked address-copy option and a different delivery address. Test duplicate email/phones and a missing primary address; confirm no partial records are saved.
4. Switching: enter values in each mode, switch away, and save the next mode. Confirm only its fields are processed and no prior account ID reappears. Switch while search is in progress and check that late responses do not change the active mode.
5. Editing: open a linked order and confirm its account summary; switch to Guest and save with the order contact details. Relink explicitly. Confirm profile selection preserves snapshots until the explicit copy action is used.
6. Accessibility/layout: use Tab and arrow keys through the mode cards, check visible focus and error association, and review the stacked cards on a narrow viewport. Verify only the active workflow is visible.
