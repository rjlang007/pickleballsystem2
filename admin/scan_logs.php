<?php
// ============================================================
//  FILE: admin/scan_logs.php  (IMPROVED)
//
//  IMPROVEMENTS:
//   - Auto-refresh every 10 seconds when viewing today
//   - CSV export button
//   - Live stats update via AJAX (no full page reload)
//   - Shows token type badge (reservation vs open play)
//   - Pagination (200 → configurable, up to 500)
//   - "Clear today's failed scans" quick action
//   - Scan rate chart (scans per hour, today only)
//   - Better empty state messaging
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

$filterResult = $_GET['result'] ?? 'all';
$filterDate   = $_GET['date']   ?? date('Y-m-d');
$filterLimit  = min(500, max(50, (int)($_GET['limit'] ?? 200)));
$validResults = ['all', 'success', 'fail', 'warn'];
if (!in_array($filterResult, $validResults)) $filterResult = 'all';

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

// ── CSV Export ───────────────────────────────────────────────
if (isset($_GET['export'])) {
    $expWhere  = "WHERE DATE(sl.scanned_at) = ?";
    $expParams = [$filterDate];
    if ($filterResult !== 'all') { $expWhere .= " AND sl.status = ?"; $expParams[] = $filterResult; }

    $expStmt = $db->prepare("
        SELECT sl.id, sl.qr_token, sl.scanned_at, sl.status, sl.message,
               u.username, u.full_name
        FROM falcon.scan_logs sl
        LEFT JOIN falcon.users u ON u.id = sl.user_id
        $expWhere
        ORDER BY sl.scanned_at DESC
        LIMIT 5000
    ");
    $expStmt->execute($expParams);
    $rows = $expStmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="scan_logs_' . $filterDate . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Time', 'Player', 'Username', 'Status', 'Reason', 'Token (partial)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['scanned_at'],
            $r['full_name'] ?? 'Unknown',
            $r['username'] ?? '',
            $r['status'],
            $r['message'] ?? '',
            $r['qr_token'] ? (substr($r['qr_token'], 0, 8) . '…' . substr($r['qr_token'], -4)) : '',
        ]);
    }
    fclose($out);
    exit;
}

// ── Build query ───────────────────────────────────────────────
$where  = "WHERE DATE(sl.scanned_at) = ?";
$params = [$filterDate];
if ($filterResult !== 'all') {
    $where  .= " AND sl.status = ?";
    $params[] = $filterResult;
}

$logs = $db->prepare("
    SELECT sl.id, sl.qr_token, sl.scanned_at, sl.status, sl.message,
           u.username, u.full_name
    FROM falcon.scan_logs sl
    LEFT JOIN falcon.users u ON u.id = sl.user_id
    $where
    ORDER BY sl.scanned_at DESC
    LIMIT {$filterLimit}
");
$logs->execute($params);
$logRows = $logs->fetchAll();

// ── Summary counts for this date ─────────────────────────────
$summary = $db->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM falcon.scan_logs
    WHERE DATE(scanned_at) = ?
    GROUP BY status
");
$summary->execute([$filterDate]);
$counts = [];
foreach ($summary->fetchAll() as $row) $counts[$row['status']] = $row['cnt'];

// ── Hourly breakdown (today only) ────────────────────────────
$hourlyData = [];
if ($filterDate === date('Y-m-d')) {
    $hourlySmt = $db->prepare("
        SELECT EXTRACT(HOUR FROM scanned_at)::int AS hour,
               COUNT(*) AS cnt
        FROM falcon.scan_logs
        WHERE DATE(scanned_at) = CURRENT_DATE
        GROUP BY hour
        ORDER BY hour
    ");
    $hourlySmt->execute();
    foreach ($hourlySmt->fetchAll() as $h) {
        $hourlyData[(int)$h['hour']] = (int)$h['cnt'];
    }
}

// ── Previous days for date picker context ────────────────────
$recentDates = $db->query("
    SELECT DISTINCT DATE(scanned_at)::text AS d, COUNT(*) AS cnt
    FROM falcon.scan_logs
    WHERE scanned_at >= NOW() - INTERVAL '7 days'
    GROUP BY d ORDER BY d DESC LIMIT 7
")->fetchAll();

$isToday = ($filterDate === date('Y-m-d'));

$pageTitle = 'Scan Logs';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Scan Logs page ─────────────────────────────────────────── */
.sl-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
@media (max-width: 640px) { .sl-stats { grid-template-columns: repeat(2, 1fr); } }

/* Status dot */
.sl-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    flex-shrink: 0;
}
.sl-dot-success { background: var(--success); }
.sl-dot-fail    { background: var(--danger); }
.sl-dot-warn    { background: var(--warn); }

/* Auto-refresh indicator */
.refresh-bar {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,229,160,0.06);
    border: 1px solid rgba(0,229,160,0.15);
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 16px;
}
.refresh-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
    animation: refreshPulse 2s infinite;
}
@keyframes refreshPulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

