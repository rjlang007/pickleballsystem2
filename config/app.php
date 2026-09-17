<?php
// ============================================================
//  FILE: config/app.php
//
//  PATCH v3 (Step 2 – Subscription config):
//  Added PayMongo key constants and webhook URL helper.
//
//  New constants:
//   - PAYMONGO_PUBLIC_KEY
//   - PAYMONGO_SECRET_KEY
//   - PAYMONGO_WEBHOOK_SECRET
//   - WEBHOOK_URL  (full URL to api/paymongo_webhook.php)
//
//  All other logic preserved from v2.
// ============================================================
if (defined('APP_ROOT')) return;

define('APP_ROOT', realpath(__DIR__ . '/..'));

require_once __DIR__ . '/alerting.php';

// ── Load .env directly (don't rely on config/db.php having run first) ──
// Root-level redirect stubs (index.php, register.php, login.php, etc.)
// only require this file, not db.php — if .env loading were left solely
// to db.php, APP_URL would fall back to a broken auto-detected value on
// those entry points. Loading it here too makes app.php self-sufficient.
if (file_exists(__DIR__ . '/../.env')) {
    $envLines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

$_envUrl = getenv('APP_URL');
if ($_envUrl) {
    define('APP_URL', rtrim($_envUrl, '/'));
} else {
    $isHttps = false;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $isHttps = true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $isHttps = true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
        $isHttps = true;
    }
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = '/' . ltrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $knownDirs  = ['/auth/', '/admin/', '/player/', '/superadmin/', '/court/', '/public/', '/api/', '/docs/', '/leaderboard/', '/tournament/'];
    $appPath    = '/';
    foreach ($knownDirs as $dir) {
        $pos = stripos($scriptName, $dir);
        if ($pos !== false) {
            $appPath = substr($scriptName, 0, $pos);
            if ($appPath === '') {
                $appPath = '/';
            }
            break;
        }
    }
    $appPath = ($appPath === '/') ? '' : rtrim($appPath, '/');
    define('APP_URL', $scheme . '://' . $host . $appPath);
}

define('APP_NAME', getenv('APP_NAME') ?: 'Falcon Pickleball Court');

if (!defined('IS_PRODUCTION')) {
    // RAILWAY_ENVIRONMENT is set to the environment's *name* (e.g.
    // "production", "staging", "pr-123") on every Railway environment,
    // not just prod — casting it to bool would treat ALL of them as
    // production. Only the literal string "production" counts.
    $railwayEnv = getenv('RAILWAY_ENVIRONMENT');
    if ($railwayEnv !== false && $railwayEnv !== '') {
        define('IS_PRODUCTION', $railwayEnv === 'production');
    } else {
        define('IS_PRODUCTION', (bool)(getenv('IS_PRODUCTION') ?: false));
    }
}

if (IS_PRODUCTION) {
    ini_set('display_errors',         '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors',         '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $formatted = sprintf('[PHP ERROR] %s in %s on line %d', $message, $file, $line);
    error_log($formatted);
    // Only page someone for severities that actually mean something's
    // broken — not every notice/warning, or a flaky legacy code path
    // would spam the webhook constantly and train everyone to ignore it.
    if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        alertOps($formatted, $file . ':' . $line . ':' . $message);
    }
    if (!defined('IS_PRODUCTION') || !IS_PRODUCTION) {
        http_response_code(500);
        echo '<pre style="color:red">' . htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    return true;
});

