# Hom'As Candles — behavior-preserving refactoring brief

You are working on my existing Hom'As Candles project. Refactor the existing code
into a clear, maintainable, well-documented architecture that a beginner can
understand. This is an implementation task, not just a request for advice.

The website is currently working. Preserve that working baseline. Previous work
introduced authentication regressions and overstated how complete the cleanup was.
I need evidence, careful changes, and honest reporting rather than reassurance.

## 1. Scope and non-negotiable requirements

- Improve structure, readability, responsibilities, meaningful reuse and documentation.
- Preserve the website's appearance, Hom'As identity, features, business rules,
  public URLs, data and existing accounts. Do not redesign the product or invent
  new workflows while doing this refactor.
- Do not replace the stack, introduce a framework, or rewrite the application from
  scratch. Introduce dependencies only when genuinely necessary and justified.
- Do not reset, reseed, truncate or clean the real database. An earlier historical
  request to reset demo data is NOT authorization to repeat it now.
- Do not change passwords, account emails, approval statuses, Google credentials,
  session lifetimes, pricing, stock rules or permissions as a cleanup shortcut.
- Read applicable AGENTS.md/CLAUDE.md instructions and inspect the actual repository.
  Treat existing architecture documents as descriptions to verify, not proof that
  the implementation already follows them.
- Preserve unrelated existing edits. Inspect Git status before touching files.
  Do not reset, clean, discard changes, or make commits unless I request that.
- Do not ask for approval for every ordinary refactor. Work through small,
  reversible, verified changes. Ask only when information or authorization is
  genuinely missing; explain the specific reason.

## 2. Project and runtime context

- Workspace: `C:\Users\Mariem\Desktop\homas-candles` on Windows/PowerShell.
- PHP 8.3/Apache, PostgreSQL 17, plain JavaScript, HTML and CSS. No frontend build
  step is required by the website.
- PHP uses the `App\` namespace, PSR-4 paths under `src/`, Composer dependencies
  and a small application autoloader in `app/Bootstrap/autoload.php`.
- `composer.lock` locks the maintained JWT verifier used for Google sign-in.
  Preserve the lockfile and signature verification; do not replace it with custom
  JWT cryptography.
- The running website uses Docker container `homas-test`, PostgreSQL uses
  `homas-postgres`, and Adminer uses `homas-adminer`, on `homas-network`.
- The configured authentication origin is currently `http://localhost`.
  `homas-candles.test` is another local address, normalized to the configured origin
  before session creation. Verify the actual configuration; do not change it casually.
- Apache's document root is `public/`. Application/configuration/storage directories
  must remain outside the publicly served root.
- The running container currently has copied source rather than a source bind mount.
  Verify this before deployment. Editing workspace files alone does not necessarily
  update the running website.
- Existing Docker image tags can predate later source fixes. Do not recreate the
  website from an old image and silently lose newer code or private runtime files.
- Preserve English/French/Arabic and RTL behavior, Tunisian phone/address validation,
  TND precision, UTC storage and the existing Tunisia business-date rules. Read the
  relevant database/business documentation and code before changing those areas.
  Preserve database constraints, stock/deposit/payment calculations and audit history.

Read first:

1. `README.md`
2. `docs/architecture.md`
3. `docs/beginner-handbook.md`
4. `docs/routing.md`
5. `docs/local-docker.md`
6. `docs/google-sign-in.md`
7. `tests/README.md`
8. `.editorconfig`, `.php-cs-fixer.dist.php`, `.prettierrc.json`, `.gitignore`,
   `.dockerignore`, `composer.json` and `composer.lock`

Consult `docs/database-requirements.md` and
`docs/admin-dashboard/ui-maintenance.md` for the affected business/UI contracts.

## 3. What better architecture means here

Do not judge architecture by an arbitrary maximum number of files per folder.
Fifteen or twenty related files can be perfectly reasonable. Improve discoverability
and ownership rather than creating needless directory levels.

Audit the whole application, then choose a consistent, proportionate organization.
Keep HTTP handling, business rules, persistence and presentation clearly separated.
Group related business areas where that makes navigation easier. Explain the chosen
rules so a beginner knows where a future change belongs.

Investigate these concrete candidates, without assuming that line count alone proves
they must be split:

- `src/Services/AdminProductService.php`: roughly 1,000 lines covering several
  product workflows, including editing, translations, images and configuration.
- `src/Services/CustomRequestService.php`: several request-management responsibilities.
- `src/Services/`: a broad flat collection of services from multiple business areas.
- `public/assets/js/admin/workspace.js`: many shared admin interactions in one file.
- Large CSS files, including admin workspace and customer-account styles.

