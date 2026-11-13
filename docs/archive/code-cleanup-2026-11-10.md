# Code and database cleanup — 10 November 2026

This maintenance pass preserves the website's screens, brand, workflows and
public URLs. It reorganizes the implementation and removes obsolete code.

## Database outcome

The local `homas_candles` database retains Mariem's existing administrator
account, six categories and their eighteen translations. The account password
is unchanged. Three language records and thirty-one migration records remain
because the application needs this infrastructure to function.

The other thirty-six application tables were cleared in one transaction.
Products, product types, customer accounts, materials, orders, payments and
activity history are empty. No reference-data seed was run against this cleaned
database. Full-row hashes of the retained tables matched before and after reset.

Before clearing data, a PostgreSQL archive was created and restored into an
isolated database; all forty-one table fingerprints matched. The private archive
is `app/storage/backups/homas_candles_20261110_143549_3c94d6f7.dump`, accompanied
by metadata and a restore receipt. Backups contain private data: keep them out
of Git and outside the public document root.

## Code changes

- Admin views now live under `app/Views/Pages/admin/<feature>/`.
- Admin JavaScript lives under `public/assets/js/admin/`; CSS is grouped into
  tokens, layout, components and pages under `public/assets/css/admin/`.
- `App\View\Presentation`, `AdminIcons` and `AdminNavigation` own repeated
  formatting, icon markup and navigation definitions.
- `App\Http\AdminRecordNavigation` owns record destinations;
  `LegacyCategoryRedirect` preserves the four old category URL adapters.
- `VIEW_PATH` anchors PHP view includes independently of the caller's folder.
- The workspace script has named initialization functions and comments explaining
  its DOM, storage and interaction contracts.
- Existing CSS tokens replace matching literal values. Provably overridden
  declarations, duplicated rules and unused loading/dashboard styles were removed.
- Obsolete fragments, empty legacy view folders and three accidental root files
  were removed. Applied SQL migrations were preserved byte for byte.
- Formatter settings, editor conventions and a read-only project checker make
  future changes easier to review.

This is a feature-based cleanup within the existing PHP architecture. Existing
HTTP entry points remain in `public/`; moving an internal view does not create
or rename a public route. See [the route inventory](../routing.md).

## Verification evidence

The refactored source passed:

- 726 integration assertions, using isolated databases or schemas;
- 199 authenticated browser checks across phone, tablet and desktop sizes;
- 32 storefront and legacy redirect checks;
- four focused JavaScript browser fixtures;
- 464 structural checks for view includes, asset references, CSS imports and
  PHP class locations;
- syntax checks for all 420 PHP files under PHP 8.3.36.

After deployment to `homas-test`, the actual PHP 8.3.35 runtime also passed all
420 syntax checks, all 464 structural checks and its deployment preflight.
All thirty-one migrations were skipped with their checksums intact. Twenty-one
read-only localhost HTTP checks covered storefront pages, admin authentication,
moved assets and legacy redirects. The retained table fingerprints were checked
again after deployment.

A second archive of the cleaned database,
`homas_candles_20261110_161558_110303da.dump`, also passed a full forty-one-table
restore verification. Both database archives and the runtime rollback snapshot
were copied to the private host recovery folder. The disposable browser schema
and all three diagnostic containers were removed; the working site remains in
`homas-test`.

Twenty admin screenshots were byte-for-byte identical before and after the
refactor. Remaining differences were confined to generated fixture identifiers,
timestamps and native overlay scrollbars; the affected images were inspected.
These checks establish the tested behavior; they are not a guarantee against
every future browser, data combination or configuration error.

## Recovery and handoff

A full source snapshot from before this pass, its SHA-256 inventory, both database
archives, the runtime rollback snapshot and verification evidence are retained in
the private, Git-ignored `app/storage/backups/code-cleanup-20261110/` folder on the
host. The diagnostic working folder also remains at
`%TEMP%\homas-code-cleanup-20261110`. A separate runtime file snapshot is retained
in the running container before source replacement.
The container snapshot alone will not survive removing that container.

Never restore a database archive merely to undo a CSS or PHP change. Restore
source and data independently, and follow [the Docker operations guide](../local-docker.md)
for database recovery. Do not run a seed script to repopulate this intentionally
empty working database.

For a new contributor, read [the beginner handbook](../beginner-handbook.md),
[architecture](../architecture.md), [routing](../routing.md), then
[the test guide](../../tests/README.md). Comments explain ownership, contracts and
non-obvious decisions; they should stay synchronized with behavior rather than
repeat each line of PHP.
