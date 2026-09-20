<?php
// ============================================================
//  FILE: config/security.php
// ============================================================
if (defined('SECURITY_LOADED')) return;
define('SECURITY_LOADED', true);

define('SESSION_IDLE_TIMEOUT', 20 * 60);
define('SESSION_WARN_BEFORE',   2 * 60);
define('RATE_LIMIT_DIR', sys_get_temp_dir() . '/falcon_rate_limits/');

function csrfNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}
function getCspNonce(): string {
    return csrfNonce();
}
function sendSecurityHeaders(): void {
    $isProduction = defined('IS_PRODUCTION') && IS_PRODUCTION;
    $nonce        = csrfNonce();
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
    if ($isProduction) {
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . $url, true, 301);
            exit;
        }
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

    // Build the server's own origin so LAN devices (e.g. 192.168.x.x) are
    // explicitly whitelisted — CSP does NOT support IP wildcards.
    $scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $serverOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$csp = implode('; ', array_filter([
    "default-src 'self'",
    "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://unpkg.com",
    "style-src-attr 'unsafe-inline'",
    "script-src 'self' 'nonce-{$nonce}' https://unpkg.com https://fonts.googleapis.com",
    "script-src-attr 'unsafe-inline'",
    "font-src 'self' https://fonts.gstatic.com",
    "img-src 'self' data: blob: "
        . "https://res.cloudinary.com "
        . "https://api.qrserver.com "
        . "https://*.tile.openstreetmap.org "
        . "https://*.basemaps.cartocdn.com "
        . "https://server.arcgisonline.com "
        . "https://maps.googleapis.com "
        . "https://maps.gstatic.com "
        . "https://www.google.com "
        . "https://www.googleapis.com",
     "frame-src https://maps.google.com https://www.google.com",
     "connect-src 'self' {$serverOrigin} "
        . "https://*.tile.openstreetmap.org "
        . "https://*.basemaps.cartocdn.com "
        . "https://server.arcgisonline.com "
        . "https://unpkg.com "
        . "http://localhost",
    "worker-src blob: 'self'",
    "object-src 'none'",
    $isProduction ? "upgrade-insecure-requests" : "",
]));
    $csp = preg_replace('/;\s*;/', ';', rtrim($csp, '; '));
    header('Content-Security-Policy: ' . $csp);
}

function checkSessionTimeout(): void {
    if (!isLoggedIn()) return;

    if (!validateSessionFingerprint()) {
        _forceLogout('⚠️ Session invalid. Please log in again.');
    }

    // Enforce admin TOTP even when an account session was established by a
    // non-password path such as email verification or impersonation.
    if (in_array($_SESSION['role'] ?? '', ADMIN_ROLES, true)
        && empty($_SESSION['two_factor_verified_at'])) {
        try {
            $stmt = getDB()->prepare('SELECT totp_enabled FROM falcon.users WHERE id = ?');
            $stmt->execute([(int)$_SESSION['user_id']]);
            if ($stmt->fetchColumn()) {
                redirect('auth/two_factor.php');
            }
        } catch (Throwable $e) {
            error_log('[SEC] TOTP enforcement lookup failed: ' . $e->getMessage());
        }
    }

    $now  = time();
    $last = $_SESSION['_last_activity'] ?? null;

    if ($last === null) {
        $_SESSION['_last_activity'] = $now;
        return;
    }

    if (($now - $last) > SESSION_IDLE_TIMEOUT) {
        _forceLogout('⏱ Session expired. Please log in again.');
    }

    $_SESSION['_last_activity'] = $now;
}

