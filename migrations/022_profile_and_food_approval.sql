-- Adds profile display preferences and the food-order review workflow.
-- Safe to run on both fresh and existing installations.

ALTER TABLE falcon.users
    ADD COLUMN IF NOT EXISTS display_name VARCHAR(120),
    ADD COLUMN IF NOT EXISTS show_display_name BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE falcon.food_orders
    ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500),
    ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS ready_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP;

ALTER TABLE falcon.food_orders DROP CONSTRAINT IF EXISTS food_orders_status_check;
ALTER TABLE falcon.food_orders
    ADD CONSTRAINT food_orders_status_check
    CHECK (status IN ('pending','approved','preparing','ready','completed','rejected','cancelled'));

CREATE INDEX IF NOT EXISTS idx_food_orders_review_queue
    ON falcon.food_orders (status, created_at);