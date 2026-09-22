-- ============================================================
--  FILE: migrations/tournament_tables_patch.sql
--  Padol Pickleball · Tournament Schema Patch
--
--  Run this INSTEAD of tournament_tables.sql if you already
--  ran a partial migration and got the bracket_section error.
--
--  Safe to re-run (all statements are IF NOT EXISTS / DO $$).
--
--  psql -U postgres -d <your_db> -f tournament_tables_patch.sql
-- ============================================================

-- ── PATCH: add bracket_section to existing tournament_matches ─
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon'
          AND table_name   = 'tournament_matches'
          AND column_name  = 'bracket_section'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD COLUMN bracket_section VARCHAR(20) NOT NULL DEFAULT 'main'
                CHECK (bracket_section IN ('main','winners','losers','grand_final'));
        RAISE NOTICE 'Added bracket_section column to tournament_matches.';
    ELSE
        RAISE NOTICE 'bracket_section already exists — skipping.';
    END IF;
END;
$$;

-- ── PATCH: add recorded_by / recorded_at if missing ──────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon'
          AND table_name   = 'tournament_matches'
          AND column_name  = 'recorded_by'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD COLUMN recorded_by  INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
            ADD COLUMN recorded_at  TIMESTAMPTZ;
        RAISE NOTICE 'Added recorded_by / recorded_at to tournament_matches.';
    ELSE
        RAISE NOTICE 'recorded_by already exists — skipping.';
    END IF;
END;
$$;

-- ── PATCH: add scheduled_court / scheduled_time if missing ───
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon'
          AND table_name   = 'tournament_matches'
          AND column_name  = 'scheduled_court'
    ) THEN
        ALTER TABLE falcon.tournament_matches
            ADD COLUMN scheduled_court INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL,
            ADD COLUMN scheduled_time  TIMESTAMPTZ;
        RAISE NOTICE 'Added scheduled_court / scheduled_time to tournament_matches.';
    ELSE
        RAISE NOTICE 'scheduled_court already exists — skipping.';
    END IF;
END;
$$;

-- ── PATCH: unique index on tournament_matches ─────────────────
CREATE UNIQUE INDEX IF NOT EXISTS idx_tm_unique_match
    ON falcon.tournament_matches(tournament_id, bracket_round, match_number, bracket_section);

-- ── PATCH: other indexes on tournament_matches ────────────────
CREATE INDEX IF NOT EXISTS idx_tm_tournament
    ON falcon.tournament_matches(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tm_status
    ON falcon.tournament_matches(tournament_id, status);
CREATE INDEX IF NOT EXISTS idx_tm_round
    ON falcon.tournament_matches(tournament_id, bracket_round);

-- ── TABLE 4: leaderboard ─────────────────────────────────────
-- REMOVED (see migrations/027_fix_duplicate_migration_schema_conflicts.sql
-- and config/db.php's ensureLeaderboardTable()): this used to define a
-- per-tournament leaderboard (extra tournament_id/total_losses/last_updated
-- columns). The table was already created earlier by
-- 002_tournament_enhancement.sql with the season-only schema the app
-- actually uses, so this CREATE TABLE always no-op'd — and the index
-- below then failed on every run because tournament_id was never really
-- added. config/db.php's ensureLeaderboardTable() runs on every request
-- and explicitly drops tournament_id/total_losses/last_updated as stale
-- if it ever finds them, confirming the season-only schema is the
-- intended one. Nothing to do here; falcon.leaderboard is fully owned
-- by 002_tournament_enhancement.sql + the runtime self-healer.

-- ── TABLE 5: tournament_scores ────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.tournament_scores (
    id            SERIAL PRIMARY KEY,
    tournament_id INTEGER      NOT NULL
                      REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    player_id     INTEGER      NOT NULL
                      REFERENCES falcon.users(id) ON DELETE CASCADE,
    placement     SMALLINT     NOT NULL CHECK (placement >= 1),
    points        INTEGER      NOT NULL DEFAULT 0 CHECK (points >= 0),
    recorded_by   INTEGER      NOT NULL
                      REFERENCES falcon.users(id) ON DELETE RESTRICT,
    recorded_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (tournament_id, player_id)
);

CREATE INDEX IF NOT EXISTS idx_ts_tournament
    ON falcon.tournament_scores(tournament_id);
CREATE INDEX IF NOT EXISTS idx_ts_player
    ON falcon.tournament_scores(player_id);
CREATE INDEX IF NOT EXISTS idx_ts_placement
    ON falcon.tournament_scores(tournament_id, placement ASC);

-- ── TABLE 6: achievements ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.achievements (
    id               SERIAL PRIMARY KEY,
    player_id        INTEGER      NOT NULL
                         REFERENCES falcon.users(id) ON DELETE CASCADE,
    achievement_type VARCHAR(60)  NOT NULL,
    label            VARCHAR(120),
    description      TEXT,
    tournament_id    INTEGER
                         REFERENCES falcon.tournaments(id) ON DELETE SET NULL,
    achieved_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (player_id, achievement_type)
);

CREATE INDEX IF NOT EXISTS idx_ach_player
    ON falcon.achievements(player_id);

-- ── auto-update trigger function ─────────────────────────────
CREATE OR REPLACE FUNCTION falcon.set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_tournaments_updated_at'
    ) THEN
        CREATE TRIGGER trg_tournaments_updated_at
            BEFORE UPDATE ON falcon.tournaments
            FOR EACH ROW EXECUTE FUNCTION falcon.set_updated_at();
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_tournament_matches_updated_at'
    ) THEN
        CREATE TRIGGER trg_tournament_matches_updated_at
            BEFORE UPDATE ON falcon.tournament_matches
            FOR EACH ROW EXECUTE FUNCTION falcon.set_updated_at();
    END IF;
END;
$$;

-- ── Grants ───────────────────────────────────────────────────
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON falcon.leaderboard,
               falcon.tournament_scores,
               falcon.achievements
            TO falcon_app;

        GRANT USAGE, SELECT
            ON SEQUENCE falcon.leaderboard_id_seq,
               falcon.tournament_scores_id_seq,
               falcon.achievements_id_seq
            TO falcon_app;
    END IF;
END;
$$;

-- ── Verify all 6 tables now present ──────────────────────────
DO $$
DECLARE
    tbl TEXT;
    expected TEXT[] := ARRAY[
        'tournaments',
        'tournament_players',
        'tournament_matches',
        'leaderboard',
        'tournament_scores',
        'achievements'
    ];
    col_exists BOOLEAN;
BEGIN
    FOREACH tbl IN ARRAY expected LOOP
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'falcon' AND table_name = tbl
        ) THEN
            RAISE EXCEPTION 'Missing table: falcon.%', tbl;
        END IF;
    END LOOP;

    -- Verify the patched column is present
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon'
          AND table_name   = 'tournament_matches'
          AND column_name  = 'bracket_section'
    ) INTO col_exists;

    IF NOT col_exists THEN
        RAISE EXCEPTION 'bracket_section column still missing from tournament_matches';
    END IF;

    RAISE NOTICE 'Patch OK — all 6 tables present, bracket_section confirmed.';
END;
$$;