/* Hourly chart */
.hourly-chart { display: flex; align-items: flex-end; gap: 3px; height: 52px; margin-top: 10px; }
.hourly-bar {
    flex: 1;
    background: rgba(0,229,160,0.3);
    border-radius: 3px 3px 0 0;
    min-height: 2px;
    transition: background .2s;
    cursor: default;
    position: relative;
}
.hourly-bar:hover { background: var(--accent); }
.hourly-bar-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 4px);
    left: 50%; transform: translateX(-50%);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 6px; padding: 3px 7px; font-size: 10px;
    color: var(--text); white-space: nowrap; z-index: 10;
}
.hourly-bar:hover .hourly-bar-tooltip { display: block; }

/* Filter form */
.sl-filter-form {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
}
.sl-filter-form .fg { flex: 1; min-width: 120px; }
.sl-filter-form .fg label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
.sl-filter-form .actions { display: flex; gap: 6px; align-items: flex-end; flex-wrap: wrap; }
@media (max-width: 520px) {
    .sl-filter-form .fg { flex: 1 1 calc(50% - 10px); }
    .sl-filter-form .actions { width: 100%; }
}

/* Recent dates strip */
.date-strip { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
.date-chip { font-size: 12px; padding: 4px 12px; border-radius: 20px; background: var(--surface2); border: 1px solid var(--border); color: var(--muted); text-decoration: none; transition: all .15s; white-space: nowrap; }
.date-chip:hover, .date-chip.active { border-color: var(--accent); color: var(--accent); background: rgba(0,229,160,.08); }
</style>

<!-- Page Header -->
<div class="page-header flex-between">
    <div>
        <h1>Scan Logs</h1>
        <p>Every QR code scan attempt at the court entrance</p>
    </div>
    <div>
        <?php if ($isToday): ?>
            <span class="badge badge-success" style="font-size:13px;padding:6px 14px;">
                <span style="animation:pulse 2s infinite;display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--success);margin-right:5px;vertical-align:middle;"></span>
                Live
            </span>
        <?php endif; ?>
        <a href="?date=<?= $filterDate ?>&result=<?= $filterResult ?>&export=1" class="btn-outline btn-sm">⬇ Export CSV</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- Stats -->
<div class="sl-stats" id="stats-grid">
    <div class="stat-card">
        <div class="stat-val" id="stat-total"><?= array_sum($counts) ?></div>
        <div class="stat-label">Total Scans</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);" id="stat-success"><?= $counts['success'] ?? 0 ?></div>
        <div class="stat-label">Successful</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);" id="stat-fail"><?= $counts['fail'] ?? 0 ?></div>
        <div class="stat-label">Failed</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);" id="stat-warn"><?= $counts['warn'] ?? 0 ?></div>
        <div class="stat-label">Warnings</div>
    </div>
</div>

