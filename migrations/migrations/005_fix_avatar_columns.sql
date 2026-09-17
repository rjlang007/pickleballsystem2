-- ============================================================
--  MIGRATION: 005b_fix_users_columns.sql
--  Adds columns referenced throughout the app (login, admin
--  player management) that were missing from the base schema.
-- ============================================================

ALTER TABLE falcon.users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE falcon.users ADD COLUMN IF NOT EXISTS is_verified BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE falcon.users ADD COLUMN IF NOT EXISTS ban_reason VARCHAR(255);