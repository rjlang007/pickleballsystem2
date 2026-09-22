-- ============================================================
--  MIGRATION: 028_open_play_leaderboard_adjustments.sql
--
--  admin/leaderboard_admin.php now displays each player's season
--  score as the SUM of the points they earned from Open Play
--  sessions only (1st = 3, 2nd = 2, 3rd = 1, else = 0 — see
--  config/tournament_config.php -> open_play_point_distribution),
--  computed live from falcon.tournament_scores /
--  falcon.tournaments (bracket_type = 'open_play').
--
--  That total is derived on the fly, so admins/superadmins still
--  need a place to record manual corrections (dispute resolution,
--  no-show penalties, bonus points, etc.) without polluting
--  falcon.leaderboard, which also aggregates regular bracket
--  tournaments and is used elsewhere (public/leaderboard.php).
--  This table holds just those manual deltas, scoped per
--  player + season, and is only ever written to by
--  admin/leaderboard_admin.php (admin/super_admin only).
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.open_play_leaderboard_adjustments (
    id           SERIAL PRIMARY KEY,
    player_id    INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    season       INTEGER NOT NULL,
    points_delta INTEGER NOT NULL,
    reason       TEXT NOT NULL,
    adjusted_by  INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_op_lb_adj_player_season
    ON falcon.open_play_leaderboard_adjustments (player_id, season);
