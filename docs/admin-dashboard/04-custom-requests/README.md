# Module 04: Custom Requests

## Objective

Manage custom-product enquiries from submission through discussion and safe conversion into a manual order.

## Build

- Paginated request inbox with search and allow-listed filters for status, category, assigned administrator, and required date.
- Detail page for the submitted preferences and only necessary customer contact data.
- Assignment and legal status-transition actions for `SUBMITTED`, `CONTACTED`, `DISCUSSING`, `AGREED`, `CONVERTED`, `DECLINED`, and `CANCELLED`.
- Protected administrator notes or contact-history entries if the schema supports them; do not silently overload customer-facing fields.
- Atomic conversion from an agreed request into one manual order, preserving request/customer snapshots and linking both records.
- Clear due-date and response-needed indicators.

## Acceptance criteria

1. All pages/actions require an active administrator; mutations require POST and CSRF.
2. Search/filter/pagination are injection-safe and use allow-listed values.
3. The server enforces legal request transitions and assignment rules.
4. Only an agreed, unconverted request can be converted.
5. Conversion creates exactly one manual order and records `converted_order_id`, `converted_at`, and `CONVERTED` atomically.
6. Repeated or concurrent conversion attempts cannot create duplicate orders.
7. Declined/cancelled/converted requests cannot be edited as if still open.
8. Snapshot data and optional preferences render safely, including missing values and long messages.
9. Customer contact details appear only where operationally necessary and never in broad dashboard summaries.
10. Tests cover authorization, state rules, assignment, successful conversion, rollback, duplicate prevention, and malformed input.
