# Google sign-in setup and maintenance

Google sign-in is implemented for customer accounts. It appears on sign-in and
signup screens only when a valid OAuth client is configured and the locked JWT
dependency is installed. Email/password sign-in continues to work independently.

The button uses Google's current official mark and a locally served Google Sans
Medium font subset for its three translations. The font's OFL license is included
under `public/assets/fonts/google-sans/`. Provider button colors/spacing follow
[Google's branding rules](https://developers.google.com/identity/branding-guidelines);
the surrounding interface keeps the Hom’As theme.

## Create your Google OAuth client

1. Open [Google Auth Platform](https://console.cloud.google.com/auth/overview).
   Create or select a project for Hom’As.
2. Configure Branding with the app name, support email and developer contact.
   Set the audience to External. While testing, add your own Google email as a
   test user. Request only the basic `openid`, `email` and `profile` scopes.
3. In Clients, create a client of type **Web application**.
4. Register this exact Authorized redirect URI for the current local website:

   ```text
   http://localhost/auth/google-callback.php
   ```

   This server redirect flow does not require an Authorized JavaScript origin.
   Google permits HTTP localhost callbacks for development. Production callbacks
   must use HTTPS and the deployed hostname. See
   [Google's web-server setup](https://developers.google.com/identity/protocols/oauth2/web-server).
5. Copy `app/config/google-auth.example.php` to
   `app/config/google-auth.local.php`. Enter your client ID and client secret in
   that private file; leave the redirect URI as above for local development.
   The file is ignored by Git and excluded from Docker image builds. Do not paste
   the secret into chat or put it in HTML/JavaScript.

When deploying under a prefix such as `/shop/`, the registered callback and
`APP_BASE_URL` must both include it: `/shop/auth/google-callback.php` and `/shop/`.
The callback is explicitly configured, never inferred from the request Host header.

## Activate in the running local container

The source file and the container are separate in this setup. After saving your
private client configuration, copy only that file:

```powershell
docker cp app/config/google-auth.local.php homas-test:/var/www/html/app/config/google-auth.local.php
docker exec homas-test php scripts/check-google-auth.php
```

The check prints configuration/dependency readiness and the callback URL, never
the client secret. All checks must pass before Google buttons appear.
The feature delivery applies migration 032 to the existing local database;
on a new installation, apply migrations with the normal migration command.

The equivalent environment variables are `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI`. Nonempty environment values take
precedence over the private file. A container's environment is set when it is
created; an interactive `export` in another process does not configure Apache.

The Dockerfile installs dependencies from `composer.lock`. A new standalone PHP
installation needs `composer install --no-dev --prefer-dist` with the declared PHP
extensions. Source sync deliberately stops when the running container has a
different dependency lockfile, before replacing application files.

## Customer journeys

### One browser origin for authentication

Session bootstraps normalize web requests to the origin of the explicitly configured
Google callback **before** starting a session or rendering a CSRF-protected form.
With the current private configuration this is `http://localhost`. Opening
`http://homas-candles.test/login.php` or its admin equivalent therefore redirects to
localhost first. These names reach the same Docker service but have different
browser cookies; mixing them previously lost OAuth state and produced expired forms.
The redirect destination comes from private configuration, never a request Host or
forwarding header. CLI commands are unaffected. GET/HEAD use temporary redirects;
POST uses 307 so its method/body cannot be silently rewritten to GET. A stale form
from a different host may still need a fresh page; do not bypass CSRF validation.

Customer timeout and logout remove customer authentication without destroying staff
authentication or a pending staff OAuth challenge. Google authorization starts also
leave the customer password form's CSRF token intact; staff Google/password forms
have independent tokens. Authentication itself still rotates the session ID.

The shared auth script restores disabled controls on `pagehide`/`pageshow`. When
Back/Forward restores a cached form, it reloads that safe GET once so the form and
CSRF token reflect the current session. This avoids a cached "signing in" screen
remaining disabled after a successful POST/redirect. Repeated sign-in/Back cycles
are exercised in a real Chromium browser against a disposable Apache preview on
desktop and mobile; the preview never uses shop accounts.

**New customer:** choose Continue with Google, approve Google's identity request,
then complete names, an eight-digit Tunisian phone number and the primary delivery
address. Name/email may be prefilled; email always comes from the verified Google
identity. No local password is requested. The server creates the account, required
address and Google link in one transaction and signs in only after commit.

**Returning linked customer:** Google signs them in directly to the existing account.
The stable Google subject is the login identity, even if their contact email later
changes in the Hom’As profile. Suspended accounts remain blocked.

**Existing email/password customer with Gmail or Google Workspace:** a fully
verified Google token proves current email ownership for Gmail, or Workspace when
its signed `hd` claim is present. The first Google sign-in connects the original
customer ID and opens the account directly. The old password is not requested.
Orders, addresses, profile details and the original password remain intact. Future
sign-ins use the stable Google subject, even after a contact-email change.

**Existing customer with another email provider:** `email_verified` alone does not
prove current ownership of an email Google does not manage. These customers sign
in with their existing credentials, then confirm the connection. Successful
explicit linking returns to the profile with a confirmation notice. See
[Google's email-ownership guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

Both paths reject suspended accounts and attempts to replace another linked Google
identity. Matching a domain string in submitted form data never authorizes linking;
the email and Workspace claim come exclusively from the verified signed token.

A new Google account has an unknowable random local password hash because the
existing schema requires a non-null hash. It is never shown or retained as plaintext.
An owner who wants email/password access can use the existing email password-reset
flow, provided mail delivery is configured.

## Security and code ownership

### Administrator Google sign-in

The admin login also offers **Continue with Google** when the same private client
is configured. It uses the existing registered `/auth/google-callback.php`; no new
Google Cloud client or redirect URI is required.

Administrator access is restricted to an **existing ACTIVE administrator's primary
email**. Google must prove current Gmail or Workspace ownership. A customer record,
secondary email, unknown address, suspended account or pending approval cannot
grant staff access. Other email providers continue to use the staff password form.
This feature never creates, approves or upgrades an administrator and never changes
a password or contact details. Email/active status are checked again atomically when
recording sign-in. Every protected page continues to enforce current staff status.

`GoogleOAuthSession` binds each authorization to a server-side customer or
administrator purpose. A non-authorizing `admin.` state prefix also preserves the
staff error destination when the session/state is missing. It cannot grant access:
each handler still consumes and verifies its own stored, one-use purpose. Staff sign-in uses
`AdministratorSession`; it never authenticates a customer account, even if the same
email exists in both tables. The ADMIN throttle is shared with password sign-in.

The signed-token integration suite covers active Gmail/Workspace staff, customer-only
and unknown addresses, suspension/pending approval, third-party email ownership,
purpose isolation, replay, cancellation, throttling, protected cookies and preservation
of staff passwords/profile/status. Live Google consent still requires the owner to
choose the Google account matching the existing administrator's primary email.

### Shared protocol and customer ownership

- `GoogleOAuthSession` owns one-use state, nonce, PKCE verifier and a ten-minute
  completion/link draft. A draft never creates an authenticated customer session.
- `GoogleOidcClient` sends the authorization code directly to Google's fixed HTTPS
  endpoint. The maintained `firebase/php-jwt` dependency verifies RS256 signatures
  using Google's public signing keys. Issuer, audience, authorized party, nonce,
  timestamps, subject and verified email are checked before using identity data.
- Public keys are cached privately with a bounded lifetime; an unknown key ID
  refreshes the cache. No access, refresh or identity tokens are persisted.
- `CurlJsonHttpClient` bounds response size/time, verifies TLS and refuses redirects
  and plaintext HTTP. Google network destinations are constants in the OIDC client.
- `GoogleCustomerService` owns registration/linking transactions and rechecks account
  status, email and any existing Google connection while holding the customer row
  lock. `GoogleIdentity` distinguishes Google-hosted email ownership from a verified
  third-party address. The shared `CustomerRegistrationValidator` keeps normal and
  Google registration on the same contact/address rules.
- `customer_google_identities` stores subject/customer mapping and link/use timestamps.
  The stable subject identifies subsequent sign-ins; stored link-time email is descriptive metadata.
- Public endpoints remain thin, use no-store/no-referrer headers and enforce HTTP
  methods. Authorization starts, completion saves and link confirmation require CSRF.
  Authentication rotates the session ID. Existing login throttling is reused.

When configuring production access logs, exclude callback query strings from logs:
the callback carries a short-lived authorization code. Application messages never
log codes, tokens, client secrets, claims or provider response bodies. See
[Google's OIDC guidance](https://developers.google.com/identity/openid-connect/openid-connect)
and the [JWT verifier](https://github.com/googleapis/php-jwt).

## Verification

```powershell
docker exec homas-test php tests/Unit/verify-google-oidc.php
docker exec homas-test php tests/Integration/verify-google-auth.php
docker exec homas-test php tests/Integration/verify-customer-auth.php
docker exec homas-test php tests/Integration/verify-login-http.php
docker exec homas-test php tests/Integration/verify-customer-order-management.php
```

The protocol suite uses locally signed RSA fixtures and an injected HTTP transport.
The integration suite uses a disposable PostgreSQL schema and a private CLI HTTP
router. Production has no setting that accepts fake tokens or alternate provider
endpoints. Browser review uses synthetic, server-created pending sessions in a
separate preview; it covers buttons, required details, mobile layouts, translations,
registration, cookie rotation and explicit linking.

The protocol and account/HTTP suites cover direct existing Gmail/Workspace sign-in,
preserved orders/addresses/passwords, third-party email ownership checks and blocked
account/link conflicts. The initial delivery also passed 39 browser checks plus
the existing registration/login/account regressions.
Actual Google consent and the registered-client exchange must also be tried with
your real configured client; synthetic tests do not claim to verify your Cloud setup.

Recovery copies and verification evidence are private under
`homas-candles-archive/storage-backups/google-sign-in-20261111/`. Migration 032 adds a new empty mapping
table without changing existing account/category rows or applied migration files.

## Preview screenshots

These screenshots use synthetic configuration and disposable customer data:

- [Google sign-in on mobile](google-sign-in-2026-11-11/login-google-390.png)
- [Required details on desktop](google-sign-in-2026-11-11/google-complete-1440.png)
- [Required details on mobile](google-sign-in-2026-11-11/google-complete-390.png)
- [Existing-account confirmation](google-sign-in-2026-11-11/google-link-390.png)
