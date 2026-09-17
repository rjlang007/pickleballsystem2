-- ============================================================
--  MIGRATION: 015_announcements.sql
--  Adds falcon.announcements — club-wide announcements that
--  admin/super_admin can post and every logged-in account
--  (players, staff, referees, admins) sees on the homepage and
--  in their navbar banner.
--
--  Safe to re-run: guarded with IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.announcements (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(200) NOT NULL,
    body         TEXT         NOT NULL,
    audience     VARCHAR(20)  NOT NULL DEFAULT 'all'
                     CHECK (audience IN ('all','player','staff','referee','admin')),
    is_pinned    BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by   INTEGER      REFERENCES falcon.users(id) ON DELETE SET NULL,
    starts_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    expires_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_announcements_active
    ON falcon.announcements (is_active, is_pinned, starts_at);

CREATE INDEX IF NOT EXISTS idx_announcements_audience
    ON falcon.announcements (audience);
