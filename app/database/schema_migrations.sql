-- WHAT: bookkeeping table that tracks which migration files have run.
-- WHY:  app/config/migrate.php reads/writes this table so it never re-runs
--       a migration twice, and can detect if an already-applied migration
--       file was edited afterward (via file_checksum) instead of silently
--       trusting it.
CREATE TABLE IF NOT EXISTS schema_migrations (
    -- e.g. '001', '002' — matches the numeric prefix of each migration filename
    version VARCHAR(20),
    migration_name VARCHAR(255) NOT NULL,
    -- SHA-256 of the migration file's contents at the time it was applied
    file_checksum VARCHAR(64) NOT NULL,
    date_applied TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,

    PRIMARY KEY (version)
);
