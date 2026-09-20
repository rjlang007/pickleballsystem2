<?php
// ============================================================
//  FILE: config/db.php
//  LOCAL-SAFE VERSION
//  - IS_PRODUCTION is NOT defined here (app.php owns it)
//  - Redis is optional; silently disabled if extension missing
// ============================================================
if (defined('DB_LOADED')) return;
define('DB_LOADED', true);

// Load .env file for local development
if (file_exists(__DIR__ . '/../.env')) {
    $envLines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue; // Skip invalid lines
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) { // Only set if not already set
            putenv("$key=$value");
        }
    }
}

// Parse DATABASE_URL when Railway provides the canonical connection string.
// Prefer it over legacy individual variables so a full URL accidentally
// supplied as PGHOST cannot be treated as a literal hostname.
$rawDbHost = getenv('PGHOST')     ?: getenv('DB_HOST');
$rawDbPort = getenv('PGPORT')     ?: getenv('DB_PORT');
$rawDbName = getenv('PGDATABASE') ?: getenv('DB_NAME');
$rawDbUser = getenv('PGUSER')     ?: getenv('DB_USER');
$rawDbPass = getenv('PGPASSWORD') ?: getenv('DB_PASS');

$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl) {
    $url = parse_url($databaseUrl);
    if (!empty($url['host'])) {
        $rawDbHost = $url['host'];
        $rawDbPort = $url['port'] ?? 5432;
        $rawDbName = ltrim($url['path'] ?? '', '/');
        $rawDbUser = $url['user'] ?? $rawDbUser;
        $rawDbPass = $url['pass'] ?? $rawDbPass;
    }
}

define('DB_HOST',   $rawDbHost ?: 'localhost');
define('DB_PORT',   $rawDbPort ?: '5432');
define('DB_NAME',   $rawDbName ?: 'railway');
define('DB_NAME_AUTOMATIC', empty($rawDbName));
define('DB_USER',   $rawDbUser ?: 'postgres');
define('DB_PASS',   $rawDbPass ?: '');
define('DB_SCHEMA', 'falcon');

function repairSerialSequence(PDO $pdo, string $qualifiedTable, string $column): void {
    $stmt = $pdo->prepare("SELECT pg_get_serial_sequence(?, ?) AS seq");
    $stmt->execute([$qualifiedTable, $column]);
    $sequence = $stmt->fetchColumn();
    if (!$sequence) {
        return;
    }

    $maxId = (int)$pdo->query("SELECT COALESCE(MAX($column), 0) FROM $qualifiedTable")->fetchColumn();
    $seqState = $pdo->query("SELECT last_value, is_called FROM $sequence")->fetch(PDO::FETCH_ASSOC);
    if (!$seqState) {
        return;
    }

    $lastValue = (int)$seqState['last_value'];
    if ($lastValue <= $maxId) {
        // Ensure the next nextval() returns max(id)+1 and avoids duplicate PK errors
        $pdo->exec("SELECT setval('$sequence', $maxId, TRUE)");
    }
}

