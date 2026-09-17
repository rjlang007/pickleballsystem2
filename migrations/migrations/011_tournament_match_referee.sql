-- ============================================================
--  MIGRATION: 011_tournament_match_referee.sql
--  Adds referee assignment to tournament matches.
--
--  falcon.tournament_matches already had court_id (which court a
--  match is played on), but no way to record which referee is
--  running it — needed for Staff's "assign a referee + court to
--  each match" tournament-day workflow, and for the Referee
--  role's "assigned to a specific court/match" scoring view.
-- ============================================================

ALTER TABLE falcon.tournament_matches
    ADD COLUMN IF NOT EXISTS referee_id INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_tournament_matches_referee ON falcon.tournament_matches (referee_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_court    ON falcon.tournament_matches (court_id);
