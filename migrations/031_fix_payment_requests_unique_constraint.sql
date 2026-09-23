-- ============================================================
--  Migration 031: Open Play payment requests — fix a unique
--  constraint that can make a *second* rejection/approval for
--  the same player+event fail outright.
--
--  Bug: migration 025 created falcon.open_play_payment_requests
--  with:
--    UNIQUE (tournament_id, player_id, status)
--  The intent was "a player can only have one PENDING request
--  open at a time for a given event" — but the constraint as
--  written also blocks a second historical 'approved' or
--  'rejected' row for that same player+event.
--
--  That's reachable in normal use: a player joins and pays
--  (pending), staff rejects them (rejected), the player submits
--  a new payment and rejoins (a new pending row — allowed, since
--  it's a different status), and staff rejects again — 't
--  UPDATE ... SET status = 'rejected' now collides with the
--  'rejected' row already sitting there from the first rejection.
--  The whole update (and, in OpenPlayEngine::rejectJoin(), the
--  whole request) fails.
--
--  The same shape of bug can hit OpenPlayEngine::approveJoin()
--  (a second 'approved' row after leaving and rejoining) and the
--  auto-finalize safety net in OpenPlayEngine::finalizeEvent(),
--  which resolves *every* still-pending request for an event in
--  one statement — so one repeat player in that batch would roll
--  back the entire finalize for everyone else too.
--
--  Fix: drop the 3-column UNIQUE constraint (found by inspecting
--  pg_constraint rather than assuming a specific auto-generated
--  name) and replace it with a *partial* unique index that only
--  enforces "one at a time" against 'pending' rows — which is
--  the actual rule the app relies on. Historical approved/rejected
--  rows are left free to accumulate, same as any other audit trail.
--
--  Safe to re-run.
-- ============================================================

DO $$
DECLARE
    constraint_record RECORD;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'falcon' AND table_name = 'open_play_payment_requests'
    ) THEN
        RAISE NOTICE 'falcon.open_play_payment_requests does not exist — skipping (run migration 025 first).';
        RETURN;
    END IF;

    -- Drop every UNIQUE constraint on this table that covers exactly
    -- (tournament_id, player_id, status), whatever it happens to be named.
    FOR constraint_record IN
        SELECT con.conname
          FROM pg_constraint con
          JOIN pg_class rel ON rel.oid = con.conrelid
          JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
         WHERE nsp.nspname = 'falcon'
           AND rel.relname = 'open_play_payment_requests'
           AND con.contype = 'u'
           AND (
                SELECT array_agg(attname ORDER BY attname)
                  FROM unnest(con.conkey) AS k(attnum)
                  JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
               ) = ARRAY['player_id','status','tournament_id']::name[]
    LOOP
        EXECUTE format('ALTER TABLE falcon.open_play_payment_requests DROP CONSTRAINT %I', constraint_record.conname);
        RAISE NOTICE 'Dropped constraint % on falcon.open_play_payment_requests', constraint_record.conname;
    END LOOP;
END $$;

-- The rule that actually matters: no two *pending* requests for the
-- same player on the same event at once. Historical approved/rejected
-- rows are no longer constrained.
CREATE UNIQUE INDEX IF NOT EXISTS idx_open_play_payment_requests_one_pending
    ON falcon.open_play_payment_requests (tournament_id, player_id)
    WHERE status = 'pending';