Extract cohesive responsibilities and shared behavior when their ownership really
matches. Keep classes/functions focused and names descriptive. Do not create a class
for every tiny expression or merge unrelated business rules merely because their
code looks similar. Avoid a large new abstraction layer that makes the project harder
for a beginner to follow.

Keep controllers thin and repositories responsible for SQL. Preserve prepared
statements, transaction boundaries, concurrency checks and server-side validation.
Templates should render prepared data rather than introduce hidden database queries.
Use shared components, validation and formatting where appropriate.

## 4. Comments and documentation

Write documentation like a senior handing the project to a beginner:

- Explain purpose, ownership, important inputs/outputs, invariants, side effects,
  transaction boundaries, failure behavior and security decisions.
- Explain WHY a non-obvious decision exists. Remove repetitive comments that only
  restate a constructor, assignment or obvious line of code.
- Use useful PHPDoc/types and concrete examples where they improve understanding.
  Do not bury readable code under a comment on every line.
- Document the actual final architecture, setup, configuration, routing, test and
  deployment procedures. Update old instructions that the refactor makes inaccurate.
- Include a short beginner guide: where to change a business rule, add a route,
  adjust a reusable component/style, run checks and recover a failed deployment.
- Keep one clear current explanation rather than copying conflicting instructions
  into many documents. Preserve useful historical records as history.

## 5. CSS, JavaScript and visual preservation

- Preserve the existing colors, typography, icons, spacing and responsive behavior.
- Reuse existing semantic CSS variables when their purpose and value match. Avoid
  equivalent new variables or repeated raw values for an existing token.
- Do not replace legitimately distinct colors/roles just because they are similar.
- Reuse component styles. Consolidate genuine duplication and proven dead rules
  while preserving import order, specificity, media queries, RTL and interaction states.
- Preserve Google's official button mark, font and provider-specific styling. These
  are intentional branding requirements, not accidental Hom'As color duplication.
- Preserve DOM hooks, IDs, data attributes, form field names and JavaScript event
  contracts, or update every consumer and test them together.
- Do not break keyboard access, mobile touch targets, loading/error states or
  browser Back/Forward navigation.

## 6. Routing and moves/renames

When moving or renaming anything, update every affected reference in the same change:

- Namespaces, class names, imports and both autoloading paths; regenerate Composer
  autoload files when required.
- PHP includes/requires, bootstrap wiring, view paths and component references.
- Form actions, redirects, query strings, route parameters, HTTP method handling and
  POST/Redirect/GET behavior.
- JavaScript imports/selectors, CSS imports, image/font URLs and asset versioning.
- Tests, scripts, deployment/sync code and documentation.

Preserve existing public URLs wherever possible. Moving an internal implementation
does not require changing its browser URL. Keep a thin compatible entry point when
appropriate. Do not use a redirect that loses POST data or bypasses authorization.
Keep filesystem paths separate from browser URLs and preserve subpath support.

Verify both direct navigation and navigation through links/forms. A successful
homepage load does not prove routing is correct.

## 7. Authentication regression requirements

These flows were recently repaired and are working. Preserve and test them explicitly:

- Administrator email/password login and logout.
- Customer email/phone and password login, signup, logout and recovery flows.
- Customer Google signup requires phone and a complete delivery address before
  creating/authenticating the customer; returning customers reuse their account.
- Verified Gmail/Workspace ownership can match an existing customer. Other email
  providers require the existing explicit ownership/linking safeguards.
- Administrator Google sign-in accepts only an existing ACTIVE administrator's
  primary email with verified Google-managed ownership. It must never register,
  approve or promote an administrator. Customer records confer no staff access.
- Admin and customer accounts remain separate even when the same email appears in
  both tables. Do not merge them or make an admin password work for an unrelated
  customer account.
- Preserve signed-token checks, state, nonce, PKCE, CSRF, session ID rotation,
  cookie protections, throttling and suspended/pending-account checks.
- The registered callback remains `/auth/google-callback.php`. Each handler verifies
  its stored one-use purpose. The `admin.` prefix only keeps lost/expired admin-state
  errors on the staff login page; the prefix itself grants no authentication.
- Keep authentication on one configured origin. Different local hostnames do not
  share browser cookies. Never choose a trusted redirect origin from a request's Host
  or forwarding headers.
- Customer timeout/logout must not destroy independent staff authentication or an
  in-progress staff Google flow.
- Starting Google must not invalidate the other password form. Administrator Google
  and password forms have independent CSRF tokens.
