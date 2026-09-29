# Module 01: Admin Shell and Overview

## Objective

Replace the placeholder admin page with a secure, reusable, responsive admin shell and a read-only operational overview. Do not implement catalogue, order, request, payment, or inventory mutations here.

## Build

- Reusable authenticated admin layout with a desktop sidebar, mobile navigation, page title/breadcrumb support, and header.
- Navigation for Dashboard, Orders, Custom Requests, Payments, Catalogue, Inventory, Production, Customers, Reports, and Settings.
- Current administrator identity and the existing CSRF-protected POST sign-out flow.
- Reusable stat cards, alerts, status badges, empty states, tables, and pagination-ready list components.
- Summary cards for requested orders, orders due within seven calendar days, pending payment verification, open custom requests, and stock requiring attention.
- Urgent actions ordered by urgency, an upcoming-orders table, recent custom requests, and pending payments.
- Dedicated repository queries and service/controller orchestration; no SQL in views.
- Safe database-error and empty states.

## Definitions

- **Due soon:** required within the next seven calendar days and not delivered or picked up.
- **Overdue:** required date has passed and the order is not delivered or picked up.
- **Open request:** not converted, declined, or cancelled.
- **Pending payment:** use the existing database status that requires administrator verification.
- Use the schema as the source of truth for exact status strings.
- If there is no reliable stock threshold, show the lowest-stock tracked materials and label them accurately. Do not invent a threshold or alter the schema only to create a count.

## Acceptance criteria

1. Signed-out and inactive administrators cannot access the dashboard.
2. An active administrator sees their safely escaped name, navigation, metrics, urgent actions, and upcoming work.
3. Counts match the documented date/status definitions and are calculated efficiently in repositories rather than by loading all rows into PHP.
4. Upcoming orders are limited, sorted by urgency, and show only order number, customer name, required date, fulfilment method, total, and status.
5. Overdue items are distinguishable without color alone.
6. Empty datasets render useful empty states without warnings, fake rows, or broken tables.
7. Database failures reveal no SQL, credentials, stack traces, filesystem paths, or sensitive records.
8. The overview reveals no phone, email, full address, payment evidence, authentication data, or private notes.
9. Sign-out remains POST-only and CSRF-protected.
10. Unfinished navigation destinations are disabled or marked `Coming soon` and do not produce broken routes.
11. The shell has no overlapping or clipped controls and no page-level horizontal overflow at 320, 375, 768, 1024, and 1440 px.
12. Mobile navigation supports keyboard use, Escape, focus handling, a correct accessible label, and `aria-expanded` state.
13. Views contain presentation only; data access and dashboard aggregation are separated into repository and service/controller layers.
14. Tests cover authorization, metric filters, date boundaries, empty states, and safe output. Changed PHP passes syntax checks and relevant existing tests pass.
