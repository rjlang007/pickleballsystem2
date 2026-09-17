-- ============================================================
--  MIGRATION: 010_superadmin_monitoring_tables.sql
--  Superadmin monitoring/ops tables.
--
--  These three tables are already READ from and WRITTEN to by:
--    - includes/activity_logger.php   -> falcon.activity_logs
--    - superadmin/activity_monitor.php -> falcon.activity_logs
--    - superadmin/impersonate.php      -> falcon.impersonation_logs
--    - superadmin/impersonate_stop.php -> falcon.impersonation_logs
--    - superadmin/dashboard.php        -> falcon.impersonation_logs
--    - includes/monitoring.php         -> falcon.performance_logs
--
--  ...but none of them were ever created in a migration file, so
--  on a fresh database: activity logging silently no-ops (caught
--  by try/catch), the "recent admin actions" panel is always
--  empty, slow-operation tracking silently no-ops, and
--  impersonating a user throws an uncaught PDOException (that
--  INSERT was never wrapped in try/catch). This migration adds
--  them. All statements are idempotent / safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS falcon.activity_logs (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
    username    VARCHAR(100),
    role        VARCHAR(30),
    action      VARCHAR(255) NOT NULL,
    category    VARCHAR(60)  NOT NULL DEFAULT 'general',
    severity    VARCHAR(20)  NOT NULL DEFAULT 'normal', -- normal | warning | critical
    csrf_token  VARCHAR(255),
    ip_address  VARCHAR(64),
    user_agent  TEXT,
    details     TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON falcon.activity_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id    ON falcon.activity_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_category   ON falcon.activity_logs (category);
CREATE INDEX IF NOT EXISTS idx_activity_logs_severity   ON falcon.activity_logs (severity);

CREATE TABLE IF NOT EXISTS falcon.impersonation_logs (
    id              SERIAL PRIMARY KEY,
    super_admin_id  INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    target_user_id  INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
    ip_address      VARCHAR(64),
    started_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at        TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_impersonation_logs_started_at ON falcon.impersonation_logs (started_at DESC);
CREATE INDEX IF NOT EXISTS idx_impersonation_logs_super_admin ON falcon.impersonation_logs (super_admin_id);

CREATE TABLE IF NOT EXISTS falcon.performance_logs (
    id          SERIAL PRIMARY KEY,
    operation   VARCHAR(255) NOT NULL,
    duration    NUMERIC(10, 4) NOT NULL, -- seconds
    metadata    TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_performance_logs_created_at ON falcon.performance_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_performance_logs_duration   ON falcon.performance_logs (duration DESC);
