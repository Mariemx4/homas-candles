| Week | Backend milestone |
|---|---|
| 1 | Finalize schema, migrations, configuration, PDO structure, repositories and error handling |
| 2 | Connect Shop, categories, filters and product pages to real database records |
| 3 | Customer and admin authentication, sessions, password hashing and authorization |
| 4 | Admin management for categories, product types, colours, scents and materials |
| 5 | Admin product creation, translations, image uploads and activation requirements |
| 6 | Recipes/BOM, production-cost calculation, selling-price rules and profit calculation |
| 7 | Session-based cart, product options, quantities and accessory restrictions |
| 8 | Checkout and normal orders: minimum 40 DT, contact details, required date and price snapshots |
| 9 | Bouquet/Event Favor request workflow, callback requests and admin-created orders |
| 10 | Order-status management and audited material stock movements, including one-time order deductions |
| 11 | English/French/Arabic runtime translations and automatic product-translation workflow |
| 12 | Security review, integration tests, responsive regression checks, database backup and deployment preparation |



| Area | Status |
|---|---|
| PDO structure | Complete |
| Local configuration | Complete for local development; `BASE_URL` remains intentionally configurable |
| Catalogue schema | Complete foundation with database-level integrity checks |
| Complete application schema | Complete 29-table foundation |
| Migrations | Complete through version `010`, with checksums and fresh-build verification |
| Repositories | Started: customer/address and material-stock repositories plus transaction management; catalogue/order repositories remain |
| Error handling | Partial—connection and product errors are handled, but there is no central error handler or environment-aware logging |
| Controllers/services | Missing; their directories are currently empty |

The database foundation is complete. Continue with catalogue repositories/services, then authentication, cart, checkout, order workflows, and administration in the weekly sequence above.