function ensureCourtStatusView(PDO $pdo): void {
    // v2: also recreate if the view predates the is_queueable /
    // manual_status-aware live_status computation (court activation fix).
    $requiredCourtColumns = ['court_type', 'is_maintenance', 'manual_status', 'max_queue', 'credit_cost', 'game_duration', 'warmup_mins', 'pass_hours', 'sort_order', 'color', 'short_code', 'address', 'photo'];
    $columnStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'courts' AND column_name = ANY(?)");
    $columnStmt->execute(['{' . implode(',', $requiredCourtColumns) . '}']);
    if (count($columnStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($requiredCourtColumns)) {
        return;
    }
    $missing = !$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'v_court_status' AND column_name = 'is_queueable'")->fetchColumn();
    if (!$missing) {
        return;
    }

    $pdo->exec(<<<'SQL'
DROP VIEW IF EXISTS falcon.v_court_status;
CREATE VIEW falcon.v_court_status AS
SELECT
    c.id,
    c.name,
    c.description,
    c.court_type,
    c.is_active,
    c.is_maintenance,
    c.manual_status,
    c.max_queue,
    c.credit_cost,
    c.game_duration,
    c.warmup_mins,
    c.pass_hours,
    c.sort_order,
    c.color,
    c.short_code,
    c.address,
    c.photo,
    c.created_at,
    c.updated_at,
    -- ── live_status: single source of truth for court state ──
    -- Priority: maintenance > deactivated > admin manual override
    -- (occupied/reserved/tournament) > real active game > queue > available.
    CASE
        WHEN c.is_maintenance = TRUE THEN 'maintenance'
        WHEN c.is_active = FALSE THEN 'closed'
        WHEN c.manual_status = 'occupied'   THEN 'occupied'
        WHEN c.manual_status = 'reserved'   THEN 'reserved'
        WHEN c.manual_status = 'tournament' THEN 'tournament'
        WHEN gs.id IS NOT NULL THEN
            CASE
                WHEN gs.session_type = 'reservation' THEN 'reserved'
                ELSE 'active'
            END
        WHEN gq.queued > 0 THEN 'queuing'
        ELSE 'available'
    END AS live_status,
    -- ── is_queueable: TRUE only for courts that should receive new ──
    -- queue joins / open-play walk-ins right now. Deactivated,
    -- maintenance, admin-reserved, admin-occupied, tournament, and
    -- courts with a real game in progress are all excluded.
    (
        c.is_active = TRUE
        AND c.is_maintenance = FALSE
        AND (c.manual_status IS NULL OR c.manual_status = 'open_play')
        AND gs.id IS NULL
    ) AS is_queueable,
    COALESCE(gp.player_count, 0) AS players_on_court,
    COALESCE(gq.queued, 0) AS queue_count,
    gs.started_at AS game_started_at,
    gs.duration_mins AS game_duration_mins,
    gs.id AS active_session_id
FROM falcon.courts c
LEFT JOIN falcon.game_sessions gs
    ON gs.court_id = c.id AND gs.status = 'active'
LEFT JOIN (
    SELECT session_id, COUNT(*) AS player_count
    FROM falcon.game_players
    GROUP BY session_id
) gp ON gp.session_id = gs.id
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS queued
    FROM falcon.game_queue
    WHERE session_id IS NULL AND court_id = c.id
) gq ON true
ORDER BY c.sort_order, c.id;
SQL
);
}

// ── Ensure leaderboard table has required columns ─────────────
function ensureLeaderboardTable(PDO $pdo): void
{
    // ── 1. Create the table if it doesn't exist at all ────────
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS falcon.leaderboard (
            id                SERIAL PRIMARY KEY,
            player_id         INTEGER      NOT NULL
                                  REFERENCES falcon.users(id) ON DELETE CASCADE,
            season            SMALLINT     NOT NULL
                                  DEFAULT EXTRACT(YEAR FROM NOW())::SMALLINT,
            total_points      INTEGER      NOT NULL DEFAULT 0,
            total_wins        INTEGER      NOT NULL DEFAULT 0,
            total_tournaments INTEGER      NOT NULL DEFAULT 0,
            rank              INTEGER,
            last_update       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            UNIQUE (player_id, season)
        )
SQL
    );

    // ── 2. Add any columns that older installations might lack ─
    $required = [
        'total_wins'       => 'INTEGER NOT NULL DEFAULT 0',
        'total_tournaments'=> 'INTEGER NOT NULL DEFAULT 0',
        'rank'             => 'INTEGER',
        'last_update'      => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
    ];

    foreach ($required as $col => $def) {
        $exists = $pdo->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'falcon'
                AND table_name   = 'leaderboard'
                AND column_name  = '$col'"
        )->fetchColumn();

        if (!$exists) {
            try {
                $pdo->exec("ALTER TABLE falcon.leaderboard ADD COLUMN $col $def");
                error_log("[DB] Added column $col to leaderboard");
            } catch (PDOException $e) {
                error_log("[DB] Could not add $col: " . $e->getMessage());
            }
        }
    }

    // ── 3. Remove stale columns that the engine no longer uses ─
    // (tournament_id, total_losses) — only drop if they exist,
    // so this is safe to run against any DB state.
    $stale = ['tournament_id', 'total_losses', 'last_updated'];
    foreach ($stale as $col) {
        $exists = $pdo->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'falcon'
                AND table_name   = 'leaderboard'
                AND column_name  = '$col'"
        )->fetchColumn();

        if ($exists) {
            try {
                $pdo->exec("ALTER TABLE falcon.leaderboard DROP COLUMN $col");
                error_log("[DB] Dropped stale column $col from leaderboard");
            } catch (PDOException $e) {
                error_log("[DB] Could not drop $col: " . $e->getMessage());
            }
        }
    }

    // ── 4. Ensure the unique index exists ─────────────────────
    try {
        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_lb_player_season
                 ON falcon.leaderboard (player_id, season)"
        );
    } catch (PDOException $e) {
        error_log("[DB] idx_lb_player_season: " . $e->getMessage());
    }

    // ── 5. Clean up old partial indexes that reference
    //       tournament_id (can't exist without the column) ──────
    foreach (['idx_lb_season_agg', 'idx_lb_season_rank', 'idx_lb_player'] as $idx) {
        try {
            $pdo->exec("DROP INDEX IF EXISTS falcon.{$idx}");
        } catch (PDOException $e) {
            // Non-fatal — index may not exist
        }
    }
}

