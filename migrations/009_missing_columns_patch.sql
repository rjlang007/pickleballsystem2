-- ============================================================
--  MIGRATION: 009_missing_columns_patch.sql
--  Fixes columns/constraints the app code expects that no
--  earlier migration actually created. Safe to re-run.
-- ============================================================

-- 1. users.display_name — read by leaderboard_engine.php
ALTER TABLE falcon.users
    ADD COLUMN IF NOT EXISTS display_name VARCHAR(120);

-- 2. player_passes needs a UNIQUE constraint on user_id so
--    "ON CONFLICT (user_id)" in player/my_qr.php works.
--    (qr_token is already unique, but user_id was not.)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'player_passes_user_id_key'
    ) THEN
        ALTER TABLE falcon.player_passes
            ADD CONSTRAINT player_passes_user_id_key UNIQUE (user_id);
    END IF;
END $$;

-- 3. chat_conversations.created_at — read/written by api/chat.php
ALTER TABLE falcon.chat_conversations
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- 3b. chat_messages needs msg_type / is_read / is_deleted — api/chat.php
--     reads and writes all three but the original schema only has
--     (id, conversation_id, sender_id, message, created_at).
ALTER TABLE falcon.chat_messages
    ADD COLUMN IF NOT EXISTS msg_type   VARCHAR(30) NOT NULL DEFAULT 'text',
    ADD COLUMN IF NOT EXISTS is_read    BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;

-- 4. topup_requests: player/topup.php and topup_history.php expect
--    gcash_ref_no, reviewed_at, review_note (the original schema
--    instead has reference_number / processed_at / admin_comment).
--    Add the expected columns and backfill from the old ones.
ALTER TABLE falcon.topup_requests
    ADD COLUMN IF NOT EXISTS gcash_ref_no VARCHAR(100),
    ADD COLUMN IF NOT EXISTS reviewed_at  TIMESTAMP,
    ADD COLUMN IF NOT EXISTS review_note  VARCHAR(500);

UPDATE falcon.topup_requests
   SET gcash_ref_no = reference_number
 WHERE gcash_ref_no IS NULL AND reference_number IS NOT NULL;

UPDATE falcon.topup_requests
   SET reviewed_at = processed_at
 WHERE reviewed_at IS NULL AND processed_at IS NOT NULL;

UPDATE falcon.topup_requests
   SET review_note = admin_comment
 WHERE review_note IS NULL AND admin_comment IS NOT NULL;

ALTER TABLE falcon.topup_requests
    ADD COLUMN IF NOT EXISTS screenshot_path VARCHAR(255);

UPDATE falcon.topup_requests
   SET screenshot_path = proof_image
 WHERE screenshot_path IS NULL AND proof_image IS NOT NULL;

ALTER TABLE falcon.topup_requests
    ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL;

UPDATE falcon.topup_requests
   SET reviewed_by = processed_by
 WHERE reviewed_by IS NULL AND processed_by IS NOT NULL;

-- 5. falcon.transactions: admin/players.php and admin/generate_topup_qr.php
--    expect method, note, status, processed_by columns that the original
--    schema never included (it only has "reason").
ALTER TABLE falcon.transactions
    ADD COLUMN IF NOT EXISTS method       VARCHAR(50),
    ADD COLUMN IF NOT EXISTS note         VARCHAR(500),
    ADD COLUMN IF NOT EXISTS status       VARCHAR(50) DEFAULT 'approved',
    ADD COLUMN IF NOT EXISTS processed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100);

UPDATE falcon.transactions
   SET reference_no = COALESCE(reference_no, reference_number)
 WHERE reference_no IS NULL AND reference_number IS NOT NULL;

UPDATE falcon.transactions
   SET note = reason
 WHERE note IS NULL AND reason IS NOT NULL;

-- 6. falcon.court_photos — used entirely by admin/content_manager.php's
--    "Court Gallery" panel, no migration ever created it.
CREATE TABLE IF NOT EXISTS falcon.court_photos (
    id         SERIAL PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL,
    caption    VARCHAR(500),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_court_photos_sort ON falcon.court_photos (sort_order, id);