set_exception_handler(function (Throwable $exception) {
    $formatted = sprintf('[UNCAUGHT EXCEPTION] %s in %s on line %d', $exception->getMessage(), $exception->getFile(), $exception->getLine());
    error_log($formatted . "\n" . $exception->getTraceAsString());
    alertOps($formatted, $exception->getFile() . ':' . $exception->getLine() . ':' . $exception->getMessage());
    if (!defined('IS_PRODUCTION') || !IS_PRODUCTION) {
        http_response_code(500);
        echo '<pre style="color:red">' . htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    } else {
        http_response_code(500);
        echo 'An internal error occurred. Please try again later.';
    }
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $formatted = sprintf('[FATAL ERROR] %s in %s on line %d', $error['message'], $error['file'], $error['line']);
        error_log($formatted);
        alertOps($formatted, $error['file'] . ':' . $error['line'] . ':' . $error['message']);
        if (!defined('IS_PRODUCTION') || !IS_PRODUCTION) {
            echo '<pre style="color:red">' . htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        } else {
            echo 'An internal error occurred. Please try again later.';
        }
    }
});

// ── Constants ─────────────────────────────────────────────────
define('CREDIT_PER_GAME',   10.00);
define('PASS_DURATION_HRS',  8);
define('TOPUP_QR_MINS',     15);
define('LOW_CREDIT_ALERT',  10.00);
define('PLAYERS_PER_GAME',   4);

define('UPLOAD_SCREENSHOTS', APP_ROOT . '/uploads/screenshots/');
define('UPLOAD_AVATARS',     APP_ROOT . '/uploads/avatars/');
define('MAX_UPLOAD_MB',      25);

// ── Load Testing Constants ────────────────────────────────────
define('LOAD_TEST_CONCURRENCY', 5);
define('LOAD_TEST_REQUESTS',    50);
define('LOAD_TEST_TIMEOUT',     10);

define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN',       'admin');
define('ROLE_STAFF',       'staff');
define('ROLE_REFEREE',     'referee');
define('ROLE_PLAYER',      'player');
define('ADMIN_ROLES',      [ROLE_ADMIN, ROLE_SUPER_ADMIN]);
// Roles that operate the day-to-day floor (queueing, tournament ops) — admin/superadmin can also act as staff.
define('STAFF_ROLES',      [ROLE_STAFF, ROLE_ADMIN, ROLE_SUPER_ADMIN]);
// Roles allowed to score matches — admin/superadmin can also act as referee.
define('REFEREE_ROLES',    [ROLE_REFEREE, ROLE_ADMIN, ROLE_SUPER_ADMIN]);
define('ALL_ROLES',        [ROLE_PLAYER, ROLE_STAFF, ROLE_REFEREE, ROLE_ADMIN, ROLE_SUPER_ADMIN]);
// Roles that count as "staff of the business" for display purposes (not customers)
define('STAFF_ACCOUNT_ROLES', [ROLE_STAFF, ROLE_REFEREE]);

// ── Subscription Plan Constants ───────────────────────────────
define('PLAN_BASIC',   'basic');
define('PLAN_MEDIUM',  'medium');
define('PLAN_PREMIUM', 'premium');
define('ALL_PLANS',    [PLAN_BASIC, PLAN_MEDIUM, PLAN_PREMIUM]);

// Plan prices in PHP
define('PLAN_PRICE_BASIC',   499.00);
define('PLAN_PRICE_MEDIUM',  799.00);
define('PLAN_PRICE_PREMIUM', 1200.00);

// ── PayMongo Configuration ────────────────────────────────────
//
//  Set these in your environment / Railway variables:
//    PAYMONGO_PUBLIC_KEY      → pk_live_xxxx  (or pk_test_xxxx for dev)
//    PAYMONGO_SECRET_KEY      → sk_live_xxxx  (or sk_test_xxxx for dev)
//    PAYMONGO_WEBHOOK_SECRET  → whsec_xxxx    (from PayMongo dashboard)
//
define('PAYMONGO_PUBLIC_KEY',     getenv('PAYMONGO_PUBLIC_KEY')     ?: '');
define('PAYMONGO_SECRET_KEY',     getenv('PAYMONGO_SECRET_KEY')     ?: '');
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

