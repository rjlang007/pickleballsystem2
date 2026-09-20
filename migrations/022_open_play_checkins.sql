-- Migration 022: Open Play per-player match confirmation and no-show handling.
-- Safe to re-run.

ALTER TABLE falcon.open_play_matches
    ADD COLUMN IF NOT EXISTS called_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS no_show_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS falcon.open_play_match_checkins (
    match_id INTEGER NOT NULL REFERENCES falcon.open_play_matches(id) ON DELETE CASCADE,
    player_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    confirmed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (match_id, player_id)
);

CREATE INDEX IF NOT EXISTS idx_opmc_match ON falcon.open_play_match_checkins(match_id);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON falcon.open_play_match_checkins TO falcon_app;
    END IF;
END $$;
