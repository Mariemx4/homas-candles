# Beginner handbook: reading and changing this project

Read this once before editing. You do not need to understand every PHP class to
make a small change safely; you need to follow the data through the right layer.

## 1. Know what is running

The website runs in Docker at `http://localhost`. Apache serves `public/`, PHP
runs on the server, and PostgreSQL stores the shop's records. Your editor changes
files on the computer; the current container has a separate source copy.

Use File -> Save All, then run `node scripts/sync-local.js` from the project
folder to apply ordinary saved code edits. Refresh the browser with Ctrl+F5.
The sync command preserves private configuration, uploads and database data.
Schema/runtime dependency changes require the separate steps in the
[operations guide](local-docker.md).

## 2. Follow one real page

Open `public/admin/catalogue/products.php`. This is the entry for the existing
product URL, not the HTML template. Read it in this order:

1. Its bootstrap creates the catalogue controller/services/repositories.
2. `requireActiveAdministrator()` rejects a missing or suspended login.
3. The method branch decides whether this is a GET (read) or POST (write).
4. The controller validates input and delegates the operation to a service.
5. The service applies business rules and uses a repository for SQL.
6. A POST puts its result in flash storage and redirects with HTTP 303.
7. A GET selects a view from `app/Views/Pages/admin/catalogue/` and renders the
   shared admin layout.

A 303 redirect makes browser refresh repeat a GET instead of repeating a write.
Keep it when reorganizing an endpoint. Flash messages/failed input survive that
one redirect; they are not a second permanent copy of the business record.

## 3. Understand includes and variables

```php
$contentView = VIEW_PATH . '/Pages/admin/catalogue/products.php';
require VIEW_PATH . '/Layouts/admin.php';
```

`VIEW_PATH` is an absolute filesystem path defined once in `app/config/paths.php`.
It is not a URL. The layout requires the page in its own PHP scope, so the page
can use the prepared `$escape`, `$administrator` and view-data variables.

The layout creates `$escape` from `Presentation::escape(...)`. In PHP this
first-class callable syntax produces a callable object; `$escape($name)` calls
the shared method. Do not print a customer/product value directly into HTML.

```php
<h1><?php echo $escape($product['name']); ?></h1>
```

Escaping belongs at the HTML boundary. Do not HTML-escape data before storing it
in PostgreSQL: otherwise it can be escaped twice and display incorrectly.

## 4. Choose the correct layer

| What you want to change | Start here |
| --- | --- |
| Sidebar/tab order or labels | `src/View/AdminNavigation.php` |
| An icon or shared row control | `src/View/AdminIcons.php` |
| Money/date/enum display | `src/View/Presentation.php` |
| Admin page markup | `app/Views/Pages/admin/<feature>/` |
| Shared markup used by several pages | `app/Views/Components/Admin/` |
| Button/form/table styling | `public/assets/css/admin/components/` |
| Phone composition | `public/assets/css/admin/layout/responsive.css` |
| Admin tab/form-step behavior | `public/assets/js/admin/workspace.js` |
| A price, readiness or business rule | The corresponding `src/Services/` class |
| Which product fields a category allows | `src/Services/ProductCoreValidator.php` |
| Product translations / photo replacement | `ProductTranslationService` / `ProductImageService` |
| Requested products in a custom request | `src/Services/CustomRequestDefinitionValidator.php` |
| Parsing or formatting a TND amount | `src/Services/Money.php` (never use floats) |
| Reading a list filter from the URL | `src/Http/ListQuery.php` |
| A query or persisted column | The corresponding `src/Repositories/` class |
| An allowed return destination | `src/Http/AdminRecordNavigation.php` |

### Quick answers

- **Change a business rule:** find the service named after the area (for
  example `PaymentService`), change the rule there, and run that area's
  integration suite. Never copy the rule into a template or JavaScript.
- **Add a route:** add a thin PHP file under `public/` that requires the
  area's bootstrap, checks the method and login, calls the controller and
  redirects with 303 after a POST. Copy an existing neighbour and read
  [the routing reference](routing.md) first.
