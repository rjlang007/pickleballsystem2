-- Open Play payment-gated join requests.
CREATE TABLE IF NOT EXISTS falcon.open_play_payment_requests (
    id              SERIAL PRIMARY KEY,
    tournament_id   INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    player_id       INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    amount          NUMERIC(12,2) NOT NULL CHECK (amount >= 0),
    payment_method  VARCHAR(50) NOT NULL,
    reference_no    VARCHAR(120),
    proof_path      TEXT NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','rejected')),
    reviewed_by     INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    reviewed_at     TIMESTAMPTZ,
    review_note     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tournament_id, player_id, status)
);

CREATE INDEX IF NOT EXISTS idx_open_play_payment_status
    ON falcon.open_play_payment_requests(tournament_id, status);