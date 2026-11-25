# Routing reference

Each screen has one current public entry point. Update callers when a route
changes and delete the obsolete entry point; do not retain forwarding files or
redirects for old URLs. A template location is a filesystem implementation detail,
not a browser URL.

## Request parameters

- Order detail/edit/delete/print endpoints identify a record with `id`.
- Payment order pages use `order_id`; payment review uses `id` and its allowed action.
- Recipe editing uses `product_id`.
- Promotion editing uses `edit`; other catalogue editors use `id`.
- Account editing uses the allowlisted `kind` and `id`.
- Record status management uses the allowlisted `kind` and `id`.
- Search, sorting and return filters remain normalized by their existing controllers.

`App\Http\AdminRecordNavigation` owns internal list/record destinations.
A raw return URL or Referer header must never become a redirect target.

## Category routing

All active collections use `/category.php?slug=<database-slug>`. Built-in and
admin-created collections follow the same route, including
`/category.php?slug=bouquets` and `/category.php?slug=event-favors`.
`App\Services\CatalogueCategory` builds these paths for navigation and the sitemap.
Filter forms and pagination retain `slug` along with the selected filters and
language. Missing, malformed, unknown and inactive slugs return HTTP 404.

Collection copy belongs in `app/translations/`, not in separate route files.
There are no category URL aliases, per-category PHP files or old-route redirects.
Migration 033 normalizes a stored `candle-bouquets` slug to `bouquets`, preserving
the category ID and related records. This updates existing data once instead of
maintaining an application alias.

## Storefront and customer account

### Google authentication

| Public path | Method | Purpose |
| --- | --- | --- |
| `/auth/google-start.php` | POST | CSRF-protected Google authorization redirect |
| `/auth/google-callback.php` | GET | One-use state/code callback; server-side state separates customer and administrator purposes |
| `/auth/google-complete.php` | GET, POST | Required phone/address completion before account creation |
| `/auth/google-link.php` | GET, POST | Authenticated, explicit existing-account connection |
| `/admin/google-start.php` | POST | CSRF-protected Google sign-in for existing active administrators only |

See [Google sign-in setup](google-sign-in.md). All paths below assume the `/` base URL.

| Public path | Source |
| --- | --- |
| `/account-order-edit.php` | [public/account-order-edit.php](../public/account-order-edit.php) |
| `/account-order.php` | [public/account-order.php](../public/account-order.php) |
| `/account.php` | [public/account.php](../public/account.php) |
| `/cart.php` | [public/cart.php](../public/cart.php) |
| `/category.php` | [public/category.php](../public/category.php) |
| `/checkout.php` | [public/checkout.php](../public/checkout.php) |
| `/contact.php` | [public/contact.php](../public/contact.php) |
| `/forgot-password.php` | [public/forgot-password.php](../public/forgot-password.php) |
| `/index.php` | [public/index.php](../public/index.php) |
| `/login.php` | [public/login.php](../public/login.php) |
| `/logout.php` | [public/logout.php](../public/logout.php) |
| `/order-confirmation.php` | [public/order-confirmation.php](../public/order-confirmation.php) |
| `/order-payment.php` | [public/order-payment.php](../public/order-payment.php) |
| `/product.php` | [public/product.php](../public/product.php) |
| `/profile.php` | [public/profile.php](../public/profile.php) |
| `/reset-password.php` | [public/reset-password.php](../public/reset-password.php) |
| `/shop.php` | [public/shop.php](../public/shop.php) |
| `/signup.php` | [public/signup.php](../public/signup.php) |
| `/sitemap.php` | [public/sitemap.php](../public/sitemap.php) |

## Administration

