-- Keep every court consumer aligned on current rentals, reservations, and Open Play.

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
        WHEN EXISTS (
            SELECT 1 FROM falcon.reservations r
             WHERE r.court_id = c.id
               AND r.status IN ('pending', 'confirmed')
               AND r.slot_date = CURRENT_DATE
               AND CURRENT_TIME BETWEEN r.slot_time AND r.slot_end
        ) THEN 'reserved'
        WHEN EXISTS (
            SELECT 1 FROM falcon.court_reservations cr
             WHERE cr.court_id = c.id
               AND cr.status != 'cancelled'
               AND cr.reservation_date = CURRENT_DATE
               AND CURRENT_TIME BETWEEN cr.slot_start AND cr.slot_end
        ) THEN 'reserved'
        WHEN EXISTS (
            SELECT 1 FROM falcon.tournaments t
             WHERE t.bracket_type = 'open_play'
               AND t.status IN ('registration_open', 'registration_closed', 'in_progress', 'paused')
               AND t.start_date <= NOW()
               AND (t.end_date IS NULL OR t.end_date >= NOW())
               AND (
                   COALESCE(t.settings->>'court_scope', 'all') <> 'selected'
                   OR (t.settings->'court_ids') ? c.id::text
               )
        ) THEN 'open_play'
        WHEN gs.id IS NOT NULL THEN
            CASE
                WHEN gs.session_type = 'reservation' THEN 'reserved'
                ELSE 'active'
            END
        WHEN gq.queued > 0 THEN 'queuing'
        ELSE 'available'
    END AS live_status,

    (
        c.is_active = TRUE
        AND c.is_maintenance = FALSE
        AND (c.manual_status IS NULL OR c.manual_status = 'open_play')
        AND gs.id IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM falcon.reservations r
             WHERE r.court_id = c.id
               AND r.status IN ('pending', 'confirmed')
               AND r.slot_date = CURRENT_DATE
               AND CURRENT_TIME BETWEEN r.slot_time AND r.slot_end
        )
        AND NOT EXISTS (
            SELECT 1 FROM falcon.court_reservations cr
             WHERE cr.court_id = c.id
               AND cr.status != 'cancelled'
               AND cr.reservation_date = CURRENT_DATE
               AND CURRENT_TIME BETWEEN cr.slot_start AND cr.slot_end
        )
        AND NOT EXISTS (
            SELECT 1 FROM falcon.tournaments t
             WHERE t.bracket_type = 'open_play'
               AND t.status IN ('registration_open', 'registration_closed', 'in_progress', 'paused')
               AND t.start_date <= NOW()
               AND (t.end_date IS NULL OR t.end_date >= NOW())
               AND (
                   COALESCE(t.settings->>'court_scope', 'all') <> 'selected'
                   OR (t.settings->'court_ids') ? c.id::text
               )
        )
    ) AS is_queueable,

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