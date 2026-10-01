# Backend delivery status

The week numbers below are the original delivery plan, not a deployment claim.
Status reflects the code in this repository as of 30 September 2026. Run the
verification commands before relying on a deployment.

| Week | Planned milestone | Current implementation |
| --- | --- | --- |
| 1 | Schema, migrations, PDO, repositories, error handling | Migrations through `020`, PDO repositories, private configuration, and a central HTTP error boundary are present. |
| 2 | Shop, categories, filters, product pages | Database backed catalogue, search, sorting, pagination, product detail, and active admin category navigation are present. `bouquets` maps to the historical `candle-bouquets` URL. |
| 3 | Customer and admin authentication | Separate account flows, sessions, password hashing, login throttling, and access checks are present. |
| 4 | Admin taxonomy and materials | Admin category, product type, colour, scent, and material management routes and services are present. |
| 5 | Products, translations, images, activation | Product editing, upload validation, translation draft generation/review, and activation checks are present. Generation requires local API configuration. |
| 6 | Recipes and pricing | Recipe, production cost, selling price, and profit services are present. |
| 7 | Cart and options | Session cart supports add, update, remove, option and stock validation, and accessory rules. |
| 8 | Checkout and orders | Checkout validates the minimum, delivery details, date, and snapshots agreed prices into orders. |
| 9 | Custom requests | Bouquet/event favor requests, contact follow up, and administrator conversion/manual order flows are present. |
| 10 | Order status and stock | Order status workflows, production records, and audited stock movements are present. |
| 11 | Languages and translation workflow | English, French, and Arabic dictionaries, locale persistence, Arabic RTL, and reviewed product translations are present. |
| 12 | Verification and deployment | Unit/integration checks, a disposable database HTTP journey check, and a runtime preflight are present. Responsive browser review, backup/restore rehearsal, and production deployment remain operational checks. |

## Verification

Use the same PHP installation as the web server. On the local XAMPP setup:

```powershell
C:\xampp\php\php.exe scripts\preflight.php
C:\xampp\php\php.exe scripts\verify-application.php
```

`preflight.php` checks PHP extensions, Argon2id, writable runtime directories,
private database configuration, and MariaDB connectivity. The application
verification creates and removes a disposable database and exercises public
and administrator HTTP journeys. Passing these commands does not replace a
browser review or a backup restore test on the deployment environment.
