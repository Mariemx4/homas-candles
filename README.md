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

## Running the project locally

- XAMPP should be installed to run this project
- the project folder needs to be placed in XAMPP's htdocs directory
- after opening XAMPP control panel, start Apache and MySQL
- Open the project’s local URL in a browser: http://localhost/homas-candles/public/

The application discovers `/homas-candles/public/` from the executing script.
When the public directory is mounted differently, set `APP_BASE_URL` to an
absolute URL path ending in `/`, such as `/shop/`. A production virtual host
should use `public/` as its document root.

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
