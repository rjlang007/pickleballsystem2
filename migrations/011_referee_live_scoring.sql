-- ============================================================
--  MIGRATION: 011_referee_live_scoring.sql
--  Supports the referee live scoring form (referee/score_match.php):
--   - serving_player / serving_side on tournament_matches, so the
--     kiosk/standings can show who's serving without re-deriving it
--   - tournament_match_events: an append-only per-point/correction
--     log, so serve/position history is recoverable and a referee
--     correcting a score after the fact is logged, not silently
--     overwritten (also lets an in-progress match survive a
--     referee device disconnect/refresh with no state lost).
--  Safe to re-run.
-- ============================================================

ALTER TABLE falcon.tournament_matches
    ADD COLUMN IF NOT EXISTS serving_player SMALLINT
        CHECK (serving_player IN (1, 2)),
    ADD COLUMN IF NOT EXISTS serving_side VARCHAR(10) NOT NULL DEFAULT 'right'
        CHECK (serving_side IN ('left', 'right'));

-- Pre-existing bug fix: tournament_tables_patch.sql created a
-- trg_tournament_matches_updated_at trigger that sets NEW.updated_at
-- on every UPDATE, but no migration ever added that column — so any
-- UPDATE to this table (recordMatchResult, and every live-scoring
-- write below) fails with "record 'new' has no field 'updated_at'".
ALTER TABLE falcon.tournament_matches
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- Migration 010 may have created this table first without tournament_id.
-- Keep the compatibility column nullable until migration 027 can backfill it.
ALTER TABLE falcon.tournament_match_events
    ADD COLUMN IF NOT EXISTS tournament_id INTEGER;

CREATE TABLE IF NOT EXISTS falcon.tournament_match_events (
    id             SERIAL PRIMARY KEY,
    match_id       INTEGER NOT NULL REFERENCES falcon.tournament_matches(id) ON DELETE CASCADE,
    tournament_id  INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    event_type     VARCHAR(20) NOT NULL
        CHECK (event_type IN ('point', 'side_out', 'correction', 'match_start', 'match_complete')),
    player_slot    SMALLINT CHECK (player_slot IN (1, 2)),
    score_player1  INTEGER NOT NULL DEFAULT 0,
    score_player2  INTEGER NOT NULL DEFAULT 0,
    note           VARCHAR(255),
    actor_id       INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tme_match      ON falcon.tournament_match_events(match_id, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_tme_tournament ON falcon.tournament_match_events(tournament_id);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT ON falcon.tournament_match_events TO falcon_app;
        GRANT USAGE, SELECT ON SEQUENCE falcon.tournament_match_events_id_seq TO falcon_app;
    END IF;
END $$;
