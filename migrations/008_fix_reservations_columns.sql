-- ============================================================
--  MIGRATION: 008_fix_reservations_columns.sql
--  Adds columns the admin approval flow expects on reservations
--  but were missing from the base schema.
-- ============================================================

ALTER TABLE falcon.reservations ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP;
ALTER TABLE falcon.reservations ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES falcon.users(id);