| Public path | Source |
| --- | --- |
| `/admin/accounts.php` | [public/admin/accounts.php](../public/admin/accounts.php) |
| `/admin/catalogue/categories.php` | [public/admin/catalogue/categories.php](../public/admin/catalogue/categories.php) |
| `/admin/catalogue/colors.php` | [public/admin/catalogue/colors.php](../public/admin/catalogue/colors.php) |
| `/admin/catalogue/product-types.php` | [public/admin/catalogue/product-types.php](../public/admin/catalogue/product-types.php) |
| `/admin/catalogue/products.php` | [public/admin/catalogue/products.php](../public/admin/catalogue/products.php) |
| `/admin/catalogue/promotions.php` | [public/admin/catalogue/promotions.php](../public/admin/catalogue/promotions.php) |
| `/admin/catalogue/scents.php` | [public/admin/catalogue/scents.php](../public/admin/catalogue/scents.php) |
| `/admin/customers/detail.php` | [public/admin/customers/detail.php](../public/admin/customers/detail.php) |
| `/admin/customers/index.php` | [public/admin/customers/index.php](../public/admin/customers/index.php) |
| `/admin/index.php` | [public/admin/index.php](../public/admin/index.php) |
| `/admin/inventory/materials.php` | [public/admin/inventory/materials.php](../public/admin/inventory/materials.php) |
| `/admin/inventory/movements.php` | [public/admin/inventory/movements.php](../public/admin/inventory/movements.php) |
| `/admin/inventory/recipe.php` | [public/admin/inventory/recipe.php](../public/admin/inventory/recipe.php) |
| `/admin/lifecycle.php` | [public/admin/lifecycle.php](../public/admin/lifecycle.php) |
| `/admin/login.php` | [public/admin/login.php](../public/admin/login.php) |
| `/admin/logout.php` | [public/admin/logout.php](../public/admin/logout.php) |
| `/admin/orders/create.php` | [public/admin/orders/create.php](../public/admin/orders/create.php) |
| `/admin/orders/customers.php` | [public/admin/orders/customers.php](../public/admin/orders/customers.php) |
| `/admin/orders/delete.php` | [public/admin/orders/delete.php](../public/admin/orders/delete.php) |
| `/admin/orders/detail.php` | [public/admin/orders/detail.php](../public/admin/orders/detail.php) |
| `/admin/orders/edit.php` | [public/admin/orders/edit.php](../public/admin/orders/edit.php) |
| `/admin/orders/index.php` | [public/admin/orders/index.php](../public/admin/orders/index.php) |
| `/admin/orders/item-recipe.php` | [public/admin/orders/item-recipe.php](../public/admin/orders/item-recipe.php) |
| `/admin/orders/price-preview.php` | [public/admin/orders/price-preview.php](../public/admin/orders/price-preview.php) |
| `/admin/orders/print.php` | [public/admin/orders/print.php](../public/admin/orders/print.php) |
| `/admin/orders/status.php` | [public/admin/orders/status.php](../public/admin/orders/status.php) |
| `/admin/orders/trash.php` | [public/admin/orders/trash.php](../public/admin/orders/trash.php) |
| `/admin/payments/evidence.php` | [public/admin/payments/evidence.php](../public/admin/payments/evidence.php) |
| `/admin/payments/index.php` | [public/admin/payments/index.php](../public/admin/payments/index.php) |
| `/admin/payments/order.php` | [public/admin/payments/order.php](../public/admin/payments/order.php) |
| `/admin/payments/review.php` | [public/admin/payments/review.php](../public/admin/payments/review.php) |
| `/admin/production/calendar.php` | [public/admin/production/calendar.php](../public/admin/production/calendar.php) |
| `/admin/production/queue.php` | [public/admin/production/queue.php](../public/admin/production/queue.php) |
| `/admin/production/sheet-print.php` | [public/admin/production/sheet-print.php](../public/admin/production/sheet-print.php) |
| `/admin/production/sheet.php` | [public/admin/production/sheet.php](../public/admin/production/sheet.php) |
| `/admin/reports/export.php` | [public/admin/reports/export.php](../public/admin/reports/export.php) |
| `/admin/reports/index.php` | [public/admin/reports/index.php](../public/admin/reports/index.php) |
| `/admin/requests/action.php` | [public/admin/requests/action.php](../public/admin/requests/action.php) |
| `/admin/requests/convert.php` | [public/admin/requests/convert.php](../public/admin/requests/convert.php) |
| `/admin/requests/create.php` | [public/admin/requests/create.php](../public/admin/requests/create.php) |
| `/admin/requests/detail.php` | [public/admin/requests/detail.php](../public/admin/requests/detail.php) |
| `/admin/requests/edit.php` | [public/admin/requests/edit.php](../public/admin/requests/edit.php) |
| `/admin/requests/index.php` | [public/admin/requests/index.php](../public/admin/requests/index.php) |
| `/admin/settings/index.php` | [public/admin/settings/index.php](../public/admin/settings/index.php) |

The catalogue taxonomy screens share their handler in `app/Bootstrap/admin/`.
Only the four concrete taxonomy screens are public endpoints. Authorization and
HTTP method/CSRF checks remain in the endpoint/controller flow.

Run `php scripts/check-project.php` to check imports, literal route links,
view/asset paths and class locations.
The authenticated integration suite follows the real routes and verifies their
response content; the browser suite additionally exercises links and forms.