// ── Club-wide announcements table (self-healing, see migrations/015) ──
function ensureAnnouncementsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS falcon.announcements (
            id           SERIAL PRIMARY KEY,
            title        VARCHAR(200) NOT NULL,
            body         TEXT         NOT NULL,
            audience     VARCHAR(20)  NOT NULL DEFAULT 'all'
                             CHECK (audience IN ('all','player','staff','referee','admin')),
            is_pinned    BOOLEAN      NOT NULL DEFAULT FALSE,
            is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
            created_by   INTEGER      REFERENCES falcon.users(id) ON DELETE SET NULL,
            starts_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            expires_at   TIMESTAMPTZ,
            created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )
SQL
    );

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_announcements_active ON falcon.announcements (is_active, is_pinned, starts_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_announcements_audience ON falcon.announcements (audience)");
    } catch (PDOException $e) {
        error_log('[DB] announcements indexes: ' . $e->getMessage());
    }
}

function ensureUserAvatarColumns(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        ALTER TABLE falcon.users
            ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255),
            ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(255),
            ADD COLUMN IF NOT EXISTS display_name VARCHAR(120),
            ADD COLUMN IF NOT EXISTS show_display_name BOOLEAN NOT NULL DEFAULT TRUE
SQL
    );

    $pdo->exec(<<<'SQL'
        UPDATE falcon.users
           SET avatar_path = COALESCE(avatar_path, avatar),
               avatar_url = COALESCE(avatar_url, avatar_path, avatar)
         WHERE avatar_path IS NULL OR avatar_url IS NULL
SQL
    );
}

function ensureTransactionCompatibilityColumns(PDO $pdo): void
{
    try {
        $pdo->exec(<<<'SQL'
            ALTER TABLE falcon.transactions
                ADD COLUMN IF NOT EXISTS method VARCHAR(50),
                ADD COLUMN IF NOT EXISTS note VARCHAR(500),
                ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'approved',
                ADD COLUMN IF NOT EXISTS processed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100),
                ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)
SQL
        );

        $pdo->exec(<<<'SQL'
            UPDATE falcon.transactions
               SET reference_no = COALESCE(reference_no, reference_number),
                   reference_number = COALESCE(reference_number, reference_no)
             WHERE reference_no IS NULL OR reference_number IS NULL
SQL
        );
    } catch (PDOException $e) {
        error_log('[DB] transaction compatibility columns: ' . $e->getMessage());
    }
}

function ensureAchievementsTable(PDO $pdo): void
{
    if (!$pdo->query("SELECT to_regclass('falcon.tournaments')")->fetchColumn()) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS falcon.achievements (
            id SERIAL PRIMARY KEY,
            player_id INTEGER NOT NULL REFERENCES falcon.users(id) ON DELETE CASCADE,
            achievement_type VARCHAR(60) NOT NULL,
            label VARCHAR(120),
            description TEXT,
            tournament_id INTEGER REFERENCES falcon.tournaments(id) ON DELETE SET NULL,
            achieved_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (player_id, achievement_type)
        )
SQL
    );
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_achievements_player ON falcon.achievements(player_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_achievements_player_type ON falcon.achievements(player_id, achievement_type)");
}

function ensureFoodOrderingSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE SEQUENCE IF NOT EXISTS falcon.food_order_number_seq START WITH 1 INCREMENT BY 1");
        $pdo->exec(<<<'SQL'
            ALTER TABLE falcon.food_orders
                ADD COLUMN IF NOT EXISTS order_number VARCHAR(20),
                ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'pending',
                ADD COLUMN IF NOT EXISTS payment_method VARCHAR(10) NOT NULL DEFAULT 'wallet',
                ADD COLUMN IF NOT EXISTS payment_status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
                ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(10) NOT NULL DEFAULT 'pickup',
                ADD COLUMN IF NOT EXISTS court_id INTEGER REFERENCES falcon.courts(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS subtotal NUMERIC(10,2) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS total_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS notes VARCHAR(255),
                ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ADD COLUMN IF NOT EXISTS ready_at TIMESTAMP,
                ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP,
                ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP,
                ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500),
                ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES falcon.users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP
SQL
        );
            $pdo->exec("ALTER TABLE falcon.food_orders DROP CONSTRAINT IF EXISTS food_orders_status_check");
            $pdo->exec("ALTER TABLE falcon.food_orders ADD CONSTRAINT food_orders_status_check CHECK (status IN ('pending','approved','preparing','ready','completed','rejected','cancelled'))");
        $pdo->exec(<<<'SQL'
            ALTER TABLE falcon.food_order_items
                ADD COLUMN IF NOT EXISTS food_item_id INTEGER REFERENCES falcon.food_items(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS item_name VARCHAR(200),
                ADD COLUMN IF NOT EXISTS unit_price NUMERIC(8,2),
                ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS subtotal NUMERIC(10,2)
SQL
        );
    } catch (PDOException $e) {
        error_log('[DB] food ordering schema: ' . $e->getMessage());
    }
}

// ── PDO singleton ─────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dbNames = [DB_NAME];
    if (defined('DB_NAME_AUTOMATIC') && DB_NAME_AUTOMATIC) {
        $dbNames[] = 'pickleball';
    }

    $lastException = null;
    foreach ($dbNames as $candidateDb) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=prefer;connect_timeout=5',
            DB_HOST, DB_PORT, $candidateDb
        );

        for ($attempt = 1; $attempt <= 2 && $pdo === null; $attempt++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 6,
                    PDO::ATTR_PERSISTENT         => false,
                ]);

                $pdo->exec("SET search_path TO falcon, public");
                $pdo->exec("SET TIME ZONE 'Asia/Manila'");
                $pdo->exec("SET statement_timeout = '10s'");
                $pdo->exec("SET lock_timeout = '3s'");
                $pdo->exec("SET application_name = 'falcon_web'");
                if (!defined('MIGRATION_RUNNER')) {
                    ensureCourtStatusView($pdo);
                    ensureLeaderboardTable($pdo);
                    ensureAnnouncementsTable($pdo);
                    ensureUserAvatarColumns($pdo);
                    ensureTransactionCompatibilityColumns($pdo);
                    ensureAchievementsTable($pdo);
                    ensureFoodOrderingSchema($pdo);
                    repairSerialSequence($pdo, 'falcon.transactions', 'id');
                }
                break;
            } catch (PDOException $e) {
                $lastException = $e;
                $pdo = null;
                if (!defined('DB_NAME_AUTOMATIC') || !DB_NAME_AUTOMATIC || $candidateDb === DB_NAME) {
                    error_log("[DB] Connection attempt {$attempt} failed for database {$candidateDb}: " . $e->getMessage());
                }
                if ($attempt < 2 && str_starts_with($e->getCode(), '08')) {
                    usleep(150000);
                    continue;
                }
                break;
            }
        }

        if ($pdo !== null) break;
    }

    if ($pdo === null) {
        $e = $lastException ?: new PDOException('Unable to establish database connection.');
        error_log('[DB] Connection failed: ' . $e->getMessage());

        // Show real error in dev, generic message in prod
        $isProd = defined('IS_PRODUCTION') ? IS_PRODUCTION
                : (getenv('RAILWAY_ENVIRONMENT') === 'production');
        if (!$isProd) {
            http_response_code(500);
            die('<pre style="color:red">[DB ERROR] ' . htmlspecialchars($e->getMessage()) . '</pre>');
        }
        http_response_code(500);
        die('Database unavailable. Please try again later.');
    }

    return $pdo;
}

// Maintain compatibility with legacy scripts that expect $pdo in global scope.
$pdo = getDB();

// ── Optional Redis singleton ──────────────────────────────────
// Returns null if Redis PHP extension is not installed — callers
// must handle null (cache simply disabled in local dev).
function getRedis() {
    static $redis = null;
    static $tried = false;
    if ($tried) return $redis;
    $tried = true;

    if (!extension_loaded('redis')) {
        // Normal in local dev — Redis is optional
        return null;
    }

    $redisUrl = getenv('REDIS_URL') ?: 'redis://localhost:6379';
    try {
        $redis  = new Redis();
        $parsed = parse_url($redisUrl);
        $redis->connect($parsed['host'] ?? 'localhost', (int)($parsed['port'] ?? 6379));
        if (!empty($parsed['pass'])) $redis->auth($parsed['pass']);
        $redis->select(0);
    } catch (Exception $e) {
        error_log('[Redis] Connection failed: ' . $e->getMessage());
        $redis = null;
    }
    return $redis;
}