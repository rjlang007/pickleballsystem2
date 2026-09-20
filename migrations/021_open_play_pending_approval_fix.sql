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
    old_constraint_name TEXT;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'falcon' AND table_name = 'tournament_players'
          AND column_name  = 'queue_status'
    ) THEN
        RAISE NOTICE 'falcon.tournament_players.queue_status does not exist — skipping (run migration 020 first).';
        RETURN;
    END IF;

    -- Find the existing CHECK constraint on this column, whatever it's named.
    SELECT con.conname INTO old_constraint_name
      FROM pg_constraint con
      JOIN pg_class rel      ON rel.oid = con.conrelid
      JOIN pg_namespace nsp  ON nsp.oid = rel.relnamespace
      JOIN pg_attribute att  ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
     WHERE nsp.nspname = 'falcon'
       AND rel.relname = 'tournament_players'
       AND att.attname = 'queue_status'
       AND con.contype = 'c'
     LIMIT 1;

    IF old_constraint_name IS NOT NULL THEN
        IF old_constraint_name = 'tournament_players_queue_status_pending_chk' THEN
            RAISE NOTICE 'queue_status CHECK constraint already includes pending_approval — skipping.';
            RETURN;
        END IF;
        EXECUTE format('ALTER TABLE falcon.tournament_players DROP CONSTRAINT %I', old_constraint_name);
    END IF;

    ALTER TABLE falcon.tournament_players
        ADD CONSTRAINT tournament_players_queue_status_pending_chk
        CHECK (queue_status IN ('pending_approval','waiting','queued','playing','resting','left'));

    RAISE NOTICE 'Migration 021 OK — queue_status now allows pending_approval.';
END;
$$;