// Full URL PayMongo will POST to on payment events.
// Override with PAYMONGO_WEBHOOK_URL env var if your webhook
// path differs from the default api/paymongo_webhook.php.
define('WEBHOOK_URL',
    getenv('PAYMONGO_WEBHOOK_URL') ?: APP_URL . '/api/paymongo_webhook.php'
);

// ── DB + Session (order matters) ──────────────────────────────
if (!function_exists('getDB')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/session.php'; // defines startDBSession()
startDBSession(getDB());               // now safe to call

// ── Security helpers ──────────────────────────────────────────
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/security.php';

// ── Helper functions ──────────────────────────────────────────
function getCourtCreditCost(int $courtId = 0): float {
    static $cache = [];
    if (isset($cache[$courtId])) return $cache[$courtId];
    try {
        $db = getDB();
        if ($courtId > 0) {
            $stmt = $db->prepare(
                "SELECT credit_cost FROM falcon.courts WHERE id = ? AND is_active = TRUE LIMIT 1"
            );
            $stmt->execute([$courtId]);
        } else {
            $stmt = $db->query(
                "SELECT credit_cost FROM falcon.courts WHERE is_active = TRUE ORDER BY id LIMIT 1"
            );
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$courtId] = $row ? (float)$row['credit_cost'] : CREDIT_PER_GAME;
    } catch (Throwable $e) {
        error_log('[getCourtCreditCost] ' . $e->getMessage());
        $cache[$courtId] = CREDIT_PER_GAME;
    }
    return $cache[$courtId];
}

// ── Subscription helper ───────────────────────────────────────
//
//  Returns the active subscription row for a user, or null.
//  A subscription is "active" when:
//    status = 'active'  AND  paid_until > NOW()
//
function getUserSubscription(int $userId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT *
              FROM falcon.subscriptions
             WHERE user_id = ?
             LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[getUserSubscription] ' . $e->getMessage());
        return null;
    }
}

function isSubscriptionActive(?array $sub): bool {
    if (!$sub) return false;
    if ($sub['status'] !== 'active') return false;
    if (empty($sub['paid_until'])) return false;
    return strtotime($sub['paid_until']) > time();
}

//  Quick one-liner: check by user ID
function userHasActiveSubscription(int $userId): bool {
    return isSubscriptionActive(getUserSubscription($userId));
}

//  Returns true if the user's plan is at least $requiredPlan.
//  Plan hierarchy: basic < medium < premium
function userPlanMeets(int $userId, string $requiredPlan): bool {
    $hierarchy = array_flip([PLAN_BASIC, PLAN_MEDIUM, PLAN_PREMIUM]);
    $sub = getUserSubscription($userId);
    if (!isSubscriptionActive($sub)) return false;
    $userRank     = $hierarchy[$sub['plan']]     ?? -1;
    $requiredRank = $hierarchy[$requiredPlan]     ?? 999;
    return $userRank >= $requiredRank;
}

