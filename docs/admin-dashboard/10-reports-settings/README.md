# Module 10: Reports and Settings

## Objective

Add trustworthy operational reports and narrowly scoped configuration/administrator tools after the transactional modules are stable.

## Build

- Date-range reports for orders, revenue, verified payments, estimated costs/profit, top products/scents/colors, request conversion, material use, and waste.
- Clear definitions for included statuses, payment states, date fields, refunds/rejections, and estimated versus realized values.
- Paginated detail tables supporting report totals.
- Safe CSV exports with the same authorization and filters as the on-screen report.
- Store settings only for values that have an explicit authoritative persistence model and validation rules.
- Administrator account management only if roles/permissions and audit requirements are defined; never create public admin registration.
- Protected backup guidance/control only through the existing safe backup mechanism.

## Acceptance criteria

1. Reports require an active administrator and validate date ranges, grouping, filters, and maximum export size.
2. Every metric displays or links to its calculation definition and distinguishes estimates from realized figures.
3. Summary totals match the supporting detail query and use consistent three-decimal TND handling.
4. Reports avoid N+1 queries, unbounded result loading, and arbitrary SQL sorting/grouping.
5. CSV output prevents spreadsheet-formula injection, uses an explicit encoding, and contains only authorized columns.
6. Exports and report pages reveal no credentials, payment evidence, private notes, or unnecessary customer data.
7. Settings mutations use POST, CSRF, strict validation, Post/Redirect/Get, and an administrator audit trail.
8. Secrets are never displayed or stored in tracked settings files; environment/server configuration remains external.
9. Administrator creation/reset/deactivation, if implemented, follows defined permissions and cannot remove the last required active administrator.
10. Backup operations never place database dumps under the public web root and never return credentials in output.
11. Responsive report tables/charts have accessible text/table equivalents and do not depend on color alone.
12. Tests cover metric definitions, date boundaries, CSV injection, authorization, settings validation, sensitive-field exclusion, and backup-path safety.
