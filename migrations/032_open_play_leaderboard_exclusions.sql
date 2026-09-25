-- ============================================================
-- Migration 032: reversible Open Play leaderboard exclusions
--
-- Lets admins hide sample/test or otherwise unwanted player rows
-- from an Open Play season leaderboard without deleting finalized
-- tournament scores or affecting match history.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.open_play_leaderboard_exclusions (
    player_id  INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    season     INTEGER NOT NULL,
    reason     TEXT NOT NULL,
    excluded_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (player_id, season)
);

CREATE INDEX IF NOT EXISTS idx_open_play_lb_exclusions_season
    ON falcon.open_play_leaderboard_exclusions (season);