function _forceLogout(string $message): never {
    $flash = ['type' => 'error', 'message' => $message];
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['flash'] = $flash;
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

function sessionSecondsRemaining(): int {
    if (!isLoggedIn() || !isset($_SESSION['_last_activity'])) return 0;
    return max(0, SESSION_IDLE_TIMEOUT - (time() - $_SESSION['_last_activity']));
}

// ── Session heartbeat ─────────────────────────────────────────
if (
    isset($_GET['_session_ping']) &&
    $_GET['_session_ping'] === '1' &&
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    if (!checkRateLimit('ping_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'reason' => 'rate_limited']);
        exit;
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    if (!isLoggedIn()) {
        echo json_encode(['ok' => false, 'remaining' => 0, 'reason' => 'not_logged_in']);
        exit;
    }
    if (!validateSessionFingerprint()) {
        echo json_encode(['ok' => false, 'remaining' => 0, 'reason' => 'fingerprint_mismatch']);
        exit;
    }

    $idle = time() - ($_SESSION['_last_activity'] ?? 0);
    if ($idle > SESSION_IDLE_TIMEOUT) {
        echo json_encode(['ok' => false, 'remaining' => 0, 'reason' => 'timed_out']);
        exit;
    }

    $_SESSION['_last_activity'] = time();
    echo json_encode(['ok' => true, 'remaining' => SESSION_IDLE_TIMEOUT]);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function verifyCsrf(): void {
    $submitted = trim($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored    = $_SESSION['csrf_token'] ?? '';

    if (empty($stored) || empty($submitted) || !hash_equals($stored, $submitted)) {
        error_log(sprintf(
            '[CSRF] Mismatch — IP:%s UA:%s stored_empty:%s submitted_empty:%s',
            $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80),
            empty($stored)    ? 'yes' : 'no',
            empty($submitted) ? 'yes' : 'no'
        ));
        http_response_code(403);
die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invalid Request</title>
<style nonce="' . htmlspecialchars(csrfNonce(), ENT_QUOTES, 'UTF-8') . '">
  body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
       min-height:100vh;margin:0;background:#f5f5f5;}
  .box{background:#fff;padding:2rem;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.1);
       text-align:center;max-width:360px;width:90%;}
  h2{margin:0 0 .5rem;color:#c0392b;}
  p{color:#555;margin:.5rem 0 1.5rem;}
  a{display:inline-block;padding:.6rem 1.4rem;background:#2c7be5;color:#fff;
    border-radius:8px;text-decoration:none;}
  a:hover{background:#1a5bbf;}
</style>
</head>
<body>
  <div class="box">
    <h2>⚠️ Invalid Request</h2>
    <p>Your session token has expired or is missing.<br>Please go back and try again.</p>
    <a href="javascript:history.back()">Go Back</a>
  </div>
</body>
</html>');
    }

    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Role guards ───────────────────────────────────────────────
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', '🔒 Please log in to continue.');
        redirect('auth/login.php');
    }
    checkSessionTimeout();
    _assertAccountActive();
}

function requireAdmin(): void {
    requireLogin();
    $liveRole = currentUserRole();
    if (!in_array($liveRole, ADMIN_ROLES, true)) {
        setFlash('error', '⛔ Admin access required.');
        redirect('player/dashboard.php');
    }
    $_SESSION['role'] = $liveRole;
}

function requireSuperAdmin(): void {
    requireLogin();
    $liveRole  = currentUserRole();
    $checkRole = $_SESSION['_real_role'] ?? $liveRole;
    if ($checkRole !== ROLE_SUPER_ADMIN) {
        setFlash('error', '⛔ Super admin access required.');
        redirect(roleDashboard());
    }
}

function requirePlayer(): void {
    requireLogin();
    $liveRole = currentUserRole();
    if ($liveRole !== ROLE_PLAYER) {
        setFlash('error', '⛔ This section is for players only.');
        redirect(roleDashboard());
    }
}

// Staff console (queueing / tournament ops). Admin & super_admin can also use it.
function requireStaff(): void {
    requireLogin();
    $liveRole = currentUserRole();
    if (!in_array($liveRole, STAFF_ROLES, true)) {
        setFlash('error', '⛔ Staff access required.');
        redirect(roleDashboard());
    }
    $_SESSION['role'] = $liveRole;
}

// Referee scoring tools. Admin & super_admin can also use it.
function requireReferee(): void {
    requireLogin();
    $liveRole = currentUserRole();
    if (!in_array($liveRole, REFEREE_ROLES, true)) {
        setFlash('error', '⛔ Referee access required.');
        redirect(roleDashboard());
    }
    $_SESSION['role'] = $liveRole;
}

function _assertAccountActive(): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT is_active FROM falcon.users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['is_active']) {
            _forceLogout('🚫 Your account has been deactivated. Contact the court owner.');
        }
    } catch (Throwable $e) {
        error_log('[_assertAccountActive] ' . $e->getMessage());
    }
}

// ── Rate limiting ─────────────────────────────────────────────
// Single atomic (flock-protected) core used by both public wrappers below.
// The previous implementation read-then-wrote each file with no locking,
// which let concurrent requests race past the limit (classic TOCTOU bug —
// e.g. a scripted burst of parallel login attempts could all "win" the
// same check). Every request now takes an exclusive lock for the whole
// read-modify-write cycle.
function _rateLimitCore(string $bucketDir, string $key, int $maxAttempts, int $windowSecs): array {
    if (!is_dir($bucketDir)) {
        @mkdir($bucketDir, 0750, true);
    }
    $file = $bucketDir . md5($key) . '.json';
    $fh   = @fopen($file, 'c+');
    if ($fh === false) {
        // Fail closed on unexpected filesystem errors so a broken disk
        // can't silently disable rate limiting.
        return ['allowed' => false, 'retry_after' => $windowSecs];
    }

    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $now  = time();
    $data = json_decode((string)$raw, true) ?: ['attempts' => [], 'blocked_until' => 0];
    $data += ['attempts' => [], 'blocked_until' => 0];

    if ($data['blocked_until'] > $now) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return ['allowed' => false, 'retry_after' => $data['blocked_until'] - $now];
    }

    $data['attempts'] = array_values(array_filter($data['attempts'], fn($t) => $t > $now - $windowSecs));

    $allowed = count($data['attempts']) < $maxAttempts;
    if ($allowed) {
        $data['attempts'][] = $now;
    } else {
        $data['blocked_until'] = $now + $windowSecs;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return ['allowed' => $allowed, 'retry_after' => $allowed ? 0 : $windowSecs];
}

/**
 * Sliding-window limiter with a lockout period once the limit is hit.
 * Use for security-sensitive actions (login, password reset, OTP, payment
 * submission) where you want a cooldown, not just a rolling cap.
 */
function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSecs = 300): bool {
    return _rateLimitCore(RATE_LIMIT_DIR, $key, $maxAttempts, $windowSecs)['allowed'];
}

function clearRateLimit(string $key): void {
    $file = RATE_LIMIT_DIR . md5($key) . '.json';
    if (file_exists($file)) @unlink($file);
}

function getRateLimitRemaining(string $key, int $windowSecs = 300): int {
    $file = RATE_LIMIT_DIR . md5($key) . '.json';
    if (!file_exists($file)) return 0;
    $data = json_decode(@file_get_contents($file), true) ?? [];
    return max(0, ($data['blocked_until'] ?? 0) - time());
}

function _ensureRateLimitDir(): void {
    if (!is_dir(RATE_LIMIT_DIR)) {
        mkdir(RATE_LIMIT_DIR, 0750, true);
    }
}

/**
 * Plain rolling-window cap for high-volume/low-risk endpoints (general API
 * polling, notification bell, etc). No lockout period — once old requests
 * age out of the window, new ones are allowed again immediately.
 * Returns true when the caller SHOULD be rate limited (blocked).
 */
function shouldRateLimit(string $key, string $scope, int $limit = 100, int $windowSeconds = 60): bool {
    $bucketDir = RATE_LIMIT_DIR . basename($scope) . '/';
    return !_rateLimitCore($bucketDir, $key, $limit, $windowSeconds)['allowed'];
}

/**
 * Combine an IP-scoped and an account-scoped rate limit in one call so a
 * distributed attacker (many IPs, one target account) and a single noisy
 * IP (many target accounts) are both covered. Returns the shorter-lived
 * "allowed" verdict; use for login, password reset, and OTP endpoints.
 */
function checkCompositeRateLimit(string $ipKey, ?string $accountKey, int $maxAttempts = 5, int $windowSecs = 300): bool {
    $ipOk = checkRateLimit('ip_' . $ipKey, $maxAttempts, $windowSecs);
    if (!$ipOk) return false;
    if ($accountKey !== null && $accountKey !== '') {
        return checkRateLimit('acct_' . $accountKey, $maxAttempts, $windowSecs);
    }
    return true;
}

// ── Same-origin check (CSRF defense-in-depth for JSON/fetch APIs) ─────
// JSON API endpoints (api/*.php) are called via fetch(), not HTML forms,
// so they don't carry the csrf_token form field. They're already relying
// on the session cookie's SameSite=Lax attribute to stop cross-site POSTs,
// but SameSite alone is a browser-compatibility-dependent safety net, not
// a guarantee (older browsers, misconfigured proxies, or a future cookie
// change can silently weaken it). This adds an explicit, server-side check
// of the Origin/Referer header for state-changing requests.
function verifySameOrigin(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    // Prefer the configured public origin behind reverse proxies. Building
    // this from HTTP_HOST/HTTPS can produce http:// on an HTTPS Railway edge.
    $configuredOrigin = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if ($configuredOrigin !== '') {
        $selfOrigin = $configuredOrigin;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $selfOrigin = $scheme . '://' . $host;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $parts  = parse_url($_SERVER['HTTP_REFERER']);
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';
    }

    // No Origin/Referer at all (some same-origin fetches, some older
    // clients) — don't hard-fail on that alone, since it would also break
    // legitimate same-origin requests behind strict privacy proxies. The
    // CSRF/SameSite cookie layer still covers this case.
    if ($origin === '') return;

    if (!hash_equals($selfOrigin, rtrim($origin, '/'))) {
        error_log("[CSRF] Cross-origin request blocked — origin:{$origin} expected:{$selfOrigin} uri:" . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cross-origin request blocked.']);
        exit;
    }
}

// ── Input sanitization ────────────────────────────────────────
function sanitizeString(string $val, int $maxLen = 255): string {
    return substr(trim(strip_tags($val)), 0, $maxLen);
}

function sanitizeInt(mixed $val): int {
    return (int)filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function sanitizeFloat(mixed $val): float {
    return (float)filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function sanitizeEmail(string $val): string {
    return (string)filter_var(trim($val), FILTER_SANITIZE_EMAIL);
}

// ── Send headers (production only) ───────────────────────────
if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
    sendSecurityHeaders();
}