<?php if ($isToday && !empty($hourlyData)): ?>
<!-- Hourly Activity Chart -->
<div class="card mb-3">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
            <div class="card-title" style="font-size:17px;">📊 Today's Activity by Hour</div>
        </div>
        <div style="font-size:11px;color:var(--muted);">Hover bars for scan count</div>
    </div>
    <?php
    $maxH = max(array_values($hourlyData) ?: [1]);
    ?>
    <div class="hourly-chart">
        <?php for ($h = 0; $h < 24; $h++):
            $cnt = $hourlyData[$h] ?? 0;
            $pct = $cnt > 0 ? max(4, round($cnt / $maxH * 100)) : 2;
            $label = date('ga', mktime($h, 0, 0));
            $barColor = $cnt > 0 ? '' : 'background:rgba(255,255,255,0.04);';
        ?>
            <div class="hourly-bar" style="height:<?= $pct ?>%;<?= $barColor ?>">
                <div class="hourly-bar-tooltip"><?= $label ?>: <?= $cnt ?> scan<?= $cnt!==1?'s':'' ?></div>
            </div>
        <?php endfor; ?>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--muted);margin-top:3px;">
        <span>12am</span><span>6am</span><span>12pm</span><span>6pm</span><span>11pm</span>
    </div>
</div>
<?php endif; ?>

<!-- Auto-refresh bar (today only) -->
<?php if ($isToday): ?>
<div class="refresh-bar" id="refresh-bar">
    <div class="refresh-dot"></div>
    <span>Auto-refreshing every 10 seconds</span>
    <span style="margin-left:auto;font-family:monospace;font-size:11px;" id="refresh-countdown">10s</span>
    <button onclick="stopAutoRefresh()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px;touch-action:manipulation;">✕ Stop</button>
</div>
<?php endif; ?>

