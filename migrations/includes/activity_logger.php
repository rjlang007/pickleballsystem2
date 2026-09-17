<?php
// ============================================================
//  FILE: includes/activity_logger.php
//  Drop-in logger — call logActivity() anywhere in the app
// ============================================================

function logActivity(
    string $action,
    string $category = 'general',
    string $severity = 'normal',  // normal | warning | critical
    string $details  = '',
    ?int   $userId   = null
): void {
    try {
        $db   = getDB();
        $csrf = $_SESSION['csrf_token'] ?? null;

        // ── Resolve identity ──────────────────────────────────
        // During impersonation we ALWAYS log under the real super
        // admin so the audit trail reflects who actually did the
        // action, not the persona they were wearing.
        if (isset($_SESSION['_real_user_id'])) {
            $uid   = (int)$_SESSION['_real_user_id'];
            $uname = $_SESSION['_real_username'] ?? 'superadmin';
            $role  = $_SESSION['_real_role']     ?? 'superadmin';
        } else {
            // If an explicit $userId was passed, look up their
            // username/role from the DB so the row is consistent.
            // Fall back to session values for the common case
            // where $userId is null (i.e. the logged-in user).
            $uid = $userId ?? ($_SESSION['user_id'] ?? null);

            if ($userId !== null && $userId !== ($_SESSION['user_id'] ?? null)) {
                // Caller logged an action for a different user — fetch their details
                try {
                    $s = $db->prepare("SELECT username, role FROM falcon.users WHERE id = ?");
                    $s->execute([$userId]);
                    $row   = $s->fetch(PDO::FETCH_ASSOC);
                    $uname = $row['username'] ?? 'unknown';
                    $role  = $row['role']     ?? 'unknown';
                } catch (Throwable) {
                    $uname = 'unknown';
                    $role  = 'unknown';
                }
            } else {
                $uname = $_SESSION['username'] ?? 'guest';
                $role  = $_SESSION['role']     ?? 'unknown';
            }
        }

        // ── IP — take only the first address in the chain ─────
        // HTTP_X_FORWARDED_FOR is client-controlled; never trust
        // the whole string. Taking only the left-most value is
        // still spoofable without a trusted proxy layer, but it
        // prevents log injection via crafted header values.
        $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR']
                 ?? $_SERVER['REMOTE_ADDR']
                 ?? 'unknown';
        $ip = trim(explode(',', $rawIp)[0]);
        // Validate it looks like an IP; fall back if garbage
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $db->prepare("
            INSERT INTO falcon.activity_logs
                (user_id, username, role, action, category,
                 severity, csrf_token, ip_address, user_agent, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $uid, $uname, $role, $action, $category,
            $severity, $csrf, $ip, $ua, $details
        ]);

    } catch (Throwable $e) {
        error_log('[ActivityLogger] ' . $e->getMessage());
    }
}