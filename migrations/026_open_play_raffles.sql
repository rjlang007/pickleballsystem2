-- Open Play raffle draws — a staff-run "spin the wheel" prize draw among
-- players currently seated in an active (ready/in_progress/paused) match.
-- Ported from the Dink Board reference system's raffle feature; ignores
-- that system's tournament/bracket and venue-location features, which
-- don't apply to Open Play.
CREATE TABLE IF NOT EXISTS falcon.open_play_raffle_draws (
    id                  SERIAL PRIMARY KEY,
    tournament_id       INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    prize_description   VARCHAR(160) NOT NULL,
    winner_player_id    INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    winner_name         VARCHAR(150) NOT NULL,
    participant_ids     JSONB NOT NULL DEFAULT '[]'::jsonb,
    participant_names   JSONB NOT NULL DEFAULT '[]'::jsonb,
    drawn_by            INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_open_play_raffle_tournament
    ON falcon.open_play_raffle_draws(tournament_id, created_at DESC);
