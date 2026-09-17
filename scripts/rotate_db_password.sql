-- ============================================================
-- Rotate the Falcon DB credential and (recommended) move off
-- the postgres superuser onto a scoped app role.
--
-- Run this against your ACTUAL live database (Railway / hosting
-- panel's SQL console, or `psql`), not just locally.
-- ============================================================

-- Option A: quick rotation, keep using the postgres superuser
-- (fine for right now, but do Option B before you go live)
ALTER USER postgres WITH PASSWORD 'REPLACE_WITH_NEW_PASSWORD';

-- Option B (recommended): scoped app role instead of superuser
CREATE ROLE falcon_app LOGIN PASSWORD 'REPLACE_WITH_NEW_PASSWORD';
GRANT USAGE ON SCHEMA falcon TO falcon_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA falcon TO falcon_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA falcon TO falcon_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA falcon
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO falcon_app;

-- After Option B, set in your hosting env vars (not local .env):
--   DB_USER=falcon_app
--   DB_PASS=<the password you used above>
-- Then restart the app, confirm it's healthy, THEN:
-- REVOKE ALL PRIVILEGES ON DATABASE falcon FROM postgres; -- optional hardening, only once falcon_app is confirmed working