// ── FIX v2: Accept $courtId so pass active/inactive check uses ──
// the correct court's credit_cost, not always court 1's cost.
function syncPassActive(PDO $db, int $userId, ?float $balance = null, int $courtId = 0): bool {
    if ($balance === null) {
        try {
            $stmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
            $stmt->execute([$userId]);
            $balance = (float)($stmt->fetchColumn() ?? 0.0);
        } catch (PDOException $e) {
            error_log('[syncPassActive] wallet fetch: ' . $e->getMessage());
            return false;
        }
    }
    $creditCost = getCourtCreditCost($courtId);
    $isActive   = ($balance >= $creditCost);
    try {
        $db->prepare("
            UPDATE falcon.player_passes
               SET is_active = ?
             WHERE user_id   = ?
        ")->execute([$isActive, $userId]);
    } catch (PDOException $e) {
        error_log('[syncPassActive] update: ' . $e->getMessage());
    }
    return $isActive;
}

function _sessionFingerprint(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return hash('sha256', $ua . '|' . APP_NAME . '|falcon_fp_v1');
}

function stampSessionFingerprint(): void   { $_SESSION['_fp'] = _sessionFingerprint(); }
function validateSessionFingerprint(): bool {
    if (!isset($_SESSION['_fp'])) return false;
    return hash_equals($_SESSION['_fp'], _sessionFingerprint());
}

function isLoggedIn(): bool  { return isset($_SESSION['user_id'], $_SESSION['_fp']); }
function isAdmin(): bool     { return isset($_SESSION['role']) && in_array($_SESSION['role'], ADMIN_ROLES, true); }
function isPlayer(): bool    { return ($_SESSION['role'] ?? '') === ROLE_PLAYER; }
function isImpersonating(): bool { return isset($_SESSION['_real_user_id']); }
function realUserId(): int   { return (int)($_SESSION['_real_user_id'] ?? $_SESSION['user_id'] ?? 0); }

// "Pure" staff/referee — does NOT include admin/super_admin (used for nav branching)
function isStaffOnly(): bool   { return ($_SESSION['role'] ?? '') === ROLE_STAFF; }
function isRefereeOnly(): bool { return ($_SESSION['role'] ?? '') === ROLE_REFEREE; }

// "Can act as" — includes admin/super_admin, who can always operate the floor / score matches
function isStaff(): bool   { return isset($_SESSION['role']) && in_array($_SESSION['role'], STAFF_ROLES, true); }
function isReferee(): bool { return isset($_SESSION['role']) && in_array($_SESSION['role'], REFEREE_ROLES, true); }

function isSuperAdmin(): bool {
    if (isset($_SESSION['_real_role'])) return $_SESSION['_real_role'] === ROLE_SUPER_ADMIN;
    return ($_SESSION['role'] ?? '') === ROLE_SUPER_ADMIN;
}

function redirect(string $path): never {
    $clean = str_replace(["\r", "\n"], '', APP_URL . '/' . ltrim($path, '/'));
    header('Location: ' . $clean);
    exit;
}

function roleDashboard(): string {
    if (isSuperAdmin())   return 'superadmin/dashboard.php';
    if (isAdmin())        return 'admin/dashboard.php';
    if (isRefereeOnly())  return 'referee/dashboard.php';
    if (isStaffOnly())    return 'staff/dashboard.php';
    return 'player/dashboard.php';
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'          => (int)$_SESSION['user_id'],
        'username'    => $_SESSION['username']  ?? '',
        'full_name'   => $_SESSION['full_name'] ?? '',
        'role'        => $_SESSION['role']      ?? ROLE_PLAYER,
        'avatar'      => $_SESSION['avatar']    ?? null,
        'avatar_path' => $_SESSION['avatar']    ?? null,
    ];
}

function currentUserRole(): string {
    if (!isLoggedIn()) return '';
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT role FROM falcon.users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['role'] ?? '';
    } catch (Throwable) {
        return $_SESSION['role'] ?? '';
    }
}

function setFlash(string $type, string $message): void {
    $allowed = ['success', 'error', 'warn', 'info'];
    $_SESSION['flash'] = [
        'type'    => in_array($type, $allowed, true) ? $type : 'info',
        'message' => $message,
    ];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function clean(?string $val): string {
    return htmlspecialchars(trim((string)$val), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Removed: "Legacy session migration: PHPSESSID → FALCON_SESS" ────
// This block adopted whatever session id arrived in the client-supplied
// PHPSESSID cookie (with use_strict_mode forced off) and copied that
// session's user_id/role into the live FALCON_SESS session — i.e. session
// fixation: an attacker supplying a chosen/known PHPSESSID value could get
// another user's identity copied into their own session. The one-time
// migration need (users who had an old PHPSESSID-named session from
// before the app renamed its cookie to FALCON_SESS) closed itself out
// within the 2-hour session TTL after that rename shipped (see
// config/session.php) — it had no legitimate purpose left. The identical
// pattern was also removed from api/chat.php.

require_once __DIR__ . '/../court/auto_end_games.php';