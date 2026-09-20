-- Migration 024: complete Open Play queue columns on partially migrated installs.
-- Migration 020 used one all-or-nothing column guard; databases that already
-- had skill_level could therefore miss the remaining queue columns.
-- Safe to re-run.

ALTER TABLE falcon.tournament_players
    ADD COLUMN IF NOT EXISTS skill_level VARCHAR(20) NOT NULL DEFAULT 'average',
    ADD COLUMN IF NOT EXISTS queue_status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    ADD COLUMN IF NOT EXISTS wins INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS losses INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS points_for INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS points_against INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS games_played INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS arrival_at TIMESTAMPTZ DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS queued_at TIMESTAMPTZ DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS arrived_at TIMESTAMPTZ DEFAULT NOW();

DO $$
DECLARE
    constraint_record RECORD;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_players'::regclass
          AND conname = 'tournament_players_skill_level_chk'
    ) THEN
        ALTER TABLE falcon.tournament_players
            ADD CONSTRAINT tournament_players_skill_level_chk
            CHECK (skill_level IN ('beginner','average','advance'));
    END IF;

    FOR constraint_record IN
        SELECT con.conname
          FROM pg_constraint con
         WHERE con.conrelid = 'falcon.tournament_players'::regclass
           AND con.contype = 'c'
           AND pg_get_constraintdef(con.oid) ILIKE '%queue_status%'
    LOOP
        EXECUTE format('ALTER TABLE falcon.tournament_players DROP CONSTRAINT %I', constraint_record.conname);
    END LOOP;

    ALTER TABLE falcon.tournament_players
        ADD CONSTRAINT tournament_players_queue_status_pending_chk
        CHECK (queue_status IN ('pending_approval','waiting','queued','playing','resting','left'));
END $$;

CREATE INDEX IF NOT EXISTS idx_tp_open_play_queue
    ON falcon.tournament_players(tournament_id, queue_status);