- Cached sign-in pages must not retain a disabled submit button or endless loading
  state. Preserve the `pagehide`/`pageshow` recovery in `public/assets/js/auth.js` and
  fresh-form handling on Back/Forward restoration.

Exercise repeat sign-in/Back/sign-in cycles, switching between customer and admin
pages, Google cancellation, password login after returning from Google, and timeout
during an admin Google callback. Test desktop and mobile. Do not simplify away these
protections to make a happy-path test pass.

## 8. Data, secrets, deletion and rollback

- Preserve ALL current real records, uploads and private configuration. Current
  customer accounts are valid data, not disposable test records.
- Never print, paste into reports, commit or move secrets into example files. Protect
  `app/config/*.local.php`, environment files, database/OAuth/throttle credentials,
  private logs, payment evidence and backups.
- Keep `app/storage/`, `public/uploads/` and generated files under
  `public/assets/products/` intact. Follow the actual ignore/build rules.
- Do not edit or remove previously applied SQL migrations. A code-only refactor
  should not require schema changes; explain any genuinely unavoidable exception
  before making it.
- Back up affected source and private runtime data before deployment. Keep backups
  outside `public/`; do not leave backup PHP files as accessible public endpoints.
- Delete only files proven unnecessary. Check dynamic includes, external URLs,
  autoloading, deployment and maintenance uses—not just one text search. An unused
  route in the UI may still be an externally used URL.
- Do not delete migrations, tests, documentation or recovery tools solely because
  they are not requested by a normal page.
- Record actual deleted/moved files, their reasons and replacement locations.

## 9. Work and verification process

1. Inspect the real project, instructions, Git state, runtime and database isolation
   rules. Establish a working baseline and identify concrete architectural problems.
2. Present a brief, reasoned refactoring plan with the proposed organization and
   preserved interfaces. Then implement it; do not stop at a plan or formatting pass.
3. Refactor in small coherent steps. Fix all callers and run appropriate checks after
   each step. If a regression appears, fix or roll back that step before expanding it.
4. Use disposable schemas/databases, synthetic accounts and isolated previews for
   mutation/browser tests. Never use my real account as a fixture or seed the shop.
5. Follow `tests/README.md` and the tests' isolation requirements. Useful checks include:

   ```powershell
   docker exec homas-test php scripts/preflight.php
   docker exec homas-test php scripts/check-project.php
   docker exec homas-test php tests/Unit/verify-canonical-origin.php
   docker exec homas-test php tests/Unit/verify-google-oidc.php
   docker exec homas-test php tests/Integration/verify-customer-auth.php
   docker exec homas-test php tests/Integration/verify-login-http.php
   docker exec homas-test php tests/Integration/verify-google-auth.php
   docker exec homas-test php tests/Integration/verify-customer-order-management.php
   node scripts/sync-local.js --dry-run
   ```

   Choose the additional catalogue/order/payment/inventory/production/admin suites
   appropriate to the affected areas. Preserve or update test entry points if moved.
   Run PHP/JavaScript syntax checks and real browser checks where behavior can change.

6. Verify a fresh build/preview as well as the intended deployment. Old files left in
   a container can hide broken moves and imports. Do not assume an old image contains
   the latest authentication repairs.
7. Follow `docs/local-docker.md` for deployment. Source sync does NOT remove obsolete
   container files, install dependencies, apply Dockerfile changes or run migrations.
   Reconcile moved/deleted source explicitly and safely after backup; preserve private
   configuration, uploads and data. Verify the running site uses the tested files.
8. Remove only the temporary containers/schemas/profiles/files created for your tests.
   Keep useful verification evidence and private recovery copies.

Do not claim tests were run when they were not. Passing tests supports the behavior
they cover; it does not prove architectural quality or every possible website flow.

## 10. Completion and reporting

Finish the refactor and its required checks. Report concisely but concretely:

- The architectural problems actually found and what changed to address them.
- The final folder/responsibility rules and an old-to-new map of moved files/classes.
- Exactly which files were deleted and why, if any.
- Meaningful reuse introduced and responsibilities extracted, beyond formatting.
- Documentation produced/updated and the beginner starting point.
- Checks actually run, their results, actual browser coverage and any unverified areas.
- How the updated source was applied to Docker and how to recover it.
- Any remaining architectural debt or blocker, without hiding unfinished work.

Do not declare the entire project clean, fully documented, regression-free or perfect
without evidence. Do not silently narrow the task to a few files and describe that as
a complete cleanup. The goal is a readable, understandable, maintainable project
whose existing behavior keeps working—not a cosmetically impressive folder tree.
