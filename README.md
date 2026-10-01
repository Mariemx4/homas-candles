# Hom'As Candles

A multilingual e-commerce website for customized handmade scented candles.

## Main features

- English, French, and Arabic support
- Customizable candle orders
- Bouquet and event-favor requests
- Customer accounts
- Cash on delivery
- Admin dashboard

## Technology

- HTML
- CSS
- JavaScript
- PHP
- MySQL

PHP classes follow PSR-4 under the `App\\` namespace in `src/`. The project includes a
small built-in autoloader, so Composer is optional during local development.

## PHP runtime and deployment preflight

Use the PHP installation that Apache runs for **every** CLI command. On this
Windows/XAMPP setup, run `C:\xampp\php\php.exe` explicitly; the `php` command
on `PATH` can resolve to a separate installation with different extensions and
configuration. XAMPP's CLI loads `C:\xampp\php\php.ini`. After changing that
file, restart Apache so its web workers load the same settings. On this machine,
`C:\xampp\apache\conf\extra\httpd-xampp.conf` loads Apache's PHP module from
`C:/xampp/php/` and sets `PHPRC` to that directory. Check those directives after
an XAMPP reconfiguration. On another
host, point CLI commands at the web server's PHP installation and compare the
PHP version, loaded `php.ini`, and extensions reported for each SAPI.

`composer.json` lists the PHP extensions required by the application: PDO MySQL
for the database, Fileinfo for upload validation, cURL for translation
generation, plus the listed session, filtering, hashing, JSON, and ctype
extensions. Argon2id password support is checked separately. SQLite is used
only by some tests; install PDO SQLite to run those tests.

From the deployed project root, run this before migrations or serving traffic:

```powershell
C:\xampp\php\php.exe scripts\preflight.php
```

The command reports the binary, version, and loaded ini files, then checks
extensions, Argon2id, writable runtime folders, private database configuration,
and a MariaDB connection. It exits nonzero when a requirement fails and never
prints database credentials. The CLI result applies to the binary shown in its
output; verify Apache is configured to use the same PHP installation.

## Running the project locally

- XAMPP should be installed to run this project
- the project folder needs to be placed in XAMPP's htdocs directory
- after opening XAMPP control panel, start Apache and MySQL
- Open the project’s local URL in a browser: http://localhost/homas-candles/public/

The application discovers `/homas-candles/public/` from the executing script.
When the public directory is mounted differently, set `APP_BASE_URL` to an
absolute URL path ending in `/`, such as `/shop/`. A production virtual host
should use `public/` as its document root.

## Search indexing and previews

Set `APP_PUBLIC_ORIGIN` to the shop's public scheme and hostname (for example,
`https://shop.example.com`, with no path). Keep `APP_BASE_URL` for the path
under that host. The explicit origin prevents a request's Host header from
becoming a canonical or social-preview URL. Without it, only loopback hosts
receive absolute metadata; other hosts are marked `noindex` until deployment
configuration is complete.

The shared public head emits localized descriptions, canonical links, and
Open Graph/Twitter previews for indexable pages. Active product pages also emit
Product JSON-LD from the current catalogue record. Request-only products have
no price Offer; cart products use their confirmed price and current availability.
Private account, cart, checkout, and authentication pages are marked `noindex`.
Search and filtered catalogue pages are `noindex` and point canonically to
their unfiltered landing page. Missing products and categories are `noindex`.

`public/sitemap.php` lists active categories and products with valid image
files, in all three languages. Submit its public URL to search engines. The
`public/robots.txt` rules assume `public/` is the virtual host's document root;
if deployed under a subpath, place equivalent rules at that host's root.
Robots rules guide crawlers and do not replace authentication for private pages.

## Database connection

1. Create an empty `homas_candles` database.
2. Copy `app/config/database.example.php` to `app/config/database.local.php`.
3. Put your local MariaDB credentials in `database.local.php`. This file is ignored by Git and must not be committed.
4. Apply all versioned migrations and the production-safe reference seed:

   ```powershell
   C:\xampp\php\php.exe scripts\migrate.php
   C:\xampp\php\php.exe scripts\seed-database.php
   ```

5. Verify the PDO connection and rebuilt schema:

   ```powershell
   C:\xampp\php\php.exe scripts\check-database.php
   C:\xampp\php\php.exe scripts\verify-database.php
   ```

The application uses a shared PDO connection configured for `utf8mb4`, exception mode, and native prepared statements.

The former `app/config/migrate.php` command remains as a compatibility launcher.

## Login attempt limits

Customer and administrator sign-in share persistent account and server-IP failure
limits. Five account failures start a two-second delay; each subsequent failure
doubles it up to 15 minutes. IP delays start after 20 customer failures or 10
administrator failures. Counters reset after 15 minutes without failures, and
a successful login clears that account's counter. The application reads the IP
from the web server's `REMOTE_ADDR`, not request forwarding headers.

