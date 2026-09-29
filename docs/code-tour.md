# Code tour

This guide explains how the folders cooperate. Read it before following the
`FILE PURPOSE` and `DATA FLOW` comments at the top of individual code files.

Comments also appear beside the code they explain:

- `FUNCTION PURPOSE` describes a class method before its declaration.
- `ROUTE STEP`, `REQUEST BRANCH`, and `RENDER STEP` trace an HTTP entry point.
- `DEPENDENCY PURPOSE` explains why bootstrap constructs each object.
- `SECTION PURPOSE` and `FORM PURPOSE` divide PHP/HTML views into regions.
- `SQL IDIOM`, `VALIDATION IDIOM`, and `COLLECTION IDIOM` explain PHP syntax
  whose effect is not obvious to a new developer.
- CSS `BASE RULES`, `RESPONSIVE`, and `ANIMATION` comments separate rule phases.
- SQL `SECTION PURPOSE` comments identify schema and seed operations.

These comments explain responsibility and data movement; they are not meant to
translate every punctuation character into English. When a syntax construct is
unfamiliar, start with the nearest purpose/idiom comment and then follow the
named method into the next layer shown below.

## The main request flow

Most pages follow the same direction:

```text
Browser request
    ↓
public/*.php or public/admin/*.php       HTTP entry point
    ↓ requires
app/Bootstrap/*.php                     constructs dependencies
    ↓ creates
src/Controllers/*Controller.php         coordinates the use case
    ↓ calls
src/Services/*Service.php               applies business rules
    ↓ calls
src/Repositories/*Repository.php        runs SQL through PDO
    ↓ returns data back up the chain
app/Views/Layouts/*.php                 creates the outer HTML shell
    ↓ requires $contentView
app/Views/Pages/*.php                   renders the page content
    ↓ may require
app/Views/Components/*.php              renders reusable fragments
```

JavaScript in `public/assets/js/` enhances the HTML after it reaches the
browser. CSS in `public/assets/css/` styles the class names produced by the
layouts, pages, and components.

## Why `$escape` works inside an admin page view

The administrator entry point does not open a page view directly. It selects a
view and then requires the admin layout:

```php
$contentView = __DIR__ . '/../../app/Views/Pages/admin-dashboard.php';
require __DIR__ . '/../../app/Views/Layouts/admin.php';
```

The layout defines the HTML-escaping closure and later requires the selected
view:

```php
$escape = static fn (mixed $value): string =>
    htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

require $contentView;
```

PHP includes the view in the layout's current variable scope. Consequently,
the page view can call `$escape(...)`, even though it does not define the
closure itself. Opening a file from `app/Views/Pages/` directly would bypass
that setup and is unsupported.

## What each top-level folder owns

### `public/`

These are the files Apache is allowed to execute or serve. PHP files here are
routes/entry points. They read request input, load bootstrap wiring, enforce
access where necessary, call a controller, and choose a response. Browser CSS,
JavaScript, and public images also live here.

### `app/Bootstrap/`

Bootstrap files are composition roots. They construct repositories, services,
controllers, authentication objects, and shared infrastructure. They expose
those objects as variables to the entry point that required them. They should
not contain HTML or feature rules.

### `src/Controllers/`

Controllers sit between HTTP entry points and business services. They normalize
request-shaped input, call the correct service operation, and return structured
data suitable for a view, redirect, or download.

### `src/Services/`

Services contain business decisions: validation, allowed state changes,
transactions, calculations, and coordination between repositories or file
storage. They do not know how the final HTML looks.

### `src/Repositories/`

Repositories own SQL and PDO interaction. A service asks them to fetch or save
data. Keeping SQL here prevents controllers and templates from becoming tied to
database details.

### `app/Views/`

- `Layouts/` produce the shared outer document and define shared view helpers.
- `Pages/` render feature-specific page content from prepared variables.
- `Components/` render smaller reusable fragments and inherit variables from
  the parent view that requires them.

Views should escape dynamic text at output time. They should not query the
database or implement business state changes.

### `app/config/`, `app/database/`, and `app/storage/`

- `config/` contains environment-aware runtime configuration.
- `database/` contains migrations and seed SQL.
- `storage/` contains private runtime output such as uploads and backups; it is
  data, not source code, and is deliberately excluded from generated comments.

### `scripts/` and `tests/`

Scripts are explicit command-line entry points for setup, maintenance, and
verification. Tests exercise isolated rules or complete feature flows. A
`scripts/verify-*.php` file is generally a convenient launcher for the matching
test.

## Namespaces and file locations

`composer.json` maps the namespace prefix `App\` to the physical `src/`
directory:

```text
App\Services\AdminProductService
        ↓
src/Services/AdminProductService.php
```

A `use` statement imports a class name; it is not a path and does not require a
same-named folder under `app/`. Composer's autoloader—or the project's fallback
autoload implementation—uses the mapping to load the PHP file when the class is
first referenced.

## How to read a feature

Start at the URL's matching file in `public/`. Follow its bootstrap include,
then the controller call, service calls, and repository methods. Return to the
entry point and follow `$contentView` into the layout and page. This order shows
both halves of the feature: how data is produced and how it is displayed.
