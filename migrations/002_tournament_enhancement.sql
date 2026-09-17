-- ============================================================
-- Migration 002: Tournament Enhancement
-- Adapted to actual falcon schema (events already has event_type
-- with CHECK ('event','tournament') and linked_tournament_id;
-- users already has display_name; courts already exists).
-- ============================================================

-- 1. tournaments table
CREATE TABLE IF NOT EXISTS falcon.tournaments (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    bracket_type  VARCHAR(50)  NOT NULL DEFAULT 'single_elimination',
    status        VARCHAR(50)  NOT NULL DEFAULT 'draft',
    max_players   INTEGER      NOT NULL DEFAULT 16,
    start_date    TIMESTAMP,
    end_date      TIMESTAMP,
    featured      BOOLEAN      DEFAULT FALSE,
    settings      JSONB        DEFAULT '{}',
    created_by    INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at    TIMESTAMP    DEFAULT NOW(),
    updated_at    TIMESTAMP    DEFAULT NOW()
);

-- 2. tournament_players table
CREATE TABLE IF NOT EXISTS falcon.tournament_players (
    id            SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    player_id     INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    seed          INTEGER,
    status        VARCHAR(50) NOT NULL DEFAULT 'registered',
    joined_at     TIMESTAMP DEFAULT NOW(),
    UNIQUE(tournament_id, player_id)
);

-- 3. tournament_matches table
CREATE TABLE IF NOT EXISTS falcon.tournament_matches (
    id               SERIAL PRIMARY KEY,
    tournament_id    INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    bracket_round    INTEGER NOT NULL,
    match_number     INTEGER NOT NULL,
    bracket_section  VARCHAR(50) DEFAULT 'main',
    player1_id       INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    player2_id       INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    winner_id        INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    score_player1    INTEGER DEFAULT 0,
    score_player2    INTEGER DEFAULT 0,
    court_id         INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL,
    scheduled_at     TIMESTAMP,
    completed_at     TIMESTAMP,
    status           VARCHAR(50) DEFAULT 'pending',
    recorded_by      INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    recorded_at      TIMESTAMP,
    created_at       TIMESTAMP DEFAULT NOW(),
    UNIQUE(tournament_id, bracket_round, match_number, bracket_section)
);

-- 4. tournament_scores table
CREATE TABLE IF NOT EXISTS falcon.tournament_scores (
    id            SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES falcon.tournaments(id) ON DELETE CASCADE,
    player_id     INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    placement     INTEGER NOT NULL,
    points        INTEGER NOT NULL DEFAULT 0,
    note          TEXT,
    recorded_by   INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    recorded_at   TIMESTAMP DEFAULT NOW(),
    UNIQUE(tournament_id, player_id)
);

-- 5. leaderboard table
CREATE TABLE IF NOT EXISTS falcon.leaderboard (
    id                 SERIAL PRIMARY KEY,
    player_id          INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    season             INTEGER NOT NULL DEFAULT EXTRACT(YEAR FROM NOW())::INTEGER,
    total_points       INTEGER NOT NULL DEFAULT 0,
    total_wins         INTEGER NOT NULL DEFAULT 0,
    total_tournaments  INTEGER NOT NULL DEFAULT 0,
    rank               INTEGER,
    updated_at         TIMESTAMP DEFAULT NOW(),
    UNIQUE(player_id, season)
);

-- 6. events table already has event_type (CHECK 'event'/'tournament')
--    and linked_tournament_id. No ALTER needed — columns exist.
--    Note: content_manager.php is adapted to use linked_tournament_id
--    instead of tournament_id, and 'event'/'tournament' instead of
--    'party_event'/'tournament'.

-- 7. users.display_name already exists in actual schema. No ALTER needed.

-- 8. Indexes
CREATE INDEX IF NOT EXISTS idx_tournament_matches_tournament_id ON falcon.tournament_matches(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_status        ON falcon.tournament_matches(status);
CREATE INDEX IF NOT EXISTS idx_tournament_players_tournament_id ON falcon.tournament_players(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_players_player_id     ON falcon.tournament_players(player_id);
CREATE INDEX IF NOT EXISTS idx_tournament_scores_tournament_id  ON falcon.tournament_scores(tournament_id);
CREATE INDEX IF NOT EXISTS idx_leaderboard_season               ON falcon.leaderboard(season);
CREATE INDEX IF NOT EXISTS idx_leaderboard_player_id            ON falcon.leaderboard(player_id);
-- idx_events_event_type and idx_events_linked_tournament already exist