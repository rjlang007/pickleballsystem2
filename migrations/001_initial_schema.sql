CREATE SCHEMA IF NOT EXISTS falcon;

-- ============================================================
-- CORE AUTHENTICATION & USERS
-- ============================================================

CREATE TABLE falcon.users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255),
    phone VARCHAR(20),
    avatar VARCHAR(255),
    avatar_path VARCHAR(255),
    avatar_url VARCHAR(255),
    role VARCHAR(50) NOT NULL DEFAULT 'player',
    is_active BOOLEAN DEFAULT TRUE,
    is_banned BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_username ON falcon.users (username);
CREATE INDEX idx_users_email ON falcon.users (email);
CREATE INDEX idx_users_role ON falcon.users (role);
CREATE INDEX idx_users_is_active ON falcon.users (is_active);

-- ============================================================
-- WALLETS & CREDIT SYSTEM
-- ============================================================

CREATE TABLE falcon.wallets (
    user_id INTEGER PRIMARY KEY REFERENCES falcon.users(id) ON DELETE CASCADE,
    balance NUMERIC(10,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'PHP',
    last_topup_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT balance_non_negative CHECK (balance >= 0)
);

CREATE INDEX idx_wallets_balance ON falcon.wallets (balance);

-- ============================================================
-- COURTS & VENUE CONFIGURATION
-- ============================================================

CREATE TABLE falcon.courts (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address VARCHAR(255),
    location POINT,
    is_active BOOLEAN DEFAULT TRUE,
    max_queue INTEGER DEFAULT 4,
    credit_cost NUMERIC(8,2) NOT NULL DEFAULT 10,
    game_duration INTEGER NOT NULL DEFAULT 60,
    warmup_mins INTEGER DEFAULT 5,
    pass_hours NUMERIC(4,1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_courts_is_active ON falcon.courts (is_active);
CREATE INDEX idx_courts_name ON falcon.courts (name);

-- ============================================================
-- COURT OPERATIONS
-- ============================================================

CREATE TABLE falcon.court_hours (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL,
    open_time TIME NOT NULL,
    close_time TIME NOT NULL,
    is_closed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(court_id, day_of_week)
);

CREATE INDEX idx_court_hours_court_id ON falcon.court_hours (court_id);

CREATE TABLE falcon.court_slot_modes (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    slot_date DATE,
    day_of_week INTEGER,
    time_from TIME NOT NULL,
    time_to TIME NOT NULL,
    mode VARCHAR(50) NOT NULL DEFAULT 'open_play',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_court_slot_modes_court_id ON falcon.court_slot_modes (court_id);
CREATE INDEX idx_court_slot_modes_date ON falcon.court_slot_modes (slot_date);

CREATE TABLE falcon.court_settings (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    key VARCHAR(100) NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(court_id, key)
);

CREATE INDEX idx_court_settings_court_id ON falcon.court_settings (court_id);

-- ============================================================
-- PLAYER PASSES & QR CODES
-- ============================================================

CREATE TABLE falcon.player_passes (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    qr_token VARCHAR(255) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    expires_at TIMESTAMP NOT NULL,
    last_scanned_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_player_passes_user_id ON falcon.player_passes (user_id);
CREATE INDEX idx_player_passes_qr_token ON falcon.player_passes (qr_token);
CREATE INDEX idx_player_passes_is_active ON falcon.player_passes (is_active);
CREATE INDEX idx_player_passes_expires_at ON falcon.player_passes (expires_at);

-- ============================================================
-- RESERVATIONS & BOOKINGS
-- ============================================================

CREATE TABLE falcon.reservations (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    slot_end TIME NOT NULL,
    party_size INTEGER NOT NULL DEFAULT 1,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50),
    payment_status VARCHAR(50) DEFAULT 'unpaid',
    payment_amount NUMERIC(10,2),
    payment_ref VARCHAR(100),
    payment_proof VARCHAR(255),
    booking_group_id VARCHAR(36),
    admin_note VARCHAR(500),
    booking_confirmed_at TIMESTAMP,
    booking_confirmed_by INTEGER REFERENCES falcon.users(id),
    note VARCHAR(300),
    arrived_at TIMESTAMP,
    late_minutes INTEGER,
    session_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(court_id, user_id, slot_date, slot_time)
);

CREATE INDEX idx_reservations_user_id ON falcon.reservations (user_id);
CREATE INDEX idx_reservations_court_id ON falcon.reservations (court_id);
CREATE INDEX idx_reservations_slot_date ON falcon.reservations (slot_date);
CREATE INDEX idx_reservations_status ON falcon.reservations (status);
CREATE INDEX idx_reservations_payment_status ON falcon.reservations (payment_status);
CREATE INDEX idx_reservations_booking_group_id ON falcon.reservations (booking_group_id);

-- ============================================================
-- GAME SESSIONS & PLAYERS
-- ============================================================

CREATE TABLE falcon.game_sessions (
    id SERIAL PRIMARY KEY,
    court_id INTEGER NOT NULL REFERENCES falcon.courts(id) ON DELETE CASCADE,
    status VARCHAR(50) NOT NULL DEFAULT 'waiting',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP,
    duration_mins INTEGER,
    session_type VARCHAR(50),
    reservation_id INTEGER REFERENCES falcon.reservations(id) ON DELETE SET NULL,
    total_credits_charged NUMERIC(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_game_sessions_court_id ON falcon.game_sessions (court_id);
CREATE INDEX idx_game_sessions_status ON falcon.game_sessions (status);
CREATE INDEX idx_game_sessions_started_at ON falcon.game_sessions (started_at);

CREATE TABLE falcon.game_players (
    id SERIAL PRIMARY KEY,
    session_id INTEGER NOT NULL REFERENCES falcon.game_sessions(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    credits_charged NUMERIC(10,2),
    payment_status VARCHAR(50),
    position INTEGER,
    status VARCHAR(50) DEFAULT 'joined'
);

CREATE INDEX idx_game_players_session_id ON falcon.game_players (session_id);
CREATE INDEX idx_game_players_user_id ON falcon.game_players (user_id);

CREATE TABLE falcon.game_queue (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    pass_id INTEGER REFERENCES falcon.player_passes(id) ON DELETE SET NULL,
    session_id INTEGER REFERENCES falcon.game_sessions(id) ON DELETE CASCADE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_game_queue_user_id ON falcon.game_queue (user_id);
CREATE INDEX idx_game_queue_session_id ON falcon.game_queue (session_id);

-- ============================================================
-- TRANSACTIONS & PAYMENTS
-- ============================================================

CREATE TABLE falcon.transactions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    type VARCHAR(50) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    reason VARCHAR(255),
    related_table VARCHAR(100),
    related_id INTEGER,
    balance_before NUMERIC(10,2),
    balance_after NUMERIC(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_transactions_user_id ON falcon.transactions (user_id);
CREATE INDEX idx_transactions_type ON falcon.transactions (type);
CREATE INDEX idx_transactions_created_at ON falcon.transactions (created_at);

CREATE TABLE falcon.topup_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    amount NUMERIC(10,2) NOT NULL,
    method VARCHAR(50),
    status VARCHAR(50) DEFAULT 'pending',
    reference_number VARCHAR(100),
    proof_image VARCHAR(255),
    admin_comment VARCHAR(500),
    processed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    processed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_topup_requests_user_id ON falcon.topup_requests (user_id);
CREATE INDEX idx_topup_requests_status ON falcon.topup_requests (status);

CREATE TABLE falcon.payment_settings (
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) NOT NULL UNIQUE,
    value TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- NOTIFICATIONS & MESSAGING
-- ============================================================

CREATE TABLE falcon.notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    title VARCHAR(255),
    message TEXT NOT NULL,
    type VARCHAR(50),
    is_read BOOLEAN DEFAULT FALSE,
    link VARCHAR(255),
    reservation_id INTEGER REFERENCES falcon.reservations(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notifications_user_id ON falcon.notifications (user_id);
CREATE INDEX idx_notifications_is_read ON falcon.notifications (is_read);

CREATE TABLE falcon.chat_conversations (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_chat_conversations_user_id ON falcon.chat_conversations (user_id);

CREATE TABLE falcon.chat_messages (
    id SERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES falcon.chat_conversations(id) ON DELETE CASCADE,
    sender_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_chat_messages_conversation_id ON falcon.chat_messages (conversation_id);
CREATE INDEX idx_chat_messages_sender_id ON falcon.chat_messages (sender_id);

-- ============================================================
-- SECURITY & AUDITING
-- ============================================================

CREATE TABLE falcon.scan_logs (
    id SERIAL PRIMARY KEY,
    qr_token VARCHAR(255),
    user_id INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    status VARCHAR(50),
    message TEXT,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_scan_logs_qr_token ON falcon.scan_logs (qr_token);
CREATE INDEX idx_scan_logs_user_id ON falcon.scan_logs (user_id);
CREATE INDEX idx_scan_logs_scanned_at ON falcon.scan_logs (scanned_at);

CREATE TABLE falcon.audit_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    ip_address INET,
    user_agent VARCHAR(500),
    action VARCHAR(100),
    table_name VARCHAR(100),
    record_id INTEGER,
    old_data JSONB,
    new_data JSONB,
    result VARCHAR(50),
    notes VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_log_user_id ON falcon.audit_log (user_id);
CREATE INDEX idx_audit_log_action ON falcon.audit_log (action);
CREATE INDEX idx_audit_log_created_at ON falcon.audit_log (created_at);

CREATE TABLE falcon.failed_logins (
    id SERIAL PRIMARY KEY,
    ip_address INET,
    username VARCHAR(100),
    reason VARCHAR(200),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_failed_logins_ip_address ON falcon.failed_logins (ip_address);
CREATE INDEX idx_failed_logins_attempted_at ON falcon.failed_logins (attempted_at);

CREATE TABLE falcon.blocked_ips (
    id SERIAL PRIMARY KEY,
    ip_address INET NOT NULL UNIQUE,
    reason VARCHAR(255),
    blocked_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    is_active BOOLEAN DEFAULT TRUE,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_blocked_ips_ip_address ON falcon.blocked_ips (ip_address);
CREATE INDEX idx_blocked_ips_is_active ON falcon.blocked_ips (is_active);

-- ============================================================
-- SESSION MANAGEMENT
-- ============================================================

CREATE TABLE falcon.php_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_php_sessions_updated_at ON falcon.php_sessions (updated_at);

-- ============================================================
-- PASSWORD RESET
-- ============================================================

CREATE TABLE falcon.password_resets (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_password_resets_token ON falcon.password_resets (token);
CREATE INDEX idx_password_resets_user_id ON falcon.password_resets (user_id);

-- ============================================================
-- CONTENT MANAGEMENT
-- ============================================================

CREATE TABLE falcon.site_content (
    id SERIAL PRIMARY KEY,
    section VARCHAR(100),
    key VARCHAR(100),
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(section, key)
);

-- ============================================================
-- ACTIVITIES & PROGRAMS
-- ============================================================

CREATE TABLE falcon.activity_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    icon VARCHAR(100),
    price_per_hour NUMERIC(8,2),
    flat_price NUMERIC(8,2),
    pricing_note VARCHAR(255),
    photo VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_activity_types_is_active ON falcon.activity_types (is_active);
CREATE INDEX idx_activity_types_sort_order ON falcon.activity_types (sort_order);

CREATE TABLE falcon.activity_bookings (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    activity_type_id INTEGER NOT NULL REFERENCES falcon.activity_types(id) ON DELETE CASCADE,
    booking_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    status VARCHAR(50) DEFAULT 'pending',
    payment_status VARCHAR(50) DEFAULT 'unpaid',
    party_size INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_activity_bookings_user_id ON falcon.activity_bookings (user_id);
CREATE INDEX idx_activity_bookings_activity_type_id ON falcon.activity_bookings (activity_type_id);
CREATE INDEX idx_activity_bookings_booking_date ON falcon.activity_bookings (booking_date);

-- ============================================================
-- MEMBERSHIP & PLANS
-- ============================================================

CREATE TABLE falcon.membership_plans (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SCHEDULING & EVENTS
-- ============================================================

CREATE TABLE falcon.schedule_slots (
    id SERIAL PRIMARY KEY,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE falcon.events (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255),
    description TEXT,
    tag VARCHAR(100),
    event_date DATE,
    time_info VARCHAR(100),
    slots_info VARCHAR(100),
    price_info VARCHAR(100),
    is_featured BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_events_event_date ON falcon.events (event_date);
CREATE INDEX idx_events_is_active ON falcon.events (is_active);

-- ============================================================
-- TRAINING & SHOP
-- ============================================================

CREATE TABLE falcon.training_programs (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255),
    description TEXT,
    price_label VARCHAR(100),
    color VARCHAR(20) DEFAULT 'green',
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE falcon.shop_items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255),
    category VARCHAR(100),
    price NUMERIC(10,2),
    image_path VARCHAR(255),
    badge VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- End of migration v1.0
