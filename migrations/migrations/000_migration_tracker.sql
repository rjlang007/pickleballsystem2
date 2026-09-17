-- ============================================================
--  MIGRATION: 000_migration_tracker.sql
--  Records which migration files have been applied, so future
--  runs never need to be re-run blind or guessed at again.
--  Safe to run any number of times.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.schema_migrations (
    id          SERIAL PRIMARY KEY,
    filename    VARCHAR(255) NOT NULL UNIQUE,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Backfill: mark every migration file that (per this conversation and the
-- \d falcon.topup_requests check) has already been successfully applied to
-- THIS database, so run_migrations.php won't try to re-run them.
INSERT INTO falcon.schema_migrations (filename) VALUES
    ('001_initial_schema.sql'),
    ('002_missing_tables.sql'),
    ('002_tournament_enhancement.sql'),
    ('003_patches.sql'),
    ('004_add_subscription_schema.sql'),
    ('005_fix_avatar_columns.sql'),
    ('005b_fix_users_columns.sql'),
    ('006_add_chat_rooms_schema.sql'),
    ('007_seed_training_shop.sql'),
    ('008_fix_reservations_columns.sql'),
    ('tournament_tables_patch.sql'),
    ('v2_multi_court.sql'),
    ('v2_fix_view_notx.sql'),
    ('v3_refresh_court_status_view.sql'),
    ('009_missing_columns_patch.sql')
ON CONFLICT (filename) DO NOTHING;
