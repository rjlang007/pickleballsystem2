<?php
// ============================================================
//  FILE: superadmin/system_health.php
//  Super Admin — errors, bugs, and system vitals in one place
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

$db = getDB();

// ── Tail the PHP error log ─────────────────────────────────
$logPath   = APP_ROOT . '/storage/logs/php_errors.log';
$errorTail = [];
$logSizeKb = 0;
if (is_file($logPath)) {
    $logSizeKb = round(filesize($logPath) / 1024, 1);
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
    if ($lines) {
        $errorTail = array_slice($lines, -60); // last 60 lines
        $errorTail = array_reverse($errorTail);
    }
}

// ── Clear log action ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    verifyCsrf();
    if (is_file($logPath)) @file_put_contents($logPath, '');
    setFlash('success', 'Error log cleared.');
    redirect('superadmin/system_health.php');
}

// ── Recent failed logins & blocked IPs ─────────────────────
$failedLogins = $db->query("
    SELECT ip_address, username, reason, attempted_at
    FROM falcon.failed_logins
    ORDER BY attempted_at DESC LIMIT 15
")->fetchAll();

$blockedIps = [];
try {
    $blockedIps = $db->query("
        SELECT ip_address, reason, blocked_by, created_at
        FROM falcon.blocked_ips ORDER BY created_at DESC LIMIT 10
    ")->fetchAll();
} catch (Throwable $e) { /* table may not exist in all environments */ }

// ── Recent admin actions ────────────────────────────────────
$auditLog = [];
try {
    $auditLog = $db->query("
        SELECT al.action, al.created_at, u.username
        FROM falcon.audit_log al
        LEFT JOIN falcon.users u ON u.id = al.user_id
        ORDER BY al.created_at DESC LIMIT 15
    ")->fetchAll();
} catch (Throwable $e) { /* column name may differ */ }

// ── DB / session vitals ─────────────────────────────────────
$dbOk = true;
try { $db->query("SELECT 1"); } catch (Throwable $e) { $dbOk = false; }

$sessionCount = 0;
try {
    $sessionCount = (int)$db->query("SELECT COUNT(*) FROM falcon.php_sessions WHERE updated_at > NOW() - INTERVAL '15 minutes'")->fetchColumn();
} catch (Throwable $e) { /* table may not exist yet */ }

$scanErrorCount = 0;
try {
    $scanErrorCount = (int)$db->query("SELECT COUNT(*) FROM falcon.scan_logs WHERE status != 'success' AND scanned_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();
} catch (Throwable $e) {}

// ── Disk space ───────────────────────────────────────────────
$diskFreeGb  = null;
$diskTotalGb = null;
$diskPctUsed = null;
try {
    $free  = @disk_free_space(APP_ROOT);
    $total = @disk_total_space(APP_ROOT);
    if ($free !== false && $total) {
        $diskFreeGb  = round($free / 1073741824, 1);
        $diskTotalGb = round($total / 1073741824, 1);
        $diskPctUsed = round((1 - $free / $total) * 100, 1);
    }
} catch (Throwable $e) {}

// ── DB connection pool ──────────────────────────────────────
$dbConnActive  = null;
$dbConnMax     = null;
try {
    $dbConnActive = (int)$db->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database()")->fetchColumn();
    $dbConnMax    = (int)$db->query("SHOW max_connections")->fetchColumn();
} catch (Throwable $e) { /* insufficient privilege on some managed hosts */ }

// ── Slow / long-running queries currently in flight ──────────
$slowQueries  = [];
$pgStatOk     = true;
try {
    $slowQueries = $db->query("
        SELECT pid, now() - query_start AS running_for, state,
               left(query, 160) AS query_snippet
          FROM pg_stat_activity
         WHERE datname = current_database()
           AND state = 'active'
           AND query_start < NOW() - INTERVAL '2 seconds'
           AND query NOT ILIKE '%pg_stat_activity%'
         ORDER BY query_start ASC
         LIMIT 10
    ")->fetchAll();
} catch (Throwable $e) { $pgStatOk = false; /* insufficient privilege on some managed hosts */ }

// ── Cron / background job health ─────────────────────────────
// auto_end_games.php writes a heartbeat every time it runs; the
// docs specify it should run every 1 minute via cron. Stale =
// probably not running (cron misconfigured, PHP CLI broken, etc.)
$autoEndLastRun = null;
$autoEndStaleSecs = null;
try {
    $ts = $db->query("
        SELECT value FROM falcon.site_content
        WHERE section = 'autoend' AND key = 'last_run'
    ")->fetchColumn();
    if ($ts) {
        $autoEndLastRun   = (int)$ts;
        $autoEndStaleSecs = time() - $autoEndLastRun;
    }
} catch (Throwable $e) {}

// Symptom of a stuck auto-end job: sessions whose timer expired
// a while ago but were never marked completed.
$stuckSessions = 0;
try {
    $stuckSessions = (int)$db->query("
        SELECT COUNT(*) FROM falcon.game_sessions
         WHERE status = 'active'
           AND started_at + (duration_mins || ' minutes')::interval < NOW() - INTERVAL '2 minutes'
    ")->fetchColumn();
} catch (Throwable $e) {}

// ── Queue backlog (unassigned walk-in players) ────────────────
$queueBacklog = 0;
try {
    $queueBacklog = (int)$db->query("SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL")->fetchColumn();
} catch (Throwable $e) {}

// ── Failed AJAX/API calls — best-effort scan of the error log ─
// There's no dedicated API error table, so this greps the same
// tail already loaded above for requests to *_data.php / api/ /
// *_ajax.php endpoints, which is where AJAX polling failures show up.
$apiErrorCount = 0;
foreach ($errorTail as $line) {
    if (preg_match('#(kiosk_data|kiosk_tournament_data|score_ajax|/api/|_ajax\.php)#i', $line)) {
        $apiErrorCount++;
    }
}

$pageTitle = 'System Health';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.health-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
@media (max-width: 1100px) { .health-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 900px)  { .health-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 520px)  { .health-grid { grid-template-columns: 1fr; } }
.log-console {
    background: #0d1117; color: #c9d1d9; border-radius: 10px; padding: 14px;
    font-family: 'JetBrains Mono', monospace; font-size: 12px; line-height: 1.6;
    max-height: 420px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;
}
.log-console .log-exc { color: #ff7b72; }
.log-console .log-err { color: #f0b429; }
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>🩺 System Health</h1>
        <p>Errors, bugs, and vitals — the dev view of the whole system.</p>
    </div>
    <a href="<?= APP_URL ?>/superadmin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<div class="health-grid">
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $dbOk ? 'var(--success)' : 'var(--danger)' ?>;"><?= $dbOk ? '✓' : '✕' ?></div>
        <div class="stat-label">Database</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= count($errorTail) ?></div>
        <div class="stat-label">Recent Log Lines (<?= $logSizeKb ?> KB)</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= $scanErrorCount ?></div>
        <div class="stat-label">Failed Scans (24h)</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--info);"><?= $sessionCount ?></div>
        <div class="stat-label">Active Sessions (15m)</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $apiErrorCount > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $apiErrorCount ?></div>
        <div class="stat-label">Failed AJAX/API Calls <span class="text-muted" style="font-weight:400;">(log scan)</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= empty($slowQueries) ? 'var(--success)' : 'var(--warn)' ?>;"><?= $pgStatOk ? count($slowQueries) : '—' ?></div>
        <div class="stat-label">Slow Queries (&gt;2s, in-flight)</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $stuckSessions > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $stuckSessions ?></div>
        <div class="stat-label">Stuck Sessions <span class="text-muted" style="font-weight:400;">(auto-end backlog)</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--info);"><?= $queueBacklog ?></div>
        <div class="stat-label">Queue Backlog <span class="text-muted" style="font-weight:400;">(walk-in)</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $dbConnMax && $dbConnActive !== null && $dbConnActive / max(1,$dbConnMax) > 0.8 ? 'var(--danger)' : 'var(--text)' ?>;">
            <?= $dbConnActive === null ? '—' : $dbConnActive . ' / ' . $dbConnMax ?>
        </div>
        <div class="stat-label">DB Connections</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $diskPctUsed !== null && $diskPctUsed > 90 ? 'var(--danger)' : ($diskPctUsed !== null && $diskPctUsed > 75 ? 'var(--warn)' : 'var(--text)') ?>;">
            <?= $diskFreeGb === null ? '—' : $diskFreeGb . ' GB free' ?>
        </div>
        <div class="stat-label">Disk Space <?= $diskPctUsed !== null ? '(' . $diskPctUsed . '% used)' : '' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $autoEndLastRun === null ? 'var(--muted)' : ($autoEndStaleSecs > 180 ? 'var(--danger)' : 'var(--success)') ?>;">
            <?= $autoEndLastRun === null ? '—' : ($autoEndStaleSecs < 60 ? $autoEndStaleSecs . 's ago' : round($autoEndStaleSecs / 60) . 'm ago') ?>
        </div>
        <div class="stat-label">Auto-End Cron <span class="text-muted" style="font-weight:400;">(last run)</span></div>
    </div>
</div>

<?php if (!empty($slowQueries)): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">🐢 Slow Queries In Flight</div>
    <hr class="divider"/>
    <div class="table-wrap">
        <table>
            <thead><tr><th>PID</th><th>Running For</th><th>State</th><th>Query</th></tr></thead>
            <tbody>
            <?php foreach ($slowQueries as $q): ?>
                <tr>
                    <td><?= (int)$q['pid'] ?></td>
                    <td style="color:var(--warn);font-weight:600;"><?= clean($q['running_for']) ?></td>
                    <td><?= clean($q['state']) ?></td>
                    <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= clean($q['query_snippet']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($stuckSessions > 0): ?>
<div class="card" style="margin-bottom:20px;border-color:rgba(239,68,68,.4);">
    <div class="card-title mb-1" style="color:var(--danger);">⚠️ Auto-End Job May Be Down</div>
    <p class="text-muted"><?= $stuckSessions ?> game session(s) ran past their timer by more than 2 minutes without being auto-completed.
    Check that <code>court/auto_end_games.php --cron</code> is running (cron/task scheduler), and that the last heartbeat above isn't stale.</p>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-title flex-between mb-1">
        <span>🪵 PHP Error Log — last <?= count($errorTail) ?> lines</span>
        <form method="POST" onsubmit="return confirm('Clear the error log?');">
            <?= csrfField() ?>
            <button type="submit" name="clear_log" value="1" class="btn-outline btn-sm">🗑 Clear Log</button>
        </form>
    </div>
    <hr class="divider"/>
    <div class="log-console">
<?php if (empty($errorTail)): ?>
✅ No errors logged. Clean slate.
<?php else: foreach ($errorTail as $line):
    $cls = str_contains($line, 'EXCEPTION') ? 'log-exc' : (str_contains($line, 'ERROR') ? 'log-err' : '');
?><span class="<?= $cls ?>"><?= clean($line) ?></span>
<?php endforeach; endif; ?>
    </div>
</div>

<div class="sa-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div class="card">
        <div class="card-title mb-1">🚫 Recent Failed Logins</div>
        <hr class="divider"/>
        <?php if (empty($failedLogins)): ?>
            <p class="text-muted text-center" style="padding:16px;">None recorded.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Username</th><th>IP</th><th>Reason</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($failedLogins as $f): ?>
                    <tr>
                        <td><?= clean($f['username'] ?: '—') ?></td>
                        <td style="font-family:monospace;font-size:11px;"><?= clean($f['ip_address']) ?></td>
                        <td style="font-size:12px;color:var(--muted);"><?= clean($f['reason']) ?></td>
                        <td style="font-size:11px;color:var(--muted);white-space:nowrap;"><?= date('M d, h:i A', strtotime($f['attempted_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title mb-1">🔒 Blocked IPs</div>
        <hr class="divider"/>
        <?php if (empty($blockedIps)): ?>
            <p class="text-muted text-center" style="padding:16px;">None currently blocked.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>IP</th><th>Reason</th><th>Since</th></tr></thead>
                <tbody>
                <?php foreach ($blockedIps as $b): ?>
                    <tr>
                        <td style="font-family:monospace;font-size:11px;"><?= clean($b['ip_address']) ?></td>
                        <td style="font-size:12px;color:var(--muted);"><?= clean($b['reason']) ?></td>
                        <td style="font-size:11px;color:var(--muted);white-space:nowrap;"><?= date('M d, h:i A', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
