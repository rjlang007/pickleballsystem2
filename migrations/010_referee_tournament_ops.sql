-- ============================================================
--  Migration 010: Referee Console + Tournament Ops
--  Adds:
--   1. referee_id / live-scoring state columns on tournament_matches
--   2. tournament_match_events — per-point log (audit + reconnect recovery)
--   3. checked_in columns on tournament_players (day-of check-in)
--   4. tournament_bracket_audit — who changed what, for disputes
--   5. score correction tracking (referee accountability)
--
--  Safe to re-run — every statement is guarded with IF NOT EXISTS.
--  psql -U postgres -d <your_db> -f 010_referee_tournament_ops.sql
-- ============================================================

-- ── 1. Referee + live-scoring state on tournament_matches ─────
DO $$
BEGIN
    ALTER TABLE falcon.tournament_matches
        ADD COLUMN IF NOT EXISTS referee_id      INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
        ADD COLUMN IF NOT EXISTS current_game    SMALLINT NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS games_to_win    SMALLINT NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS games_won_p1    SMALLINT NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS games_won_p2    SMALLINT NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS current_server  SMALLINT NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS server_position VARCHAR(5) NOT NULL DEFAULT 'right',
        ADD COLUMN IF NOT EXISTS round_name      VARCHAR(60),
        ADD COLUMN IF NOT EXISTS started_at      TIMESTAMPTZ;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_matches'::regclass
          AND conname = 'tournament_matches_current_server_chk'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD CONSTRAINT tournament_matches_current_server_chk
            CHECK (current_server IN (1,2));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_matches'::regclass
          AND conname = 'tournament_matches_server_position_chk'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD CONSTRAINT tournament_matches_server_position_chk
            CHECK (server_position IN ('left','right'));
    END IF;
END;
$$;

-- scheduled_court may be added by a later compatibility patch. Add it here
-- before creating the index so this migration also works on a fresh database.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon' AND table_name = 'tournament_matches'
          AND column_name = 'scheduled_court'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD COLUMN scheduled_court INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL;
    END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_tm_referee ON falcon.tournament_matches(referee_id);
CREATE INDEX IF NOT EXISTS idx_tm_court_status ON falcon.tournament_matches(scheduled_court, status);

-- ── 2. Per-point / per-event score log ─────────────────────────
--     Lets a referee's device disconnect mid-match without losing
--     state (rebuild from event log) and gives a full audit trail
--     of every score change, correction, and server change.
CREATE TABLE IF NOT EXISTS falcon.tournament_match_events (
    id            SERIAL PRIMARY KEY,
    match_id      INTEGER NOT NULL REFERENCES falcon.tournament_matches(id) ON DELETE CASCADE,
    game_number   SMALLINT NOT NULL DEFAULT 1,
    event_type    VARCHAR(20) NOT NULL CHECK (event_type IN
                       ('point','side_out','server_change','correction',
                        'game_complete','match_complete','undo')),
    score_player1 INTEGER NOT NULL DEFAULT 0,
    score_player2 INTEGER NOT NULL DEFAULT 0,
    server        SMALLINT CHECK (server IN (1,2)),
    server_position VARCHAR(5) CHECK (server_position IN ('left','right')),
    note          TEXT,
    created_by    INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tme_match ON falcon.tournament_match_events(match_id, created_at);

-- ── 3. Tournament-day check-in ─────────────────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon' AND table_name = 'tournament_players'
          AND column_name  = 'checked_in'
    ) THEN
        ALTER TABLE falcon.tournament_players
            ADD COLUMN checked_in    BOOLEAN NOT NULL DEFAULT FALSE,
            ADD COLUMN checked_in_at TIMESTAMPTZ,
            ADD COLUMN checked_in_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL;
        RAISE NOTICE 'Added checked_in columns to tournament_players.';
    ELSE
        RAISE NOTICE 'checked_in already exists on tournament_players — skipping.';
    END IF;
END;
$$;

-- ── 4. Bracket / score audit trail (dispute resolution) ────────
CREATE TABLE IF NOT EXISTS falcon.tournament_bracket_audit (
    id             SERIAL PRIMARY KEY,
    tournament_id  INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    match_id       INTEGER REFERENCES falcon.tournament_matches(id) ON DELETE SET NULL,
    actor_id       INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    actor_role     VARCHAR(20),
    action         VARCHAR(40) NOT NULL,   -- e.g. 'manual_matchup', 'score_correction', 'referee_assigned', 'randomize'
    before_state   JSONB,
    after_state    JSONB,
    note           TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tba_tournament ON falcon.tournament_bracket_audit(tournament_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tba_match ON falcon.tournament_bracket_audit(match_id);

-- ── Verify ───────────────────────────────────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'falcon' AND table_name = 'tournament_match_events'
    ) THEN
        RAISE EXCEPTION 'Migration failed: tournament_match_events missing';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'falcon' AND table_name = 'tournament_bracket_audit'
    ) THEN
        RAISE EXCEPTION 'Migration failed: tournament_bracket_audit missing';
    END IF;
    RAISE NOTICE 'Migration 010 OK.';
END;
$$;
