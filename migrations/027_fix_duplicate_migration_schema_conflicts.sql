-- ============================================================
--  MIGRATION: 027_fix_duplicate_migration_schema_conflicts.sql
--
--  Two earlier migrations each created the same table twice with
--  incompatible column sets. Because every CREATE TABLE in this
--  project uses IF NOT EXISTS, whichever migration ran FIRST won,
--  and the second one's columns were silently never added — no
--  error at migration time, just missing columns discovered later
--  at runtime.
--
--  This migration is purely additive (ALTER TABLE ... ADD COLUMN
--  IF NOT EXISTS) and safe to run on any copy of this database,
--  regardless of which of the two conflicting versions "won" when
--  the table was first created.
--
--  ── Conflict 1: chat_rooms / chat_room_members / chat_room_messages
--     004_add_subscription_schema.sql   created these tables first.
--     006_add_chat_rooms_schema.sql     tried to create them again
--                                        with extra columns; no-op'd.
--     Live code (api/chat_rooms.php) requires the 006 columns:
--       - chat_rooms.pinned_announcement
--       - chat_room_members.left_at        (leaving a room)
--       - chat_room_messages.is_read        (unread counts)
--       - chat_room_messages.pinned_by      (who pinned a message)
--     Symptom before this fix: "leave room" and unread-count queries
--     throw a hard SQL error (column does not exist).
--
--  ── Conflict 2: tournament_match_events
--     010_referee_tournament_ops.sql   created this table first,
--                                       using columns game_number /
--                                       server / server_position /
--                                       created_by.
--     011_referee_live_scoring.sql     tried to create it again
--                                       with tournament_id /
--                                       player_slot / actor_id; no-op'd.
--     Live code (tournament/tournament_engine.php, the class actually
--     wired into referee/score_match.php) writes point-by-point
--     events using tournament_id / player_slot / actor_id — the 011
--     shape. That INSERT is wrapped in a try/catch ("must never block
--     scoring"), so on a database that only ran 010's version, every
--     single point/correction/match-complete event log write has been
--     silently failing with no visible error anywhere.
--
--     (referee/api/score_action.php, which writes the 010 shape, is
--     not called from any page in this codebase — it's dead code, not
--     an active second writer. Not touched by this migration.)
--
--  Safe to re-run.
-- ============================================================

-- ── Conflict 1: chat rooms ──────────────────────────────────────

ALTER TABLE falcon.chat_rooms
    ADD COLUMN IF NOT EXISTS pinned_announcement TEXT;

ALTER TABLE falcon.chat_room_members
    ADD COLUMN IF NOT EXISTS left_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_crm_room_active
    ON falcon.chat_room_members (room_id, left_at);

CREATE INDEX IF NOT EXISTS idx_crm_user
    ON falcon.chat_room_members (user_id, left_at);

ALTER TABLE falcon.chat_room_messages
    ADD COLUMN IF NOT EXISTS is_read   BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS pinned_by INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.chat_room_messages'::regclass
          AND conname = 'chat_room_messages_pinned_by_fkey'
    ) THEN
        ALTER TABLE falcon.chat_room_messages
            ADD CONSTRAINT chat_room_messages_pinned_by_fkey
            FOREIGN KEY (pinned_by) REFERENCES falcon.users(id) ON DELETE SET NULL;
    END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_crm_unread
    ON falcon.chat_room_messages (room_id, sender_id, is_read)
    WHERE is_read = FALSE AND is_deleted = FALSE;

-- ── Conflict 2: tournament_match_events ─────────────────────────

ALTER TABLE falcon.tournament_match_events
    ADD COLUMN IF NOT EXISTS tournament_id INTEGER,
    ADD COLUMN IF NOT EXISTS player_slot   SMALLINT,
    ADD COLUMN IF NOT EXISTS actor_id      INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_match_events'::regclass
          AND conname = 'tournament_match_events_tournament_id_fkey'
    ) THEN
        ALTER TABLE falcon.tournament_match_events
            ADD CONSTRAINT tournament_match_events_tournament_id_fkey
            FOREIGN KEY (tournament_id) REFERENCES falcon.tournaments(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_match_events'::regclass
          AND conname = 'tournament_match_events_player_slot_check'
    ) THEN
        ALTER TABLE falcon.tournament_match_events
            ADD CONSTRAINT tournament_match_events_player_slot_check
            CHECK (player_slot IS NULL OR player_slot IN (1, 2));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'falcon.tournament_match_events'::regclass
          AND conname = 'tournament_match_events_actor_id_fkey'
    ) THEN
        ALTER TABLE falcon.tournament_match_events
            ADD CONSTRAINT tournament_match_events_actor_id_fkey
            FOREIGN KEY (actor_id) REFERENCES falcon.users(id) ON DELETE SET NULL;
    END IF;
END;
$$;

-- Backfill tournament_id on any rows written before this fix (the
-- 010-shaped table has no tournament_id, so old rows are all NULL).
UPDATE falcon.tournament_match_events e
   SET tournament_id = tm.tournament_id
  FROM falcon.tournament_matches tm
 WHERE tm.id = e.match_id
   AND e.tournament_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_tme_tournament
    ON falcon.tournament_match_events (tournament_id);

-- Grant, matching the pattern used by 010/011 for this table.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'falcon_app') THEN
        GRANT SELECT, INSERT ON falcon.tournament_match_events TO falcon_app;
    END IF;
END;
$$;
