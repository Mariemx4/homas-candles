# Tests

- `Unit/` contains isolated checks with no database dependency.
- `Integration/` contains checks that use sessions, the database, or the complete application bootstrap.
- Existing `scripts/verify-*.php` commands are compatibility launchers, so documented commands and developer habits keep working.

Run a check with the XAMPP PHP executable, for example:

```powershell
C:\xampp\php\php.exe scripts\verify-customer-auth.php
```

For the PostgreSQL Docker runtime, run the authentication regression checks:

```powershell
docker exec homas-test php tests/Integration/verify-login-throttle.php
docker exec homas-test php tests/Integration/verify-login-http.php
```

Use a container built from the current project files. These checks create randomly
named schemas and remove them in `finally`; they never use existing shop accounts.
The HTTP check runs a separate PHP server to verify both login forms, CSRF rejection,
authenticated landing pages, and logout. The other check covers failed-attempt limits
and safe upgrades of legacy password hashes. The configured PostgreSQL role needs
permission to create schemas and ICU collations.

Run the application journey check from the XAMPP project root:

```powershell
C:\xampp\php\php.exe scripts\verify-application.php
```

It checks literal imports, creates a randomly named MariaDB database from the
current migrations and reference seed, starts a local PHP server, and uses HTTP
requests to exercise customer signup, sign-in, catalogue search, cart, checkout,
account and owned order views, order confirmation, administrator sign-in, order review, and every public/admin
PHP entry point. It drops the test database and stops the server in `finally`.
It never writes customer or order fixtures to the development database. A
nonzero exit means a required journey, route, or bootstrap failed.
