-- ============================================================
--  Migration 021: Open Play — allow 'pending_approval' as a
--  queue_status value.
--
--  Bug: migration 020 added queue_status with
--    CHECK (queue_status IN ('waiting','queued','playing','resting','left'))
--  but OpenPlayEngine::joinEvent() (tournament/open_play_engine.php)
--  inserts queue_status = 'pending_approval' for every new join
--  request (Open Play requires staff approval before a player
--  enters the active queue). That value violates the CHECK
--  constraint above, so every join request fails at the database
--  level with a constraint-violation error — nobody can actually
--  join an Open Play event as shipped.
--
--  This migration finds whatever CHECK constraint currently
--  governs falcon.tournament_players.queue_status (found by
--  inspecting pg_constraint rather than assuming a specific
--  auto-generated name, since that name depends on how/when the
--  column was added) and replaces it with one that also allows
--  'pending_approval'.
--
--  Safe to re-run.
-- ============================================================

DO $$
DECLARE
    constraint_record RECORD;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon' AND table_name = 'tournament_players'
          AND column_name  = 'queue_status'
    ) THEN
        RAISE NOTICE 'falcon.tournament_players.queue_status does not exist — skipping (run migration 020 first).';
        RETURN;
    END IF;

        -- Replace every CHECK definition that mentions queue_status. This avoids
        -- leaving a legacy constraint active on partially migrated databases and
        -- avoids dropping an unrelated CHECK chosen arbitrarily.
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

    RAISE NOTICE 'Migration 021 OK — queue_status now allows pending_approval.';
END;
$$;
