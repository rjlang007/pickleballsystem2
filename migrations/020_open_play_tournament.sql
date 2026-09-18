-- ============================================================
--  Migration 020: Open Play (skill-balanced random pairing)
--
--  Adds a second tournament format alongside the existing
--  bracket types (single_elimination / round_robin /
--  double_elimination). Open Play events reuse
--  falcon.tournaments + falcon.tournament_players (a player
--  "registers" for an open play event the same way they
--  register for a bracket tournament) but matches are drawn
--  live, round by round, instead of generated all at once —
--  so they live in a new table, falcon.open_play_matches,
--  rather than falcon.tournament_matches.
--
--  Safe to re-run (IF NOT EXISTS / DO $$ guards throughout).
-- ============================================================

-- ── tournament_players: open-play specific columns ────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon' AND table_name = 'tournament_players'
          AND column_name  = 'skill_level'
    ) THEN
        ALTER TABLE falcon.tournament_players
            ADD COLUMN skill_level VARCHAR(20) NOT NULL DEFAULT 'average'
                CHECK (skill_level IN ('beginner','average','advance')),
            ADD COLUMN queue_status VARCHAR(20) NOT NULL DEFAULT 'waiting'
                CHECK (queue_status IN ('waiting','queued','playing','resting','left')),
            ADD COLUMN wins            INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN losses          INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN points_for      INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN points_against  INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN games_played    INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN arrival_at      TIMESTAMPTZ DEFAULT NOW(),
            ADD COLUMN queued_at       TIMESTAMPTZ DEFAULT NOW(),
            ADD COLUMN arrived_at      TIMESTAMPTZ DEFAULT NOW();
        RAISE NOTICE 'Added continuous walk-in queue columns to tournament_players.';
    ELSE
        RAISE NOTICE 'open-play columns already exist on tournament_players — skipping.';
    END IF;
END;
$$;

-- ── open_play_matches: live-drawn doubles/singles games ───────
CREATE TABLE IF NOT EXISTS falcon.open_play_matches (
    id                SERIAL PRIMARY KEY,
    tournament_id     INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    round_number      INTEGER NOT NULL DEFAULT 1,
    court_id          INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL,

    -- Team A (player2 nullable → also supports singles open play)
    team1_player1_id  INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    team1_player2_id  INTEGER REFERENCES falcon.users(id) ON DELETE CASCADE,
    -- Team B
    team2_player1_id  INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    team2_player2_id  INTEGER REFERENCES falcon.users(id) ON DELETE CASCADE,

    status            VARCHAR(20) NOT NULL DEFAULT 'ready'
                        CHECK (status IN ('ready','in_progress','paused','finished','cancelled')),
    is_tiebreaker     BOOLEAN NOT NULL DEFAULT FALSE,

    score_team1       INTEGER NOT NULL DEFAULT 0,
    score_team2       INTEGER NOT NULL DEFAULT 0,
    winner_team       SMALLINT CHECK (winner_team IN (1,2)),

    duration_seconds  INTEGER NOT NULL DEFAULT 900,
    remaining_seconds INTEGER,
    started_at        TIMESTAMPTZ,
    paused_at         TIMESTAMPTZ,
    finished_at       TIMESTAMPTZ,

    created_by        INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_opm_tournament ON falcon.open_play_matches(tournament_id);
CREATE INDEX IF NOT EXISTS idx_opm_status     ON falcon.open_play_matches(tournament_id, status);
CREATE INDEX IF NOT EXISTS idx_opm_court_live ON falcon.open_play_matches(court_id)
    WHERE status IN ('ready','in_progress','paused');

-- ── Grants (no-op if falcon_app role doesn't exist locally) ────
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON falcon.open_play_matches TO falcon_app;
        GRANT USAGE, SELECT ON SEQUENCE falcon.open_play_matches_id_seq TO falcon_app;
    END IF;
END;
$$;

-- ── Verify ──────────────────────────────────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'falcon' AND table_name = 'open_play_matches'
    ) THEN
        RAISE EXCEPTION 'Migration failed: falcon.open_play_matches missing';
    END IF;
    RAISE NOTICE 'Migration 020 OK — Open Play tables ready.';
END;
$$;
