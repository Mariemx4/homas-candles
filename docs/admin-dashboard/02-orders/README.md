# Module 02: Orders

## Objective

Provide secure, read-focused order discovery and detail pages. Order-status mutations belong to Module 03.

## Build

- Paginated orders list with search by order number or customer and allow-listed filters for status, source, fulfilment method, and required-date range.
- Sort by required date, creation date, or total using allow-listed columns and directions.
- Order detail showing snapshot customer/fulfilment information, items, selected scent/color, customization, totals, payment summary, notes, and status history.
- Clear overdue/due-soon indicators and links back to the preserved filter state.
- Print-friendly preparation/order summary without navigation or unnecessary personal information.
- Repository queries that avoid N+1 access and never interpolate search/filter input into SQL.

## Acceptance criteria

1. Every route requires an active administrator and validates order identifiers.
2. Search, filtering, sorting, and pagination work together and preserve query state.
3. Invalid filters or sort values fall back safely; they cannot alter SQL structure.
4. Order details use stored snapshots, so renamed/deactivated catalogue items do not corrupt history.
5. Totals, deposit requirement, amount paid, and remaining balance are displayed consistently at three-decimal TND precision.
6. Missing optional address, account, color, scent, or product references render safely.
7. List pages expose only the minimum customer data needed; detailed personal data appears only on the protected order page.
8. No state changes are possible from this module.
9. Empty results, nonexistent orders, and database failures have safe states and appropriate HTTP behavior.
10. List, detail, and print layouts remain usable on all target screen sizes and with keyboard navigation.
11. Tests cover authorization, search escaping, filter allow lists, pagination boundaries, snapshot display, missing references, and not-found behavior.
