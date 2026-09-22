-- ============================================================
--  MIGRATION: 006_add_chat_rooms_schema.sql
--  Step 6 — Community & Group Chat Rooms
--  Run once against your PostgreSQL database.
--  Safe to re-run: uses IF NOT EXISTS / DO $$ throughout.
-- ============================================================

-- ── 1. chat_rooms ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.chat_rooms (
    id                   SERIAL PRIMARY KEY,
    name                 VARCHAR(60)  NOT NULL,
    description          TEXT,
    room_type            VARCHAR(20)  NOT NULL DEFAULT 'group'
                             CHECK (room_type IN ('community','group')),
    created_by           INTEGER      NOT NULL DEFAULT 0,   -- 0 = system
    max_members          SMALLINT     NOT NULL DEFAULT 4,
    is_active            BOOLEAN      NOT NULL DEFAULT TRUE,
    is_locked            BOOLEAN      NOT NULL DEFAULT FALSE,
    pinned_announcement  TEXT,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_chat_rooms_type
    ON falcon.chat_rooms (room_type);

CREATE INDEX IF NOT EXISTS idx_chat_rooms_active
    ON falcon.chat_rooms (is_active, updated_at DESC);

-- ── 2. chat_room_members ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.chat_room_members (
    id        SERIAL      PRIMARY KEY,
    room_id   INTEGER     NOT NULL REFERENCES falcon.chat_rooms(id) ON DELETE CASCADE,
    user_id   INTEGER     NOT NULL REFERENCES falcon.users(id)      ON DELETE CASCADE,
    role      VARCHAR(20) NOT NULL DEFAULT 'member'
                  CHECK (role IN ('owner','moderator','member')),
    joined_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    left_at   TIMESTAMPTZ,
    UNIQUE (room_id, user_id)
);

-- Older installs may already have this table without the leave timestamp.
ALTER TABLE falcon.chat_room_members
    ADD COLUMN IF NOT EXISTS left_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_crm_room_active
    ON falcon.chat_room_members (room_id, left_at NULLS FIRST);

CREATE INDEX IF NOT EXISTS idx_crm_user
    ON falcon.chat_room_members (user_id, left_at NULLS FIRST);

-- ── 3. chat_room_messages ────────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.chat_room_messages (
    id          SERIAL       PRIMARY KEY,
    room_id     INTEGER      NOT NULL REFERENCES falcon.chat_rooms(id)  ON DELETE CASCADE,
    sender_id   INTEGER      NOT NULL DEFAULT 0,  -- 0 = system message
    message     TEXT         NOT NULL,
    msg_type    VARCHAR(30)  NOT NULL DEFAULT 'text'
                    CHECK (msg_type IN ('text','system','game_invite','announcement')),
    meta        JSONB,
    is_read     BOOLEAN      NOT NULL DEFAULT FALSE,
    is_deleted  BOOLEAN      NOT NULL DEFAULT FALSE,
    is_pinned   BOOLEAN      NOT NULL DEFAULT FALSE,
    pinned_by   INTEGER,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_crm_room_msg
    ON falcon.chat_room_messages (room_id, created_at ASC)
    WHERE is_deleted = FALSE;

CREATE INDEX IF NOT EXISTS idx_crm_unread
    ON falcon.chat_room_messages (room_id, sender_id, is_read)
    WHERE is_read = FALSE AND is_deleted = FALSE;

-- ── 4. Seed the one community room if it doesn't exist ───────
INSERT INTO falcon.chat_rooms (name, description, room_type, created_by, max_members, is_active)
SELECT 'Community Court',
       'Open chat for all Padol players',
       'community',
       0,
       9999,
       TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM falcon.chat_rooms WHERE room_type = 'community'
);

-- ── 5. Auto-join all existing active players to community ─────
INSERT INTO falcon.chat_room_members (room_id, user_id, role, joined_at)
SELECT
    (SELECT MIN(id) FROM falcon.chat_rooms WHERE room_type = 'community'),
    u.id,
    'member',
    NOW()
FROM falcon.users u
WHERE u.is_active = TRUE
  AND u.role      = 'player'
  AND NOT EXISTS (
        SELECT 1
          FROM falcon.chat_room_members m
         WHERE m.room_id = (SELECT MIN(id) FROM falcon.chat_rooms WHERE room_type = 'community')
           AND m.user_id = u.id
      )
ON CONFLICT (room_id, user_id) DO NOTHING;