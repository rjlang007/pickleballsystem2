-- Migration 023: admin TOTP and PayMongo top-up correlation.
-- Safe to re-run.
ALTER TABLE falcon.users
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64),
    ADD COLUMN IF NOT EXISTS totp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS totp_recovery_codes JSONB NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE falcon.topup_requests
    ADD COLUMN IF NOT EXISTS paymongo_payment_id VARCHAR(150),
    ADD COLUMN IF NOT EXISTS paymongo_auto_verified_at TIMESTAMPTZ;

CREATE UNIQUE INDEX IF NOT EXISTS uq_topup_paymongo_payment
    ON falcon.topup_requests(paymongo_payment_id)
    WHERE paymongo_payment_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_totp_enabled ON falcon.users(role, totp_enabled);
