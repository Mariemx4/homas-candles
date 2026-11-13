# Running and operating the shop

Operational notes for the real installation: runtime, deployment checks,
search indexing, database setup, sign-in protection, passwords, translation
generation, email and administrator access. To try the project locally, use
the demo in the main [README](../README.md) instead. The Docker set-up, backups
and recovery are in [local-docker.md](local-docker.md).

## PHP runtime and deployment preflight

The supported runtime is PHP 8.3/Apache with PostgreSQL 17 in Docker. The
Dockerfile installs the application extensions, PostgreSQL backup tools and locked
Composer dependencies. See [Google sign-in setup](google-sign-in.md) for the
private OAuth client configuration and required-address registration flow.

Run the checks in the same container that serves the website:

```powershell
docker exec homas-test php scripts/preflight.php
```

This checks PHP extensions, Argon2id, writable private storage, PostgreSQL
configuration/connection, the reference catalogue and the login-throttle key.
See [current Docker setup, backup and recovery](local-docker.md).

## Running the project locally

The current local website is served by `homas-test` at `http://localhost`.
PostgreSQL runs in `homas-postgres` on `homas-network`. Private configuration
and uploaded files must be preserved across container recreation.

Use the [Docker operations guide](local-docker.md) for configuration,
clean database setup, image builds, backups and recovery.

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

Copy `app/config/database.example.php` to `database.local.php` for a new
installation and enter its PostgreSQL credentials. Existing installations keep
their current private configuration. The connection uses UTF-8, native prepared
statements, exceptions and UTC timestamp storage.

For a new empty database:

```powershell
docker exec homas-test php scripts/migrate.php
docker exec homas-test php scripts/seed-database.php
```

The runner creates the ICU collation and applies checksummed migrations.
`app/database/reference.sql` supplies reference lookup data without fake
accounts or inventory. See [the current operations guide](local-docker.md).

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
rotation). The PHP build must have
Argon2id support; account creation and resets fail closed if it is missing.

## Product translation generation

Copy `app/config/translation.example.php` to the Git-ignored `app/config/translation.local.php`
and set `api_key` to your OpenAI API key. The default model is `gpt-4o-mini`.
Saving a complete product translation generates drafts for the other two languages.
Review and approve each draft in the product editor; only approved text is published
and counts toward activation. Missing configuration or API errors are shown as failed
drafts that can be regenerated after the issue is fixed. API usage is billed to the
OpenAI account associated with the key.

`app/database/reference.sql` contains reference data only. Demo products for the local `docker compose` demo are created by `docker/demo/seed-demo.php`, which refuses to run outside the demo database. Local SQL backups are written to `app/storage/backups` and ignored by Git.

## Password reset email delivery

Customer password resets use PHP's configured `mail()` transport. The application
creates a six-digit, ten-minute verification code, stores only its password hash,
and sends the plain code only to the customer's email address.

Before real emails can arrive:

1. Configure PHP with a working SMTP relay or `sendmail_path`.
2. Set `APP_MAIL_FROM` to a verified sender address, for example
   `no-reply@your-domain.tn`.
3. Restart Apache after changing PHP or environment configuration.
4. Test delivery to a real inbox, including its spam folder.

Do not place SMTP passwords in tracked PHP files. Keep provider credentials in
the server mail configuration or environment.

## Administrator access

Administrator and customer identities are deliberately separate. Administrators
sign in at `http://localhost/admin/login.php`; protected
admin pages accept only an active administrator session and re-check the account
status on every request. The configured admin-session fallback is 150 minutes of inactivity. The
sign-in page shows the effective duration. Set `ADMIN_SESSION_TIMEOUT` to a positive number of seconds to override
that value.

Administrator accounts are provisioned directly by an authorized database
operator; there is no public administrator signup route. Store passwords only as
Argon2id values produced through the application's PasswordPolicy.

For an existing active administrator in the local Docker setup, recover a lost
password from an interactive project terminal:

```powershell
docker exec -it homas-test php scripts/reset-admin-password.php
```

Enter the account's primary email or phone, then a new password twice. Password
entry is hidden. The command uses the application password policy (at least
eight characters, including a letter and number) and Argon2id hashing. It changes
only the selected account's password and never creates or reactivates accounts.
Passwords must not be passed as command-line arguments or committed to files.
