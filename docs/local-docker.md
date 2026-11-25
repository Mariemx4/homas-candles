# Current local Docker operations

Google sign-in adds the locked Composer JWT dependency. The Dockerfile installs
it automatically. After introducing/changing dependencies, rebuild the image or
install its lockfile before using source sync; the sync script checks for a matching
installed lockfile before overwriting code. Private Google configuration is copied
separately as described in [Google sign-in setup](google-sign-in.md).

The supported runtime is PHP 8.3/Apache with PostgreSQL 17. The Dockerfile
installs the application PHP extensions, PDO SQLite for isolated unit checks,
and PostgreSQL 17 client tools. The web document root is `public/`.

The existing local installation uses `homas-test` and `homas-postgres` on
`homas-network`, with the website at `http://localhost`. Its existing accounts,
private configuration, uploads and database are retained.

## Saving and applying all project code

In VS Code, use **File → Save All** (Ctrl+K, then S). To apply all saved code to
the current local website, run this from the project folder on your computer:

```powershell
node scripts/sync-local.js
```

Then refresh the browser with Ctrl+F5. The command requires Docker and Node.js
(already available in this development environment). `--dry-run` previews the
file count without changing the container. It preserves private `*.local.php`
configuration, runtime storage, uploaded product images and payment evidence.
It keeps a source rollback archive in the container's `/tmp` directory before
copying code. It mirrors the listed source directories and removes source files
that no longer exist in the project, so deleted routes do not survive in the
container, and regenerates Composer's class map. It does not run migrations,
install dependencies or apply Dockerfile changes; those require their separate
setup steps. Editor saving and syncing code
do not replace database/upload backups.

## Rolling back a source sync

Each sync prints `Previous source backup: homas-test:/tmp/homas-source-before-sync-….tar.gz`.
That archive holds the code that was running before the sync (private
configuration, uploads and product images are excluded and are not touched).
To return to it, use the exact path that was printed:

```powershell
docker exec homas-test tar -xzf /tmp/homas-source-before-sync-TIMESTAMP.tar.gz -C /var/www/html
```

Extracting the archive restores deleted files but does not remove files added
by the newer version. For a complete rollback, restore the matching source
checkout and run source sync again. The `/tmp` archive is lost if the container is recreated, so also
copy it out (`docker cp homas-test:/tmp/… app/storage/backups/`) after an
important deployment.

## Configuration and checks

`app/config/database.local.php` contains the private PostgreSQL connection.
`database.example.php` is a template; inside Docker its host is the database
container name. Configure the stable login-throttle key through
`LOGIN_THROTTLE_KEY` or `app/config/login-throttle.local.php` as described by
`login-throttle.example.php`. Preserve other local payment, translation and
mail configuration when rebuilding. Private local configuration and runtime
storage are excluded from the build context.

```powershell
docker exec homas-test php scripts/preflight.php
```

`docker exec` runs as root, which can write anywhere, so preflight then checks
the runtime directories against the permissions of the web user (`www-data`,
override with `HOMAS_WEB_USER`). A FAIL there means Apache cannot save logs or
uploads, even though root can.

For a newly provisioned empty PostgreSQL database, apply the schema and
reference catalogue data:

```powershell
docker exec homas-test php scripts/migrate.php
docker exec homas-test php scripts/seed-database.php
```

The migration runner creates the ICU collation and tracks migration checksums.
Reference data is in `app/database/reference.sql`; it contains languages,
catalogue taxonomy and lookup data, without fake staff, products or inventory.
Seeding is idempotent. Initial administrator provisioning remains an authorized
database-operator task. Existing installations retain their administrators;
staff can add further administrators through Team & access.

## Backup and restore checking

```powershell
docker exec homas-test php scripts/backup-database.php --restore-check
```

This creates a PostgreSQL custom-format `.dump` in private
`app/storage/backups`, plus a checksum/table-digest `.json` file. The dump and
digests use the same exported database snapshot. Verification restores into a
new randomly named `homas_candles_restore_check_…` database, compares every table's
row count and digest, and removes that temporary database. It does not restore
over the shop. A matching `.restore.json` receipt drives the status in Settings.

To recheck an existing archive:

```powershell
docker exec homas-test php scripts/backup-database.php --verify=/var/www/html/app/storage/backups/ARCHIVE.dump
```

Use the actual archive filename. A changed archive loses its verified status.
Keep the dump, its metadata and receipt together. Export additional copies
outside the container/computer according to the shop's backup schedule.
Database archives do not include uploaded files: also preserve
`app/storage/uploads/payments` and `public/assets/products`, along with private
configuration. Runtime backups and proof files must stay outside the public
web root.

## Recovery into a separate database

Use a new empty recovery database and keep the current shop intact while
checking the recovery. The following is an operator procedure; it is not run
by the website. Choose the database role and an unused recovery database name:

```powershell
$databaseRole = 'your_database_role'
$recoveryDatabase = 'homas_candles_recovered_20261110'
docker cp homas-test:/var/www/html/app/storage/backups/ARCHIVE.dump ./recovery.dump
docker cp ./recovery.dump homas-postgres:/tmp/homas-recovery.dump
docker exec -it homas-postgres createdb -U $databaseRole $recoveryDatabase
docker exec -it homas-postgres pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error --single-transaction -U $databaseRole --dbname=$recoveryDatabase /tmp/homas-recovery.dump
```

Substitute the actual archive filename and role. The clean option above applies
only to the **new recovery database**. Verify restored data and the matching
uploaded files, then configure the application to use the checked recovery
database. Keep recovered archives and temporary copies private. The older
MySQL/XAMPP rebuild instructions are not the PostgreSQL recovery procedure.

## Building the application image

```powershell
docker build -t homas-candles:admin-final-20261110 .
```

A fresh container needs its private `app/config/*.local.php` configuration
provided separately. Persist `app/storage` and `public/assets/products` across
container recreation, and export those directories from an existing container
before replacing it. Building an image does not copy the current container's
runtime data. The updated local site is already running; rebuilding is not
needed just to view the changes.

## Calendar and account history

Business dates, booking windows, promotion validity and daily quotas follow
`Africa/Tunis`. Stored timestamps remain UTC. Report-date boundaries and local
follow-up/payment time inputs are converted explicitly. Existing timestamps
were preserved.

Account history starts at migration 031. Profile edits, administrator-created
accounts, access changes and permitted deletion record the acting account and
safe before/after values. Credentials are excluded; history is append only and
written in the same transaction as the change. Older events are not fabricated.

The backup and timezone implementation follows PostgreSQL's documentation for
[pg_dump snapshots](https://www.postgresql.org/docs/17/app-pgdump.html),
[pg_restore](https://www.postgresql.org/docs/17/app-pgrestore.html), and
[time-zone conversion](https://www.postgresql.org/docs/17/functions-datetime.html).