- **Adjust a reusable component or style:** markup in
  `app/Views/Components/Admin/`, styles in `public/assets/css/admin/components/`,
  values from the token files (section 7).
- **Run checks:** `php scripts/check-project.php`, then the suites listed in
  [tests/README.md](../tests/README.md). Only run suites listed there as isolated.
- **Recover a failed deployment:** every `node scripts/sync-local.js` prints a
  `Previous source backup` path inside the container. Restore it with the
  command in [the operations guide](local-docker.md#rolling-back-a-source-sync).

Do not put a database query into a template to save time. It creates another
data path, makes failures harder to handle and can repeat one query per row.
Prepare the value once through the existing service/controller flow.

## 5. Read a service transaction

A transaction means several related writes succeed or fail together. For
example, a profile change and its actor-attributed history must be one operation.
If history fails, the profile must not be partially saved. A repository's row
lock protects the record only while the surrounding transaction remains open.

Keep these protections when extracting methods. A shorter method is not an
improvement if the lock is released before the dependent write. SQL values
belong in prepared-statement parameters; only allowlisted table/column names
may be composed into SQL identifiers.

## 6. Keep forms working

The field `name` is the server's input key. The `id` connects a label/error to
its field. A `data-*` attribute is a browser-script hook. They have different
jobs; changing one can break a flow even when the screen still looks correct.

Retain hidden CSRF fields and expected-version values. CSRF protects the write
from another site. Expected versions prevent an old editor tab from overwriting
a newer saved record. The server must validate both even if JavaScript already
checked the visible form.

The guided forms move existing controls into declared steps. They keep the same
POST form, field names and endpoint. Browser previews are helpful feedback;
server calculations still decide the saved price or stock movement.

## 7. Reuse styles without changing the design

The existing brand primitives live in `public/assets/css/tokens.css`. Admin roles
live in `public/assets/css/admin/tokens.css` and usually reference those primitives.
Use `var(--admin-border)` instead of copying its hex value into another panel.
Use `var(--space-md)` instead of repeating the same 1rem spacing.

CSS is ordered. A later rule may deliberately change an earlier layout at a
phone breakpoint. Do not move a rule solely because two files mention the same
class. Check its selector specificity, responsive condition and position first.
Keep unique values when replacing them with a nearby token would change pixels.

## 8. Add useful comments

Explain a contract or reason that is not obvious from the statement:

```php
// Recheck references inside the transaction: another request may have linked
// this record since the confirmation screen was opened.
```

Avoid comments that merely repeat the call, such as "execute the query".
When moving code, update the comments and examples that describe its location.
Prefer a clear name and a short method over a paragraph explaining tangled code.

## 9. Verify a change

Review the accessibility, security, responsive and multilingual effect of every
change before you finish it: keyboard use, server-side checks, narrow screens and
all three languages.

Start with `php scripts/check-project.php`. Run the relevant unit/integration
checks from [tests/README.md](../tests/README.md). UI changes also need browser
checks at desktop and phone sizes; passing PHP syntax does not prove usability.

Integration/browser fixtures use generated schemas and synthetic accounts.
Do not test order creation, stock deductions, deletion or password recovery on
the shop account. Keep the private test state/configuration out of Git.

Not every file in `tests/Integration/` is isolated yet. Before running a suite,
check that [tests/README.md](../tests/README.md) lists it as isolated. On
2026-11-12 a non-isolated suite wrote synthetic accounts into the real database
and had to be cleaned up from a backup-verified state; the suites involved now
call `isolate_application_database()` and refuse to run otherwise.

## 10. Protect data while maintaining source

Do not delete `app/storage`, uploaded product images or `*.local.php` files as
"unused code". Database archives do not contain uploaded files. Preserve both
when recovering or recreating a container. Run a restore check before a deliberate
database cleanup. A file being outside Git does not make it disposable.

The dated cleanup record explains what was removed and where its recovery
copies are retained. [The architecture map](architecture.md) and
[routing reference](routing.md) provide the next level of detail.
