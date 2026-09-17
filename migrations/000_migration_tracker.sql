-- ============================================================
--  MIGRATION: 000_migration_tracker.sql
--  Records which migration files have been applied, so future
--  runs never need to be re-run blind or guessed at again.
--  Safe to run any number of times.
-- ============================================================

CREATE SCHEMA IF NOT EXISTS falcon;

CREATE TABLE IF NOT EXISTS falcon.schema_migrations (
    id          SERIAL PRIMARY KEY,
    filename    VARCHAR(255) NOT NULL UNIQUE,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Do not pre-populate this table. On a fresh Railway database the migration
-- runner must apply the complete schema; existing databases retain their
-- already-recorded rows and therefore remain safe to re-run.
