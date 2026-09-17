-- Missing reservation lifecycle tables and community / payout features
-- Valid falcon.reservations.status values:
--   pending | confirmed | cancelled | completed | no_show | disputed

CREATE SCHEMA IF NOT EXISTS falcon;

CREATE TABLE falcon.reviews (
    id SERIAL PRIMARY KEY,
    reservation_id INTEGER NOT NULL REFERENCES falcon.reservations(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment VARCHAR(500),
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (reservation_id, user_id)
);

CREATE INDEX idx_reviews_reservation_id ON falcon.reviews (reservation_id);
CREATE INDEX idx_reviews_user_id ON falcon.reviews (user_id);

CREATE TABLE falcon.disputes (
    id SERIAL PRIMARY KEY,
    reservation_id INTEGER NOT NULL REFERENCES falcon.reservations(id) ON DELETE CASCADE,
    filed_by INTEGER NOT NULL REFERENCES falcon.users(id),
    reason TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'open',
    admin_note VARCHAR(500),
    resolved_by INTEGER REFERENCES falcon.users(id),
    resolved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (reservation_id),
    CHECK (status IN ('open', 'resolved', 'dismissed'))
);

CREATE INDEX idx_disputes_reservation_id ON falcon.disputes (reservation_id);
CREATE INDEX idx_disputes_filed_by ON falcon.disputes (filed_by);
CREATE INDEX idx_disputes_resolved_by ON falcon.disputes (resolved_by);
CREATE INDEX idx_disputes_status ON falcon.disputes (status);

CREATE TABLE falcon.community_posts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    content TEXT NOT NULL CHECK (char_length(content) > 0),
    media_url VARCHAR(255),
    is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_community_posts_user_id ON falcon.community_posts (user_id);
CREATE INDEX idx_community_posts_is_pinned ON falcon.community_posts (is_pinned);
CREATE INDEX idx_community_posts_is_hidden ON falcon.community_posts (is_hidden);

CREATE TABLE falcon.post_comments (
    id SERIAL PRIMARY KEY,
    post_id INTEGER NOT NULL REFERENCES falcon.community_posts(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    content VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_post_comments_post_id ON falcon.post_comments (post_id);
CREATE INDEX idx_post_comments_user_id ON falcon.post_comments (user_id);

CREATE TABLE falcon.withdrawal_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    amount NUMERIC(10,2) NOT NULL CHECK (amount >= 100),
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(500),
    processed_by INTEGER REFERENCES falcon.users(id),
    processed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    CHECK (status IN ('pending', 'approved', 'rejected'))
);

CREATE INDEX idx_withdrawal_requests_user_id ON falcon.withdrawal_requests (user_id);
CREATE INDEX idx_withdrawal_requests_processed_by ON falcon.withdrawal_requests (processed_by);
CREATE INDEX idx_withdrawal_requests_status ON falcon.withdrawal_requests (status);