Bucket keys are HMAC digests. Set `LOGIN_THROTTLE_KEY` to a stable secret of at
least 32 characters, or copy `app/config/login-throttle.example.php` to the
Git-ignored `app/config/login-throttle.local.php` and put a random key there.
Keep the same key across app instances and restarts. Login fails closed if it
is missing. Migration `020_create_login_throttle.sql` creates the counter table.

## Password hashing and length

New customer passwords and password resets use Argon2id with an eight-character
minimum and a 4,096-byte maximum. Both login realms enforce that maximum. Existing bcrypt hashes accept
only passwords shorter than 72 bytes, because bcrypt ignores bytes after byte
72 and a hash cannot reveal whether its original password was longer. A
successful shorter legacy login upgrades its hash to Argon2id without
overwriting a concurrent password reset. Accounts with a 72-byte or longer
legacy password need a new password (customer reset or administrator account
rotation). The XAMPP PHP build must have
Argon2id support; account creation and resets fail closed if it is missing.

## Product translation generation

Copy `app/config/translation.example.php` to the Git-ignored `app/config/translation.local.php`
and set `api_key` to your OpenAI API key. The default model is `gpt-4o-mini`.
Saving a complete product translation generates drafts for the other two languages.
Review and approve each draft in the product editor; only approved text is published
and counts toward activation. Missing configuration or API errors are shown as failed
drafts that can be regenerated after the issue is fixed. API usage is billed to the
OpenAI account associated with the key.

`app/database/seed.sql` contains reference data only. `app/database/demo_seed.sql` is optional development data and must never be applied in production. Local SQL backups are written to `app/storage/backups` and ignored by Git.

## Password reset email delivery

Customer password resets use PHP's configured `mail()` transport. The application
creates a six-digit, ten-minute verification code, stores only its password hash,
and sends the plain code only to the customer's email address.

Before real emails can arrive:

1. Configure PHP/XAMPP with a working SMTP relay or `sendmail_path`.
2. Set `APP_MAIL_FROM` to a verified sender address, for example
   `no-reply@your-domain.tn`.
3. Restart Apache after changing PHP or environment configuration.
4. Test delivery to a real inbox, including its spam folder.

Do not place SMTP passwords in tracked PHP files. Keep provider credentials in
the server/XAMPP mail configuration or environment.

## Administrator access

Administrator and customer identities are deliberately separate. Administrators
sign in at `http://localhost/homas-candles/public/admin/login.php`; protected
admin pages accept only an active administrator session and re-check the account
status on every request. Admin sessions expire after 15 minutes of inactivity by
default. Set `ADMIN_SESSION_TIMEOUT` to a positive number of seconds to override
that value.

Administrator accounts are provisioned directly by an authorized database
operator; there is no public administrator signup route. Store passwords only as
values produced by PHP's `password_hash($password, PASSWORD_DEFAULT)`.

## Project structure

For a guided explanation of how a request moves through these folders, read
[the code tour](docs/code-tour.md). Treat comments as explanations of current
business rules and design choices, and check older generated comments against
the code they describe.

```text
app/
├── Bootstrap/      Autoloading and dependency construction
├── database/       Migrations and reference seed data
├── Views/          Layouts, components, and page templates
├── config/         Declarative application configuration
├── storage/        Private runtime files
└── translations/   Language dictionaries
src/
├── Auth/           Authentication and session domain classes
├── Controllers/    Request validation and view-data preparation
├── Database/       Transaction support
├── Mail/           Email delivery contracts and implementations
├── Repositories/   Database queries and persistence
├── Security/       CSRF and other security helpers
└── Services/       Application and asset-processing logic
public/             Web entry points and browser assets
scripts/            Database and maintenance commands
tests/              Unit and integration verification
docs/               Design and delivery documentation
```

Public PHP files should remain small: bootstrap dependencies, ask a controller
for view data, and render a page from `app/Views/Pages`.

## Verification

Run the disposable-database HTTP journey check from the XAMPP project root:

```powershell
C:\xampp\php\php.exe scripts\verify-application.php
```

Existing verification commands remain stable even though their implementations
now live in `tests/Integration`:

```powershell
C:\xampp\php\php.exe scripts\verify-customer-auth.php
C:\xampp\php\php.exe scripts\verify-administrator-auth.php
C:\xampp\php\php.exe scripts\verify-password-reset.php
C:\xampp\php\php.exe scripts\verify-repositories.php
C:\xampp\php\php.exe scripts\verify-database.php
C:\xampp\php\php.exe scripts\verify-migrations.php
C:\xampp\php\php.exe tests\Unit\verify-base-url.php
C:\xampp\php\php.exe tests\Unit\verify-public-asset-path-resolver.php
```
