-- Allow staff to add walk-in players who do not have a site account.
-- Guest records have no usable login credentials and only exist to preserve
-- their name in the open-play roster, matches, and results.
ALTER TABLE falcon.users
    ADD COLUMN IF NOT EXISTS is_guest BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_users_is_guest ON falcon.users (is_guest);