# Module 09: Customers

## Objective

Provide a privacy-conscious customer directory and history for legitimate order support and repeat business.

## Build

- Paginated customer search using name, email, or phone with careful result limits.
- Customer detail with account state, saved addresses, order history, custom-request history, total verified/completed value, and last-order date.
- Links to authorized order/request details and a manual-order starting point.
- Account activation/deactivation only if supported by existing business rules.
- Clear distinction between account data and immutable order/request snapshots.

## Acceptance criteria

1. Every route requires an active administrator and customer identifiers are strictly validated.
2. Search is prepared, normalized, rate-conscious, paginated, and cannot enumerate unrestricted customer data.
3. Directory rows show only the minimum useful identity data; addresses and full history remain on the protected detail page.
4. Historical orders display snapshots and are not rewritten when account/profile data changes.
5. Customer totals have an explicitly documented status/payment basis and use authoritative records.
6. Missing/deleted product relationships and optional customer fields do not break history.
7. No passwords, password hashes, reset records, session data, payment evidence, or private administrator credentials are queried or rendered.
8. Any account-state mutation uses POST, CSRF, server-side rules, and an audit trail where supported.
9. Responsive pages handle long histories through pagination rather than unbounded queries.
10. Tests cover authorization, search safety, privacy projection, metric definitions, missing data, pagination, and any account-state action.
