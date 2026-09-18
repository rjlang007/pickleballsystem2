-- Runtime compatibility repair for older PostgreSQL installations.
-- Safe to run repeatedly and safe for databases that already have these columns.

ALTER TABLE falcon.reservations
    ADD COLUMN IF NOT EXISTS payment_verified_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS payment_verified_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS cancelled_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500);

ALTER TABLE falcon.activity_types
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE falcon.membership_plans
    ADD COLUMN IF NOT EXISTS tagline VARCHAR(255),
    ADD COLUMN IF NOT EXISTS price NUMERIC(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS period VARCHAR(50),
    ADD COLUMN IF NOT EXISTS per_game VARCHAR(100),
    ADD COLUMN IF NOT EXISTS features TEXT,
    ADD COLUMN IF NOT EXISTS is_featured BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE falcon.schedule_slots
    ADD COLUMN IF NOT EXISTS time_label VARCHAR(30),
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'available',
    ADD COLUMN IF NOT EXISTS max_players INTEGER NOT NULL DEFAULT 4;

CREATE TABLE IF NOT EXISTS falcon.court_reservations (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    reservation_date DATE NOT NULL,
    slot_start TIME NOT NULL,
    slot_end TIME NOT NULL,
    label VARCHAR(255) NOT NULL DEFAULT 'Reserved',
    notes VARCHAR(500),
    status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
    reserved_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_court_reservations_date
    ON falcon.court_reservations (reservation_date, court_id, slot_start);
CREATE INDEX IF NOT EXISTS idx_court_reservations_status
    ON falcon.court_reservations (status);
