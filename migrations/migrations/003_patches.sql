-- ============================================================
--  FILE: migrations/003_patches.sql
--  Run after 002_missing_tables.sql.
--  Fixes:
--   1. site_content UNIQUE(section, key) — required by auto_end_games.php
--      ON CONFLICT upsert. Add only if it doesn't exist already.
--   2. Verify game_sessions.credit_cost column exists — add if missing.
--   3. Add community_posts ORDER index for pinned-first feed.
-- ============================================================

-- ── 1. site_content unique constraint ─────────────────────────
-- auto_end_games.php does:
--   INSERT ... ON CONFLICT (section, key) DO UPDATE ...
-- This requires a unique constraint (or unique index) on (section, key).
-- Silently skips if the constraint already exists.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conrelid = 'falcon.site_content'::regclass
           AND contype  = 'u'
           AND conname  = 'uq_site_content_section_key'
    ) THEN
        ALTER TABLE falcon.site_content
            ADD CONSTRAINT uq_site_content_section_key UNIQUE (section, key);
    END IF;
END
$$;

-- ── 2. game_sessions.credit_cost column ───────────────────────
-- auto_end_games.php SELECTs gs.credit_cost from game_sessions.
-- Add the column if it wasn't in the original schema.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'falcon'
           AND table_name   = 'game_sessions'
           AND column_name  = 'credit_cost'
    ) THEN
        ALTER TABLE falcon.game_sessions
            ADD COLUMN credit_cost NUMERIC(10,2) NOT NULL DEFAULT 10.00;

        COMMENT ON COLUMN falcon.game_sessions.credit_cost IS
            'Credits charged per player per session; copied from courts.credit_cost at session start.';
    END IF;
END
$$;

-- ── 3. Community posts feed index ─────────────────────────────
-- Supports ORDER BY is_pinned DESC, created_at DESC efficiently.
CREATE INDEX IF NOT EXISTS idx_community_posts_feed
    ON falcon.community_posts (is_pinned DESC, created_at DESC)
    WHERE is_hidden = FALSE;

-- ── 4. Withdrawal requests — pending lookup index ─────────────
-- api/wallet.php checks for existing pending requests per user.
CREATE INDEX IF NOT EXISTS idx_withdrawal_pending
    ON falcon.withdrawal_requests (user_id, status)
    WHERE status = 'pending';