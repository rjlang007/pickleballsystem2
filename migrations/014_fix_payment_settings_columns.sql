-- ============================================================
--  MIGRATION: 014_fix_payment_settings_columns.sql
--  admin/payment_settings.php reads/writes method_name, account_name,
--  account_number, qr_image, instructions, and sort_order on
--  falcon.payment_settings, but no earlier migration ever added
--  those columns (001_initial_schema.sql only created a generic
--  key/value table). This patches the table to match what the
--  application code actually expects.
--
--  Safe to re-run: every statement is guarded with IF NOT EXISTS.
-- ============================================================

ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS method_name    VARCHAR(100);
ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS account_name   VARCHAR(150);
ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS account_number VARCHAR(100);
ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS qr_image       VARCHAR(255);
ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS instructions   VARCHAR(1000);
ALTER TABLE falcon.payment_settings ADD COLUMN IF NOT EXISTS sort_order     INTEGER NOT NULL DEFAULT 0;

-- Backfill sane defaults for any legacy key/value rows so old data
-- (if present) doesn't leave the new columns null where NOT NULL matters.
UPDATE falcon.payment_settings
   SET sort_order = 0
 WHERE sort_order IS NULL;
