-- ============================================================
--  MIGRATION: v3_refresh_court_status_view.sql
--  Ensures falcon.v_court_status has the latest live status columns
-- ============================================================

DROP VIEW IF EXISTS falcon.v_court_status;
CREATE VIEW falcon.v_court_status AS
SELECT
    c.id,
    c.name,
    c.description,
    c.court_type,
    c.is_active,
    c.is_maintenance,
    c.max_queue,
    c.credit_cost,
    c.game_duration,
    c.warmup_mins,
    c.pass_hours,
    c.sort_order,
    c.color,
    c.short_code,
    c.address,
    c.photo,
    c.manual_status,
    c.manual_status_set_by,
    c.manual_status_set_at,
    c.created_at,
    c.updated_at,

    CASE
        WHEN c.is_maintenance = TRUE THEN 'maintenance'
        WHEN c.is_active = FALSE THEN 'closed'
        WHEN c.manual_status IS NOT NULL THEN c.manual_status
        WHEN gs.id IS NOT NULL THEN
            CASE
                WHEN gs.session_type = 'reservation' THEN 'reserved'
                ELSE 'active'
            END
        WHEN gq.queued > 0 THEN 'queuing'
        ELSE 'available'
    END AS live_status,

    COALESCE(gp.player_count, 0) AS players_on_court,
    COALESCE(gq.queued, 0) AS queue_count,

    gs.started_at AS game_started_at,
    gs.duration_mins AS game_duration_mins,
    gs.id AS active_session_id

FROM falcon.courts c
LEFT JOIN falcon.game_sessions gs
    ON gs.court_id = c.id AND gs.status = 'active'
LEFT JOIN (
    SELECT session_id, COUNT(*) AS player_count
    FROM falcon.game_players
    GROUP BY session_id
) gp ON gp.session_id = gs.id
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS queued
    FROM falcon.game_queue
    WHERE session_id IS NULL AND court_id = c.id
) gq ON true
ORDER BY c.sort_order, c.id;
