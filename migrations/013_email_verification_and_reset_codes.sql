-- ============================================================
--  MIGRATION 013: Email verification + code-based password reset
--
--  Adds:
--   - falcon.email_verifications      (6-digit codes for new registrations)
--   - falcon.password_reset_codes     (6-digit codes for "Forgot Password")
--
--  Also repairs falcon.password_resets so its columns match what the
--  application already expects (token_hash / used_at) — the original
--  001 migration only created token/expires_at/created_at.
-- ============================================================

-- ── Repair legacy password_resets table (kept for reference/back-compat) ──
ALTER TABLE falcon.password_resets ADD COLUMN IF NOT EXISTS token_hash VARCHAR(255);
ALTER TABLE falcon.password_resets ADD COLUMN IF NOT EXISTS used_at TIMESTAMP;
CREATE UNIQUE INDEX IF NOT EXISTS idx_password_resets_token_hash ON falcon.password_resets (token_hash);

-- ── Email verification codes ───────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.email_verifications (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    code_hash   VARCHAR(255) NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 0,
    expires_at  TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_email_verifications_user_id ON falcon.email_verifications (user_id);
CREATE INDEX IF NOT EXISTS idx_email_verifications_pending
    ON falcon.email_verifications (user_id) WHERE consumed_at IS NULL;

-- ── Password reset codes ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.password_reset_codes (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    code_hash   VARCHAR(255) NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 0,
    expires_at  TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_password_reset_codes_user_id ON falcon.password_reset_codes (user_id);
CREATE INDEX IF NOT EXISTS idx_password_reset_codes_pending
    ON falcon.password_reset_codes (user_id) WHERE consumed_at IS NULL;

-- ── Fix broken "log out everywhere" on password reset ────────────
-- reset_password.php previously deleted from a falcon.user_sessions
-- table that never existed (the real session table is php_sessions,
-- keyed only by session id with no user_id column) — meaning a
-- password reset never actually invalidated the player's other
-- logged-in sessions, and depending on transaction handling could
-- throw and roll back the whole reset. Add the missing column so
-- "force logout all sessions" actually works.
ALTER TABLE falcon.php_sessions ADD COLUMN IF NOT EXISTS user_id INTEGER;
CREATE INDEX IF NOT EXISTS idx_php_sessions_user_id ON falcon.php_sessions (user_id);

-- ── Email ownership confirmation ───────────────────────────────
-- NOTE: this is intentionally a SEPARATE column from is_verified.
-- is_verified is the existing admin-side "Verify Player" flag (staff
-- manually confirming a walk-in player's identity at the counter).
-- email_verified is the new self-service flag set when a player
-- enters the 6-digit code we emailed them. Keeping them separate
-- avoids silently changing the behavior of the existing admin
-- verification workflow in admin/players.php.
ALTER TABLE falcon.users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN NOT NULL DEFAULT FALSE;

-- Existing accounts (registered before this migration) already went
-- through the old "auto-verified on signup" flow, so grandfather them
-- in rather than locking everyone out of login at once.
UPDATE falcon.users SET email_verified = TRUE WHERE email_verified = FALSE AND email IS NOT NULL AND email != '';
