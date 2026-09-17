-- ============================================================
--  MIGRATION: 010_food_ordering.sql
--  Adds the mini-restaurant / food ordering feature:
--    - falcon.food_items         (menu, admin-managed, has price)
--    - falcon.food_orders        (one row per order, has order_number)
--    - falcon.food_order_items   (line items per order)
--    - falcon.food_order_number_seq (drives short order numbers like A-0042)
--
--  Safe to re-run: every statement is guarded with IF NOT EXISTS /
--  DO $$ ... $$ blocks, matching the style of the other migrations.
-- ============================================================

-- ── Menu items ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.food_items (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(200)   NOT NULL,
    description  TEXT,
    price        NUMERIC(8,2)   NOT NULL DEFAULT 0 CHECK (price >= 0),
    category     VARCHAR(100)   NOT NULL DEFAULT 'Main',
    image        VARCHAR(255),
    is_available BOOLEAN        NOT NULL DEFAULT TRUE,
    sort_order   INTEGER        NOT NULL DEFAULT 0,
    created_by   INTEGER        REFERENCES falcon.users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_food_items_category    ON falcon.food_items (category);
CREATE INDEX IF NOT EXISTS idx_food_items_is_available ON falcon.food_items (is_available);

-- ── Order number sequence → generates "A-0001", "A-0002", ... ──
CREATE SEQUENCE IF NOT EXISTS falcon.food_order_number_seq START WITH 1 INCREMENT BY 1;

-- ── Orders ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.food_orders (
    id               SERIAL PRIMARY KEY,
    order_number     VARCHAR(20)   NOT NULL UNIQUE,
    user_id          INTEGER       NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    status           VARCHAR(20)   NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending','preparing','ready','completed','cancelled')),
    payment_method   VARCHAR(10)   NOT NULL DEFAULT 'wallet'
                          CHECK (payment_method IN ('wallet','cash')),
    payment_status   VARCHAR(10)   NOT NULL DEFAULT 'unpaid'
                          CHECK (payment_status IN ('unpaid','paid','refunded')),
    fulfillment_type VARCHAR(10)   NOT NULL DEFAULT 'pickup'
                          CHECK (fulfillment_type IN ('pickup','court')),
    court_id         INTEGER       REFERENCES falcon.courts(id) ON DELETE SET NULL,
    subtotal         NUMERIC(10,2) NOT NULL DEFAULT 0,
    total_amount     NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes            VARCHAR(255),
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_at         TIMESTAMP,
    completed_at     TIMESTAMP,
    cancelled_at     TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_food_orders_user_id    ON falcon.food_orders (user_id);
CREATE INDEX IF NOT EXISTS idx_food_orders_status     ON falcon.food_orders (status);
CREATE INDEX IF NOT EXISTS idx_food_orders_created_at ON falcon.food_orders (created_at);

-- ── Order line items ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.food_order_items (
    id           SERIAL PRIMARY KEY,
    order_id     INTEGER       NOT NULL REFERENCES falcon.food_orders(id) ON DELETE CASCADE,
    food_item_id INTEGER       REFERENCES falcon.food_items(id) ON DELETE SET NULL,
    item_name    VARCHAR(200)  NOT NULL,
    unit_price   NUMERIC(8,2)  NOT NULL,
    quantity     INTEGER       NOT NULL DEFAULT 1,
    subtotal     NUMERIC(10,2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_food_order_items_order_id ON falcon.food_order_items (order_id);

-- ── Patch columns for installs that already had an older/partial
--    version of these tables (safe no-ops if columns already exist) ──
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'falcon' AND table_name = 'food_orders' AND column_name = 'fulfillment_type'
    ) THEN
        ALTER TABLE falcon.food_orders ADD COLUMN fulfillment_type VARCHAR(10) NOT NULL DEFAULT 'pickup';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'falcon' AND table_name = 'food_orders' AND column_name = 'court_id'
    ) THEN
        ALTER TABLE falcon.food_orders ADD COLUMN court_id INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL;
    END IF;
END $$;
