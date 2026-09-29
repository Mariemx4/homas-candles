# Hom'As Candles Admin Dashboard

This directory is the implementation specification for the Hom'As Candles admin dashboard. Each numbered folder is one bounded delivery module. An AI agent should implement only one module per task, verify it, and stop.

## Recommended order

1. [Admin shell and overview](01-shell-overview/README.md)
2. [Orders](02-orders/README.md)
3. [Order-status workflow](03-order-status/README.md)
4. [Custom requests](04-custom-requests/README.md)
5. [Payments](05-payments/README.md)
6. [Catalogue](06-catalogue/README.md)
7. [Materials and inventory](07-inventory/README.md)
8. [Production](08-production/README.md)
9. [Customers](09-customers/README.md)
10. [Reports and settings](10-reports-settings/README.md)

Later modules may rely on earlier ones, so they should normally be completed in this order.

## Reusable agent instruction

Use this short prompt for each module:

```text
Read docs/admin-dashboard/README.md and the README for module [NUMBER].
Inspect the repository and existing uncommitted changes before editing. Implement
only that module, meet all acceptance criteria, run the relevant verification,
review the final diff for security and unrelated changes, then report changed
files, test results, assumptions, and remaining risks. Do not claim completion
when a required check fails.
```

## Shared engineering rules

- Inspect the existing schema, migrations, authentication, repositories, services, CSS tokens, tests, and project conventions before editing.
- Preserve existing behavior and user changes. Do not rewrite working code without a module-specific reason.
- Keep public PHP entry points thin: bootstrap dependencies, call controller/application logic, then render a view.
- Keep SQL in repositories, business rules in services, request handling in controllers, and presentation in views.
- Use reusable layouts and components. Do not create monolithic PHP, CSS, or JavaScript files.
- Use the existing autoloader, database connection, administrator authorization, session, CSRF, and configuration infrastructure.
- Use transactions for multi-step changes that must succeed or fail together.
- Avoid new dependencies and schema changes unless the module cannot be implemented correctly without them.
- Never use destructive Git commands or overwrite unrelated work.

## Shared security rules

- Require an authenticated, active administrator on every admin route.
- Enforce authorization and every business rule on the server; hiding a control is not authorization.
- Protect all state-changing requests with CSRF and accept them only through appropriate HTTP methods.
- Use native prepared statements and strict server-side allow-list validation.
- Escape untrusted HTML output with `htmlspecialchars` using `ENT_QUOTES` and UTF-8.
- Apply Post/Redirect/Get after successful mutations to prevent duplicate submission.
- Never expose passwords, password hashes, reset codes, session identifiers, CSRF tokens, environment values, credentials, raw SQL, stack traces, or internal filesystem paths.
- Show only customer data needed for the task. Do not expose payment evidence or private notes in summaries.
- Do not log secrets, authentication data, or unnecessary payment/customer data.
- Validate uploads by MIME content, allowed extension, size, and generated filename; store them where executable code cannot run.
- Use generic public error messages and keep technical details in protected server-side handling.
- Preserve order, payment, inventory, and administrator audit history.
- Prevent authenticated admin responses from being cached by shared/public caches.

## Shared design and accessibility rules

- Reuse the Hom'As Candles colors, typography, spacing, and visual tone through shared admin design tokens.
- Support 320 px, 375 px, tablet, laptop, and wide desktop screens without page-level horizontal overflow.
- Give wide tables a usable mobile/card presentation or contained horizontal scrolling.
- Use semantic landmarks, headings, labels, table headers, status text, visible focus, sufficient contrast, and keyboard-operable controls.
- Do not communicate state using color alone.
- Respect reduced-motion preferences.
- Handle long names, identifiers, translated content, missing optional values, empty results, errors, and large result sets gracefully.
- Provide consistent success, validation-error, empty, and failure states.
- Do not use inline styles or duplicate component rules across pages.

## Shared completion checklist

- Add focused tests for authorization, validation, repository queries, and important business rules introduced by the module.
- Run PHP syntax checks for every changed PHP file.
- Run all relevant existing unit and integration verification scripts.
- Confirm existing public pages and administrator authentication still work.
- Inspect the final diff for secrets, debug output, unrelated edits, duplicated logic, and accidental schema changes.
- Report any skipped check or unresolved risk explicitly.

## Scope discipline

Do not build every module in one change. Links to unfinished modules must be disabled or marked `Coming soon`; they must never lead to broken or unauthorized routes.
