-- ============================================================
--  MIGRATION: 004_add_subscription_schema.sql
--
--  Adds:
--    falcon.subscriptions            – per-user subscription record
--    falcon.subscription_payments    – payment history per subscription
--    falcon.chat_rooms               – community + player-created group rooms
--    falcon.chat_room_members        – membership & roles per room
--    falcon.chat_room_messages       – messages inside group rooms
--    falcon.community_announcements  – owner/admin pinned announcements
--
--  Follows existing schema conventions:
--    • FK references → falcon.users(id)
--    • Explicit sequences for all serial PKs
--    • timestamptz throughout
--    • GRANT blocks for falcon_app + postgres roles
--    • Index storage params: fillfactor=100, deduplicate_items=True
-- ============================================================


-- ============================================================
-- SEQUENCES
-- ============================================================

CREATE SEQUENCE IF NOT EXISTS falcon.subscriptions_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE IF NOT EXISTS falcon.subscription_payments_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE IF NOT EXISTS falcon.chat_rooms_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE IF NOT EXISTS falcon.chat_room_members_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE IF NOT EXISTS falcon.chat_room_messages_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE IF NOT EXISTS falcon.community_announcements_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;


-- ============================================================
-- TABLE: falcon.subscriptions
--
--  One row per user.  Tracks the active plan and whether the
--  user is currently paid up.  `paid_until` is the source of
--  truth for access control — update it on every successful
--  payment (including webhook renewals).
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.subscriptions
(
    id              bigint          NOT NULL
                        DEFAULT nextval('falcon.subscriptions_id_seq'::regclass),
    user_id         integer         NOT NULL,

    -- Plan tier: 'basic' | 'medium' | 'premium'
    plan            character varying(50)   COLLATE pg_catalog."default"
                        NOT NULL DEFAULT 'basic',

    status          character varying(30)   COLLATE pg_catalog."default"
                        NOT NULL DEFAULT 'inactive',
                    -- 'active' | 'inactive' | 'expired' | 'cancelled'

    -- PayMongo recurring reference (null for manual / one-shot payments)
    paymongo_sub_id character varying(255)  COLLATE pg_catalog."default",

    -- When current paid period ends; NULL means never paid
    paid_until      timestamp with time zone,

    -- Automatically renew via PayMongo webhook?
    auto_renew      boolean         NOT NULL DEFAULT false,

    -- Soft cancel — keep access until paid_until then lapse
    cancelled_at    timestamp with time zone,

    created_at      timestamp with time zone NOT NULL DEFAULT now(),
    updated_at      timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT subscriptions_pkey          PRIMARY KEY (id),
    CONSTRAINT subscriptions_user_id_ukey  UNIQUE (user_id),
    CONSTRAINT subscriptions_user_id_fkey  FOREIGN KEY (user_id)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT subscriptions_plan_check    CHECK (
        plan IN ('basic', 'medium', 'premium')
    ),
    CONSTRAINT subscriptions_status_check  CHECK (
        status IN ('active', 'inactive', 'expired', 'cancelled')
    )
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.subscriptions
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.subscriptions FROM falcon_app;
GRANT INSERT, SELECT, UPDATE, DELETE ON TABLE falcon.subscriptions TO falcon_app;
GRANT ALL ON TABLE falcon.subscriptions TO postgres;

-- Index: quick lookup by status (access gate queries)
CREATE INDEX IF NOT EXISTS idx_subscriptions_status
    ON falcon.subscriptions USING btree
    (status COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

-- Index: expiry sweep (cron / webhook renewal jobs)
CREATE INDEX IF NOT EXISTS idx_subscriptions_paid_until
    ON falcon.subscriptions USING btree
    (paid_until ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

-- Index: PayMongo webhook lookup
CREATE INDEX IF NOT EXISTS idx_subscriptions_paymongo_sub_id
    ON falcon.subscriptions USING btree
    (paymongo_sub_id COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- TABLE: falcon.subscription_payments
--
--  Immutable ledger of every payment event (manual top-up,
--  PayMongo webhook, admin override, etc.).  Never delete rows.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.subscription_payments
(
    id                  bigint          NOT NULL
                            DEFAULT nextval('falcon.subscription_payments_id_seq'::regclass),
    subscription_id     bigint          NOT NULL,
    user_id             integer         NOT NULL,

    -- Amount in PHP (centavos stored as decimal for readability)
    amount              numeric(10,2)   NOT NULL,

    -- 'gcash' | 'maya' | 'card' | 'paymongo' | 'manual' | 'admin_override'
    payment_method      character varying(50)  COLLATE pg_catalog."default"
                            NOT NULL DEFAULT 'manual',

    -- PayMongo payment intent / payment ID
    paymongo_payment_id character varying(255) COLLATE pg_catalog."default",

    -- 'pending' | 'paid' | 'failed' | 'refunded'
    payment_status      character varying(30)  COLLATE pg_catalog."default"
                            NOT NULL DEFAULT 'pending',

    -- Period this payment covers
    period_start        timestamp with time zone,
    period_end          timestamp with time zone,

    -- Raw webhook payload for audit; null for manual payments
    raw_webhook         jsonb,

    -- Admin who manually confirmed (null for automated payments)
    confirmed_by        integer,

    created_at          timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT subscription_payments_pkey             PRIMARY KEY (id),
    CONSTRAINT subscription_payments_sub_id_fkey      FOREIGN KEY (subscription_id)
        REFERENCES falcon.subscriptions (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT subscription_payments_user_id_fkey     FOREIGN KEY (user_id)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT subscription_payments_confirmed_by_fkey FOREIGN KEY (confirmed_by)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE SET NULL,
    CONSTRAINT subscription_payments_status_check     CHECK (
        payment_status IN ('pending', 'paid', 'failed', 'refunded')
    )
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.subscription_payments
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.subscription_payments FROM falcon_app;
GRANT INSERT, SELECT, UPDATE ON TABLE falcon.subscription_payments TO falcon_app;
GRANT ALL ON TABLE falcon.subscription_payments TO postgres;

CREATE INDEX IF NOT EXISTS idx_sub_payments_subscription_id
    ON falcon.subscription_payments USING btree
    (subscription_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_sub_payments_user_id
    ON falcon.subscription_payments USING btree
    (user_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_sub_payments_paymongo_id
    ON falcon.subscription_payments USING btree
    (paymongo_payment_id COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_sub_payments_status
    ON falcon.subscription_payments USING btree
    (payment_status COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_sub_payments_created_at
    ON falcon.subscription_payments USING btree
    (created_at DESC NULLS FIRST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- TABLE: falcon.chat_rooms
--
--  Covers two room types:
--    'community'  – one global public room, auto-created, all
--                   active subscribers can read/write
--    'group'      – player-created private rooms (invite only)
--                   auto-lock when member count reaches 4
--
--  The 'game_invite' room type is a special group room that is
--  linked to a game session via game_session_id.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.chat_rooms
(
    id              bigint          NOT NULL
                        DEFAULT nextval('falcon.chat_rooms_id_seq'::regclass),

    -- 'community' | 'group' | 'game_invite'
    room_type       character varying(30)   COLLATE pg_catalog."default"
                        NOT NULL DEFAULT 'group',

    name            character varying(100)  COLLATE pg_catalog."default" NOT NULL,
    description     text            COLLATE pg_catalog."default",

    -- Player who created this room (null for the system community room)
    created_by      integer,

    -- Max members before room auto-locks (null = unlimited, 4 for game rooms)
    max_members     integer,

    -- Locked rooms reject new join requests
    is_locked       boolean         NOT NULL DEFAULT false,

    -- Soft-delete
    is_active       boolean         NOT NULL DEFAULT true,

    -- Only for game_invite rooms; links back to the game session
    game_session_id integer,

    -- Minimum plan required to read this room
    -- 'basic' | 'medium' | 'premium' | null (no restriction)
    required_plan   character varying(50)   COLLATE pg_catalog."default",

    created_at      timestamp with time zone NOT NULL DEFAULT now(),
    updated_at      timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT chat_rooms_pkey              PRIMARY KEY (id),
    CONSTRAINT chat_rooms_created_by_fkey   FOREIGN KEY (created_by)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE SET NULL,
    CONSTRAINT chat_rooms_type_check        CHECK (
        room_type IN ('community', 'group', 'game_invite')
    ),
    CONSTRAINT chat_rooms_required_plan_check CHECK (
        required_plan IS NULL OR required_plan IN ('basic', 'medium', 'premium')
    )
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.chat_rooms
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.chat_rooms FROM falcon_app;
GRANT INSERT, SELECT, UPDATE, DELETE ON TABLE falcon.chat_rooms TO falcon_app;
GRANT ALL ON TABLE falcon.chat_rooms TO postgres;

CREATE INDEX IF NOT EXISTS idx_chat_rooms_type
    ON falcon.chat_rooms USING btree
    (room_type COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_rooms_created_by
    ON falcon.chat_rooms USING btree
    (created_by ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_rooms_game_session
    ON falcon.chat_rooms USING btree
    (game_session_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_rooms_is_active
    ON falcon.chat_rooms USING btree
    (is_active ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- TABLE: falcon.chat_room_members
--
--  Tracks who is in which room and their role within it.
--  Roles:
--    'owner'  – room creator, can pin/kick/lock
--    'admin'  – promoted by owner, same moderation rights
--    'member' – regular participant
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.chat_room_members
(
    id          bigint      NOT NULL
                    DEFAULT nextval('falcon.chat_room_members_id_seq'::regclass),
    room_id     bigint      NOT NULL,
    user_id     integer     NOT NULL,

    -- 'owner' | 'admin' | 'member'
    role        character varying(20) COLLATE pg_catalog."default"
                    NOT NULL DEFAULT 'member',

    -- When the user joined (or was invited)
    joined_at   timestamp with time zone NOT NULL DEFAULT now(),

    -- Soft-remove without losing history
    is_active   boolean     NOT NULL DEFAULT true,

    -- Last time user opened this room (used for unread counts)
    last_read_at timestamp with time zone,

    CONSTRAINT chat_room_members_pkey           PRIMARY KEY (id),
    CONSTRAINT chat_room_members_room_user_ukey UNIQUE (room_id, user_id),
    CONSTRAINT chat_room_members_room_id_fkey   FOREIGN KEY (room_id)
        REFERENCES falcon.chat_rooms (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT chat_room_members_user_id_fkey   FOREIGN KEY (user_id)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT chat_room_members_role_check     CHECK (
        role IN ('owner', 'admin', 'member')
    )
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.chat_room_members
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.chat_room_members FROM falcon_app;
GRANT INSERT, SELECT, UPDATE, DELETE ON TABLE falcon.chat_room_members TO falcon_app;
GRANT ALL ON TABLE falcon.chat_room_members TO postgres;

-- Fastest path: "give me all rooms for user X"
CREATE INDEX IF NOT EXISTS idx_chat_room_members_user_id
    ON falcon.chat_room_members USING btree
    (user_id ASC NULLS LAST, is_active ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

-- Fastest path: "give me all members of room Y"
CREATE INDEX IF NOT EXISTS idx_chat_room_members_room_id
    ON falcon.chat_room_members USING btree
    (room_id ASC NULLS LAST, is_active ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- TABLE: falcon.chat_room_messages
--
--  Messages scoped to a chat_room (separate from the existing
--  falcon.chat_messages which handles 1-on-1 / admin DMs).
--
--  msg_type values:
--    'text'        – plain message
--    'game_invite' – structured invite card (meta carries details)
--    'system'      – auto-generated events (join, lock, etc.)
--    'gif'         – GIF embed
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.chat_room_messages
(
    id          bigint      NOT NULL
                    DEFAULT nextval('falcon.chat_room_messages_id_seq'::regclass),
    room_id     bigint      NOT NULL,
    sender_id   integer     NOT NULL,

    message     text        COLLATE pg_catalog."default" NOT NULL,

    msg_type    character varying(20) COLLATE pg_catalog."default"
                    NOT NULL DEFAULT 'text',

    -- Structured payload for game_invite / system events
    meta        jsonb,

    -- Soft-delete (moderator or sender can retract)
    is_deleted  boolean     NOT NULL DEFAULT false,

    -- Pinned messages float to the top of the room header
    is_pinned   boolean     NOT NULL DEFAULT false,

    -- Sender's plan at time of posting (for audit / display badge)
    sender_plan character varying(20) COLLATE pg_catalog."default"
                    DEFAULT 'basic',

    created_at  timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT chat_room_messages_pkey          PRIMARY KEY (id),
    CONSTRAINT chat_room_messages_room_id_fkey  FOREIGN KEY (room_id)
        REFERENCES falcon.chat_rooms (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT chat_room_messages_sender_fkey   FOREIGN KEY (sender_id)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT chat_room_messages_type_check    CHECK (
        msg_type IN ('text', 'game_invite', 'system', 'gif')
    )
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.chat_room_messages
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.chat_room_messages FROM falcon_app;
GRANT INSERT, SELECT, UPDATE, DELETE ON TABLE falcon.chat_room_messages TO falcon_app;
GRANT ALL ON TABLE falcon.chat_room_messages TO postgres;

CREATE INDEX IF NOT EXISTS idx_chat_room_messages_room_id
    ON falcon.chat_room_messages USING btree
    (room_id ASC NULLS LAST, created_at DESC NULLS FIRST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_room_messages_sender
    ON falcon.chat_room_messages USING btree
    (sender_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_room_messages_pinned
    ON falcon.chat_room_messages USING btree
    (room_id ASC NULLS LAST, is_pinned ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_room_messages_type
    ON falcon.chat_room_messages USING btree
    (msg_type COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_chat_room_messages_created_at
    ON falcon.chat_room_messages USING btree
    (created_at DESC NULLS FIRST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- TABLE: falcon.community_announcements
--
--  Owner / admin can post pinned announcements that appear in
--  the community chat header and optionally as a notification.
--  Ordered by pin_order ASC, then created_at DESC.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.community_announcements
(
    id          bigint      NOT NULL
                    DEFAULT nextval('falcon.community_announcements_id_seq'::regclass),

    -- Which room this announcement belongs to
    -- (usually the single community room, but can be a group room)
    room_id     bigint,

    -- Author must be admin or super_admin
    author_id   integer     NOT NULL,

    title       character varying(255) COLLATE pg_catalog."default" NOT NULL,
    body        text        COLLATE pg_catalog."default" NOT NULL,

    -- Lower number = higher priority in UI
    pin_order   integer     NOT NULL DEFAULT 0,

    -- Hidden announcements are kept for history but not shown
    is_visible  boolean     NOT NULL DEFAULT true,

    -- Optional expiry; NULL = never expires
    expires_at  timestamp with time zone,

    created_at  timestamp with time zone NOT NULL DEFAULT now(),
    updated_at  timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT community_announcements_pkey         PRIMARY KEY (id),
    CONSTRAINT community_announcements_room_fkey    FOREIGN KEY (room_id)
        REFERENCES falcon.chat_rooms (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE SET NULL,
    CONSTRAINT community_announcements_author_fkey  FOREIGN KEY (author_id)
        REFERENCES falcon.users (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE
)
TABLESPACE pg_default;

ALTER TABLE IF EXISTS falcon.community_announcements
    OWNER TO postgres;

REVOKE ALL ON TABLE falcon.community_announcements FROM falcon_app;
GRANT INSERT, SELECT, UPDATE, DELETE ON TABLE falcon.community_announcements TO falcon_app;
GRANT ALL ON TABLE falcon.community_announcements TO postgres;

CREATE INDEX IF NOT EXISTS idx_announcements_room_id
    ON falcon.community_announcements USING btree
    (room_id ASC NULLS LAST, pin_order ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_announcements_visible
    ON falcon.community_announcements USING btree
    (is_visible ASC NULLS LAST, expires_at ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

CREATE INDEX IF NOT EXISTS idx_announcements_author
    ON falcon.community_announcements USING btree
    (author_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;


-- ============================================================
-- SEED: Community room
--
--  Insert the single global community room so it always exists.
--  ON CONFLICT DO NOTHING makes this re-runnable.
-- ============================================================

INSERT INTO falcon.chat_rooms
    (id, room_type, name, description, created_by, max_members,
     is_locked, is_active, required_plan)
VALUES
    (1, 'community', 'Falcon Community', 'Main community chat for all Falcon Pickleball members.',
     NULL, NULL, false, true, 'basic')
ON CONFLICT (id) DO NOTHING;

-- Keep the sequence ahead of the seeded row
SELECT setval('falcon.chat_rooms_id_seq', GREATEST(
    (SELECT MAX(id) FROM falcon.chat_rooms),
    1
), true);


-- ============================================================
-- GRANT on sequences (so falcon_app can call nextval)
-- ============================================================

GRANT USAGE, SELECT ON SEQUENCE falcon.subscriptions_id_seq            TO falcon_app;
GRANT USAGE, SELECT ON SEQUENCE falcon.subscription_payments_id_seq    TO falcon_app;
GRANT USAGE, SELECT ON SEQUENCE falcon.chat_rooms_id_seq               TO falcon_app;
GRANT USAGE, SELECT ON SEQUENCE falcon.chat_room_members_id_seq        TO falcon_app;
GRANT USAGE, SELECT ON SEQUENCE falcon.chat_room_messages_id_seq       TO falcon_app;
GRANT USAGE, SELECT ON SEQUENCE falcon.community_announcements_id_seq  TO falcon_app;

-- ============================================================
-- END OF MIGRATION 004
-- ============================================================