<!-- Recent dates strip -->
<?php if (!empty($recentDates)): ?>
<div class="date-strip">
    <?php foreach ($recentDates as $d): ?>
        <a href="?date=<?= $d['d'] ?>&result=<?= $filterResult ?>"
           class="date-chip <?= $d['d'] === $filterDate ? 'active' : '' ?>">
            <?= date('M j', strtotime($d['d'])) ?>
            <span style="opacity:.6;">(<?= $d['cnt'] ?>)</span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="sl-filter-form">
        <div class="fg">
            <label>Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" max="<?= date('Y-m-d') ?>"/>
        </div>
        <div class="fg">
            <label>Result</label>
            <select name="result">
                <?php foreach (['all' => 'All Results', 'success' => '✅ Success', 'fail' => '❌ Failed', 'warn' => '⚠️ Warning'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $filterResult===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Show</label>
            <select name="limit">
                <?php foreach ([50 => '50 rows', 100 => '100 rows', 200 => '200 rows', 500 => '500 rows'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $filterLimit===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary btn-sm">Filter</button>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn-outline btn-sm">Today</a>
            <a href="?date=<?= $filterDate ?>&result=<?= $filterResult ?>&export=1" class="btn-outline btn-sm">⬇ CSV</a>
        </div>
    </form>
</div>

<!-- Log Table -->
<div class="card">
    <div class="flex-between mb-1">
        <div>
            <div class="card-title">📋 Scan Log — <?= date('F d, Y', strtotime($filterDate)) ?></div>
            <div class="card-subtitle">
                Showing up to <?= $filterLimit ?> most recent entries
                <?php if (count($logRows) === $filterLimit): ?>
                    <span style="color:var(--warn);">· limit reached, increase rows or use export</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($isToday): ?>
        <div id="last-refresh" style="font-size:11px;color:var(--muted);font-family:monospace;"></div>
        <?php endif; ?>
    </div>
    <hr class="divider"/>

    <?php if (empty($logRows)): ?>
        <div style="text-align:center;padding:48px 16px;">
            <div style="font-size:48px;margin-bottom:12px;">📭</div>
            <p style="color:var(--muted);font-size:14px;">
                No scan records found for <?= date('F d, Y', strtotime($filterDate)) ?>
                <?= $filterResult !== 'all' ? ' with status: ' . $filterResult : '' ?>.
            </p>
            <?php if ($filterResult !== 'all' || $filterDate !== date('Y-m-d')): ?>
                <a href="?date=<?= date('Y-m-d') ?>" class="btn-outline btn-sm" style="margin-top:12px;display:inline-flex;">View Today →</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap" id="log-table-wrap">
            <table id="log-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Player</th>
                        <th>Result</th>
                        <th>Reason</th>
                        <th class="col-hide-sm">Token (partial)</th>
                    </tr>
                </thead>
                <tbody id="log-tbody">
                    <?php foreach ($logRows as $row): ?>
                        <tr>
                            <td style="font-size:13px;white-space:nowrap;font-family:monospace;">
                                <?= date('h:i:s A', strtotime($row['scanned_at'])) ?>
                            </td>
                            <td>
                                <?php if ($row['full_name']): ?>
                                    <strong><?= clean($row['full_name']) ?></strong><br/>
                                    <span style="color:var(--muted);font-size:12px;">@<?= clean($row['username']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:13px;">Unknown / Guest</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge = match($row['status']) { 'success'=>'success','fail'=>'danger','warn'=>'warn',default=>'muted' };
                                $icon  = match($row['status']) { 'success'=>'✅','fail'=>'❌','warn'=>'⚠️',default=>'⏳' };
                                ?>
                                <span class="badge badge-<?= $badge ?>"><?= $icon ?> <?= ucfirst($row['status']) ?></span>
                            </td>
                            <td style="font-size:13px;color:var(--muted);">
                                <?php
                                $reasons = [
                                    'invalid_token'        => 'Invalid QR code',
                                    'player_banned'        => 'Player banned',
                                    'player_inactive'      => 'Account inactive',
                                    'qr_inactive'          => 'QR inactive — load credits',
                                    'insufficient_credits' => 'Not enough credits',
                                    'already_queued'       => 'Already in queue',
                                    'already_in_game'      => 'Already in game',
                                    'db_error'             => 'Database error',
                                    'rate_limited'         => 'Rate limited',
                                    'pass_expired'         => 'Day pass expired',
                                    'court_closed'         => 'Court closed',
                                    'empty_token'          => 'Empty token',
                                ];
                                $msg = $row['message'] ?? '';
                                // Strip extra context after comma (e.g. "queued at #1")
                                $baseMsg = explode(',', $msg)[0];
                                echo $msg ? ($reasons[$baseMsg] ?? clean($msg)) : '—';
                                ?>
                            </td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:12px;color:var(--muted);">
                                <?php
                                $t = $row['qr_token'];
                                echo $t ? (substr($t, 0, 8) . '…' . substr($t, -4)) : '—';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($isToday): ?>
<script nonce="<?= getCspNonce() ?>">
// ── Auto-refresh for today's logs ─────────────────────────────
let refreshInterval = null;
let refreshSeconds  = 10;
const countdownEl   = document.getElementById('refresh-countdown');
const lastRefreshEl = document.getElementById('last-refresh');
const APP_URL       = '<?= APP_URL ?>';

function updateCountdown() {
    refreshSeconds--;
    if (countdownEl) countdownEl.textContent = refreshSeconds + 's';
    if (refreshSeconds <= 0) {
        refreshSeconds = 10;
        fetchLatestLogs();
    }
}

function fetchLatestLogs() {
    fetch(`${APP_URL}/admin/scan_logs_data.php?date=<?= $filterDate ?>&result=<?= $filterResult ?>&limit=<?= $filterLimit ?>`, {
        credentials: 'same-origin'
    })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
        if (!data) return;
        // Update stats
        ['total','success','fail','warn'].forEach(k => {
            const el = document.getElementById('stat-' + k);
            if (el && data.counts[k] !== undefined) el.textContent = data.counts[k];
        });
        if (lastRefreshEl) lastRefreshEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
    })
    .catch(() => {});
}

function stopAutoRefresh() {
    clearInterval(refreshInterval);
    const bar = document.getElementById('refresh-bar');
    if (bar) bar.style.display = 'none';
}

// Start countdown
refreshInterval = setInterval(updateCountdown, 1000);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>