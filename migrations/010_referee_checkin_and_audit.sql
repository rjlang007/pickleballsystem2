-- ============================================================
--  MIGRATION: 010_referee_checkin_and_audit.sql
--  Supports the staff tournament bracket builder:
--   - referee_id on tournament_matches (assign a referee per match;
--     court_id already exists from 002_tournament_enhancement.sql)
--   - check-in tracking on tournament_players (so no-shows aren't
--     auto-seeded into the bracket on tournament day)
--   - tournament_audit_log (who changed a bracket/matchup and when,
--     for dispute resolution)
--  Safe to re-run.
-- ============================================================

-- 1. Referee assignment per match.
ALTER TABLE falcon.tournament_matches
    ADD COLUMN IF NOT EXISTS referee_id INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_tm_referee ON falcon.tournament_matches(referee_id);

-- 2. Tournament-day check-in.
ALTER TABLE falcon.tournament_players
    ADD COLUMN IF NOT EXISTS checked_in    BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS checked_in_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_tp_checked_in ON falcon.tournament_players(tournament_id, checked_in);

-- 3. Audit trail for bracket/matchup/assignment changes.
CREATE TABLE IF NOT EXISTS falcon.tournament_audit_log (
    id            SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    match_id      INTEGER REFERENCES falcon.tournament_matches(id) ON DELETE SET NULL,
    actor_id      INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    action        VARCHAR(60) NOT NULL,
    details       JSONB NOT NULL DEFAULT '{}',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tal_tournament ON falcon.tournament_audit_log(tournament_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tal_match      ON falcon.tournament_audit_log(match_id);

-- ── Grants (harmless no-op locally if falcon_app role doesn't exist) ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT ON falcon.tournament_audit_log TO falcon_app;
        GRANT USAGE, SELECT ON SEQUENCE falcon.tournament_audit_log_id_seq TO falcon_app;
    END IF;
END $$;
