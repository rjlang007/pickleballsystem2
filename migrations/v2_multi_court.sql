-- ============================================================
--  MIGRATION: v2_multi_court.sql
--  Expands Falcon Pickleball from 1 court to 6 courts
--  Run this FIRST, then v2_fix_view_notx.sql
-- ============================================================

-- Add new columns to falcon.courts
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS court_type VARCHAR(20) DEFAULT 'covered';
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS is_maintenance BOOLEAN DEFAULT FALSE;
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS sort_order INTEGER DEFAULT 0;
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS color VARCHAR(7) DEFAULT '#00e5a0';
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS photo TEXT;
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS short_code VARCHAR(5);

-- Add new columns to falcon.court_slot_modes
ALTER TABLE falcon.court_slot_modes ADD COLUMN IF NOT EXISTS created_by INTEGER REFERENCES falcon.users(id);
ALTER TABLE falcon.court_slot_modes ADD COLUMN IF NOT EXISTS is_whole_day BOOLEAN DEFAULT FALSE;
ALTER TABLE falcon.court_slot_modes ADD COLUMN IF NOT EXISTS note TEXT;

-- Add court_id to falcon.game_queue
ALTER TABLE falcon.game_queue ADD COLUMN IF NOT EXISTS court_id INTEGER REFERENCES falcon.courts(id);

-- Create index on game_queue.court_id
CREATE INDEX IF NOT EXISTS idx_game_queue_court_id ON falcon.game_queue(court_id);

-- Insert 6 default courts
INSERT INTO falcon.courts (id, name, short_code, court_type, is_active, is_maintenance, max_queue, credit_cost, game_duration, warmup_mins, pass_hours, sort_order, color, address, created_at, updated_at)
VALUES
(1, 'Court 1', 'C1', 'covered', TRUE, FALSE, 4, 50, 15, 2, 2, 1, '#00e5a0', 'Main Court Area', NOW(), NOW()),
(2, 'Court 2', 'C2', 'covered', TRUE, FALSE, 4, 50, 15, 2, 2, 2, '#00aaff', 'Main Court Area', NOW(), NOW()),
(3, 'Court 3', 'C3', 'uncovered', TRUE, FALSE, 4, 50, 15, 2, 2, 3, '#f59e0b', 'Outdoor Area', NOW(), NOW()),
(4, 'Court 4', 'C4', 'indoor', TRUE, FALSE, 4, 50, 15, 2, 2, 4, '#a855f7', 'Indoor Pavilion', NOW(), NOW()),
(5, 'Court 5', 'C5', 'outdoor', TRUE, FALSE, 4, 50, 15, 2, 2, 5, '#10b981', 'Backyard Courts', NOW(), NOW()),
(6, 'Court 6', 'C6', 'covered', TRUE, FALSE, 4, 50, 15, 2, 2, 6, '#ec4899', 'Upper Courts', NOW(), NOW())
ON CONFLICT (id) DO NOTHING;

-- Populate falcon.court_hours for all courts (10 AM to midnight daily)
INSERT INTO falcon.court_hours (court_id, day_of_week, open_time, close_time, is_closed)
SELECT c.id, d.day, '10:00:00', '00:00:00', FALSE
FROM falcon.courts c
CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6)) AS d(day)
ON CONFLICT (court_id, day_of_week) DO NOTHING;

-- Populate falcon.court_settings for all courts
INSERT INTO falcon.court_settings (court_id, key, value)
SELECT c.id, s.key, s.value
FROM falcon.courts c
CROSS JOIN (VALUES
    ('reservation_price_per_hour', '200.00'),
    ('reservation_deposit_percent', '50'),
    ('max_advance_booking_days', '14'),
    ('min_notice_hours', '2'),
    ('auto_confirm_under', '4'),
    ('require_deposit', 'true')
) AS s(key, value)
ON CONFLICT (court_id, key) DO NOTHING;

-- Insert default open-play rules for all courts (8 PM to midnight tonight)
INSERT INTO falcon.court_slot_modes (court_id, slot_date, day_of_week, time_from, time_to, mode, is_whole_day, note, created_by, created_at)
SELECT c.id, CURRENT_DATE, NULL, '20:00:00', '00:00:00', 'open_play', FALSE, 'Default evening open play', 1, NOW()
FROM falcon.courts c
WHERE NOT EXISTS (
    SELECT 1 FROM falcon.court_slot_modes
    WHERE court_id = c.id AND slot_date = CURRENT_DATE
    AND time_from = '20:00:00' AND time_to = '00:00:00'
);

-- Add performance indexes
CREATE INDEX IF NOT EXISTS idx_court_slot_modes_court_date ON falcon.court_slot_modes(court_id, slot_date);
CREATE INDEX IF NOT EXISTS idx_court_slot_modes_court_time ON falcon.court_slot_modes(court_id, time_from, time_to);
CREATE INDEX IF NOT EXISTS idx_game_sessions_court_status ON falcon.game_sessions(court_id, status);
CREATE INDEX IF NOT EXISTS idx_reservations_court_date ON falcon.reservations(court_id, slot_date);