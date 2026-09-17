<?php
// ============================================================
//  FILE: admin/export_csv.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

// ── Handle CSV download ──────────────────────────────────────
if (isset($_GET['export'])) {
    $csrfToken = $_GET['csrf_token'] ?? '';
    if (!verifyCsrf($csrfToken, 'get')) die('Invalid token');

    $type      = $_GET['export'];
    $dateFrom  = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo    = $_GET['date_to']   ?? date('Y-m-d');
    $rows      = [];
    $filename  = 'export_' . $type . '_' . date('Ymd') . '.csv';

    switch ($type) {
        case 'sessions':
            $rows = $db->prepare("
                SELECT gs.id, gs.status, gs.started_at, gs.ended_at, gs.duration_mins,
                       c.name AS court,
                       STRING_AGG(u.username, ', ' ORDER BY u.username) AS players,
                       COALESCE(SUM(gp.credits_charged), 0) AS total_credits
                FROM falcon.game_sessions gs
                JOIN falcon.courts c ON c.id = gs.court_id
                LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
                LEFT JOIN falcon.users u ON u.id = gp.user_id
                WHERE DATE(gs.started_at) BETWEEN :from AND :to
                GROUP BY gs.id, c.name
                ORDER BY gs.started_at DESC
            ");
            $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);
            $rows = $rows->fetchAll();
            break;

        case 'revenue':
            $rows = $db->prepare("
                SELECT DATE(started_at) AS date,
                       COUNT(*) AS total_games,
                       COUNT(CASE WHEN status='completed' THEN 1 END) AS completed,
                       COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled,
                       COALESCE(SUM(gp.credits_charged), 0) AS total_credits
                FROM falcon.game_sessions gs
                LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
                WHERE DATE(gs.started_at) BETWEEN :from AND :to
                GROUP BY DATE(started_at)
                ORDER BY date DESC
            ");
            $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);
            $rows = $rows->fetchAll();
            break;

        case 'topups':
            $rows = $db->prepare("
                SELECT tr.id, tr.status, tr.amount, tr.gcash_ref_no,
                       tr.created_at, tr.reviewed_at,
                       u.username, u.full_name, u.phone,
                       reviewer.username AS reviewed_by
                FROM falcon.topup_requests tr
                JOIN falcon.users u ON u.id = tr.user_id
                LEFT JOIN falcon.users reviewer ON reviewer.id = tr.reviewed_by
                WHERE DATE(tr.created_at) BETWEEN :from AND :to
                ORDER BY tr.created_at DESC
            ");
            $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);
            $rows = $rows->fetchAll();
            break;

        case 'players':
            $rows = $db->query("
                SELECT u.id, u.username, u.full_name, u.email, u.phone,
                       u.role, u.created_at, u.last_login,
                       COALESCE(w.balance, 0) AS credits,
                       COUNT(DISTINCT gp.session_id) AS total_games
                FROM falcon.users u
                LEFT JOIN falcon.wallets w ON w.user_id = u.id
                LEFT JOIN falcon.game_players gp ON gp.user_id = u.id
                WHERE u.role = 'player'
                GROUP BY u.id, w.balance
                ORDER BY u.created_at DESC
            ")->fetchAll();
            break;

        case 'food':
            $rows = $db->prepare("
                SELECT fo.id, fo.order_number, fo.status, fo.payment_method, fo.payment_status,
                       fo.fulfillment_type, c.name AS court, fo.total_amount,
                       fo.created_at, fo.ready_at, fo.completed_at, fo.cancelled_at,
                       u.username, u.full_name,
                       STRING_AGG(foi.item_name || ' x' || foi.quantity, ', ' ORDER BY foi.id) AS items
                FROM falcon.food_orders fo
                JOIN falcon.users u ON u.id = fo.user_id
                LEFT JOIN falcon.courts c ON c.id = fo.court_id
                LEFT JOIN falcon.food_order_items foi ON foi.order_id = fo.id
                WHERE DATE(fo.created_at) BETWEEN :from AND :to
                GROUP BY fo.id, c.name, u.username, u.full_name
                ORDER BY fo.created_at DESC
            ");
            $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);
            $rows = $rows->fetchAll();
            break;
    }

    if (!empty($rows)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
    setFlash('No data found for the selected date range.', 'warn');
    header('Location: ' . APP_URL . '/admin/export_csv.php');
    exit;
}

// ── Preview counts ───────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

try {
    $counts = [
        'sessions' => $db->prepare("SELECT COUNT(*) FROM falcon.game_sessions WHERE DATE(started_at) BETWEEN ? AND ?")->execute([$dateFrom,$dateTo]) ? $db->query("SELECT COUNT(*) FROM falcon.game_sessions WHERE DATE(started_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn() : 0,
        'topups'   => $db->query("SELECT COUNT(*) FROM falcon.topup_requests WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
        'players'  => $db->query("SELECT COUNT(*) FROM falcon.users WHERE role='player'")->fetchColumn(),
        'food'     => $db->query("SELECT COUNT(*) FROM falcon.food_orders WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    ];
    $revenueTotal = $db->query("
        SELECT COALESCE(SUM(gp.credits_charged),0)
        FROM falcon.game_players gp
        JOIN falcon.game_sessions gs ON gs.id = gp.session_id
        WHERE DATE(gs.started_at) BETWEEN '$dateFrom' AND '$dateTo'
    ")->fetchColumn();
} catch (PDOException $e) {
    $counts = ['sessions'=>0,'topups'=>0,'players'=>0,'food'=>0];
    $revenueTotal = 0;
}

$pageTitle = 'Export CSV';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.export-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.export-card {
    background: var(--surface);
    border: 2px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    transition: border-color 0.2s;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.export-card:hover { border-color: var(--accent); }
.export-card.selected { border-color: var(--accent); background: rgba(0,229,160,0.05); }
.export-icon  { font-size: 32px; }
.export-title { font-size: 15px; font-weight: 700; }
.export-desc  { font-size: 13px; color: var(--muted); flex: 1; }
.export-count { font-family: 'Bebas Neue', sans-serif; font-size: 22px; color: var(--accent); }

.date-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 12px;
    align-items: end;
    margin-bottom: 20px;
}

@media (max-width: 700px) {
    .export-grid { grid-template-columns: 1fr; }
    .date-row    { grid-template-columns: 1fr 1fr; }
    .date-row .btn-primary { grid-column: 1 / -1; }
}
@media (max-width: 420px) {
    .date-row { grid-template-columns: 1fr; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Export CSV</h1>
        <p>Download reports as spreadsheet-ready CSV files.</p>
    </div>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<!-- Date Range Filter -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-title" style="margin-bottom:14px;">📅 Date Range</div>
    <form method="GET" id="filter-form">
        <div class="date-row">
            <div class="form-group" style="margin:0;">
                <label>From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"/>
            </div>
            <div class="form-group" style="margin:0;">
                <label>To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"/>
            </div>
            <button type="submit" class="btn-primary" style="min-height:48px;">🔍 Preview</button>
        </div>

        <!-- Quick range presets -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <?php
            $ranges = [
                'Today'      => [date('Y-m-d'),       date('Y-m-d')],
                'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                'This Month' => [date('Y-m-01'),       date('Y-m-d')],
                'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
                'This Year'  => [date('Y-01-01'),      date('Y-m-d')],
            ];
            foreach ($ranges as $label => [$from, $to]):
            ?>
                <button type="button" class="btn-outline btn-xs"
                        onclick="setRange('<?= $from ?>','<?= $to ?>')"
                        style="<?= ($dateFrom===$from && $dateTo===$to) ? 'border-color:var(--accent);color:var(--accent);' : '' ?>">
                    <?= $label ?>
                </button>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<!-- Export type cards -->
<div class="export-grid">
    <?php
    $exports = [
        'sessions' => ['📋', 'Game Sessions',     'All game sessions with players, courts and credits charged.', $counts['sessions'].' sessions'],
        'revenue'  => ['📈', 'Daily Revenue',      'Revenue summary grouped by day — perfect for accounting.',   '₱'.number_format($revenueTotal,0).' total'],
        'topups'   => ['💳', 'Top-Up Requests',    'All credit top-up submissions and their approval status.',    $counts['topups'].' requests'],
        'players'  => ['👥', 'Player Activity',    'All player accounts with game counts and credit balances.',   $counts['players'].' players'],
        'food'     => ['🍔', 'Food Orders',        'All food/drink orders with items, payment and fulfillment.',  $counts['food'].' orders'],
    ];
    foreach ($exports as $key => [$icon, $title, $desc, $count]):
    ?>
    <div class="export-card" id="card-<?= $key ?>" onclick="selectExport('<?= $key ?>')">
        <div style="display:flex;align-items:center;gap:12px;">
            <div class="export-icon"><?= $icon ?></div>
            <div>
                <div class="export-title"><?= $title ?></div>
                <div class="export-count"><?= $count ?></div>
            </div>
        </div>
        <div class="export-desc"><?= $desc ?></div>
        <div style="margin-top:auto;">
            <a href="<?= APP_URL ?>/admin/export_csv.php?export=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&csrf_token=<?= csrfToken() ?>"
               class="btn-success btn-sm btn-block"
               onclick="event.stopPropagation();">
                ⬇ Download CSV
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Info box -->
<div class="card">
    <div class="card-title" style="margin-bottom:10px;">ℹ️ CSV Format Notes</div>
    <div style="font-size:13px;color:var(--muted);line-height:1.8;">
        <div>✅ All files include a <strong style="color:var(--text);">UTF-8 BOM</strong> for correct display in Microsoft Excel.</div>
        <div>✅ Dates are in <strong style="color:var(--text);">ISO 8601</strong> format (YYYY-MM-DD HH:MM:SS).</div>
        <div>✅ Monetary values are in <strong style="color:var(--text);">credits</strong> (not Philippine Peso).</div>
        <div>✅ Player Activity export always includes <strong style="color:var(--text);">all players</strong> regardless of date filter.</div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function setRange(from, to) {
    const f = document.querySelector('[name=date_from]');
    const t = document.querySelector('[name=date_to]');
    if (f) f.value = from;
    if (t) t.value = to;
    document.getElementById('filter-form')?.submit();
}
function selectExport(key) {
    document.querySelectorAll('.export-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('card-'+key)?.classList.add('selected');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>