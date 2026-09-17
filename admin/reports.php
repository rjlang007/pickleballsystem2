<?php
// ============================================================
//  FILE: admin/reports.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

$month = $_GET['month'] ?? date('Y-m');
[$yr, $mo] = explode('-', $month);
$monthStart = "$yr-$mo-01";
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

$monthlySummary = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE gs.status='completed')   AS games_completed,
        COUNT(*) FILTER (WHERE gs.status='cancelled')   AS games_cancelled,
        COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='completed'), 0) AS revenue,
        COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='cancelled'), 0) AS refunded,
        COUNT(DISTINCT gp.user_id)                      AS unique_players
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) BETWEEN ? AND ?
");
$monthlySummary->execute([$monthStart, $monthEnd]);
$summary = $monthlySummary->fetch();

$dailyStmt = $db->prepare("
    SELECT DATE(gs.started_at) AS day,
           COUNT(*) AS sessions,
           COUNT(*) FILTER (WHERE gs.status='completed') AS completed,
           COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='completed'), 0) AS revenue
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) BETWEEN ? AND ?
    GROUP BY DATE(gs.started_at)
    ORDER BY day ASC
");
$dailyStmt->execute([$monthStart, $monthEnd]);
$daily = $dailyStmt->fetchAll();

$topByGames = $db->prepare("
    SELECT u.username, u.full_name, COUNT(*) AS games,
           COALESCE(SUM(gp.credits_charged), 0) AS spent
    FROM falcon.game_players gp
    JOIN falcon.users u ON u.id = gp.user_id
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE DATE(gs.started_at) BETWEEN ? AND ? AND gs.status = 'completed'
    GROUP BY u.id, u.username, u.full_name
    ORDER BY games DESC LIMIT 10
");
$topByGames->execute([$monthStart, $monthEnd]);
$topPlayers = $topByGames->fetchAll();

$peakStmt = $db->prepare("
    SELECT EXTRACT(HOUR FROM gs.started_at)::int AS hour, COUNT(*) AS sessions
    FROM falcon.game_sessions gs
    WHERE DATE(gs.started_at) BETWEEN ? AND ? AND gs.status = 'completed'
    GROUP BY hour ORDER BY hour ASC
");
$peakStmt->execute([$monthStart, $monthEnd]);
$peakHours = $peakStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$topupSummary = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE status='approved') AS approved_count,
        COUNT(*) FILTER (WHERE status='pending')  AS pending_count,
        COALESCE(SUM(amount) FILTER (WHERE status='approved'), 0) AS total_loaded
    FROM falcon.topup_requests
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$topupSummary->execute([$monthStart, $monthEnd]);
$topup = $topupSummary->fetch();

$byCourt = $db->prepare("
    SELECT c.name AS court_name, COUNT(*) AS sessions,
           COALESCE(SUM(gp.credits_charged), 0) AS revenue
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) BETWEEN ? AND ? AND gs.status = 'completed'
    GROUP BY c.id, c.name ORDER BY revenue DESC
");
$byCourt->execute([$monthStart, $monthEnd]);
$courtRevenue = $byCourt->fetchAll();

// ── Food sales (mini-restaurant module) ──────────────────────
$foodSummaryStmt = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE payment_status = 'paid')  AS orders_paid,
        COUNT(*) FILTER (WHERE status = 'cancelled')      AS orders_cancelled,
        COALESCE(SUM(total_amount) FILTER (WHERE payment_status = 'paid'), 0) AS food_revenue,
        COUNT(DISTINCT user_id)                           AS food_customers
    FROM falcon.food_orders
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$foodSummaryStmt->execute([$monthStart, $monthEnd]);
$foodSummary = $foodSummaryStmt->fetch();

$foodDailyStmt = $db->prepare("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS orders,
           COALESCE(SUM(total_amount) FILTER (WHERE payment_status = 'paid'), 0) AS revenue
    FROM falcon.food_orders
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$foodDailyStmt->execute([$monthStart, $monthEnd]);
$foodDaily = $foodDailyStmt->fetchAll();
$maxFoodRevenue = max(array_column($foodDaily, 'revenue') ?: [1]);

$topFoodItemsStmt = $db->prepare("
    SELECT foi.item_name,
           SUM(foi.quantity) AS qty_sold,
           SUM(foi.subtotal) AS revenue
      FROM falcon.food_order_items foi
      JOIN falcon.food_orders fo ON fo.id = foi.order_id
     WHERE DATE(fo.created_at) BETWEEN ? AND ? AND fo.payment_status = 'paid'
     GROUP BY foi.item_name
     ORDER BY qty_sold DESC
     LIMIT 10
");
$topFoodItemsStmt->execute([$monthStart, $monthEnd]);
$topFoodItems = $topFoodItemsStmt->fetchAll();

// ── Credits Flow — all-time totals (not month-filtered) ──────
// All credits ever approved and distributed to players
$creditsDistributed = (float) $db->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM falcon.transactions
    WHERE type = 'topup' AND status = 'approved'
")->fetchColumn();

// Credits actually deducted via QR scan in completed games
$creditsSpentInGames = (float) $db->query("
    SELECT COALESCE(SUM(gp.credits_charged), 0)
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gs.status = 'completed'
")->fetchColumn();

// Current unspent balance sitting in all active (non-banned) player wallets
$creditsUnspent = (float) $db->query("
    SELECT COALESCE(SUM(w.balance), 0)
    FROM falcon.wallets w
    JOIN falcon.users u ON u.id = w.user_id
    WHERE u.is_banned = FALSE
")->fetchColumn();

// What % of all distributed credits has actually been spent
$utilisationPct = $creditsDistributed > 0
    ? min(100, round($creditsSpentInGames / $creditsDistributed * 100, 1))
    : 0;

// Difference between (distributed - spent) vs actual wallet balances
// A non-zero diff indicates refunds, manual deductions, or adjustments
$theoreticalRemaining = $creditsDistributed - $creditsSpentInGames;
$diff = abs($theoreticalRemaining - $creditsUnspent);

$maxRevenue  = max(array_column($daily, 'revenue') ?: [1]);
$maxSessions = max(array_values($peakHours) ?: [1]);
$combinedRevenue = (float)$summary['revenue'] + (float)$foodSummary['food_revenue'];

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Reports page — only what app.css doesn't cover ── */
.reports-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.topup-stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}
.export-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Credits Flow card */
.credits-flow-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}
.cf-block {
    background: var(--surface2);
    border-radius: 12px;
    padding: 18px 16px;
    text-align: center;
    border: 1px solid var(--border);
    min-width: 0;
}
.cf-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(22px, 3.5vw, 34px);
    line-height: 1;
    word-break: break-all;
}
.cf-label {
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-top: 5px;
}
.cf-sub {
    font-size: 11px;
    color: var(--muted);
    margin-top: 3px;
}
.util-bar-wrap {
    background: var(--surface2);
    border-radius: 8px;
    height: 10px;
    overflow: hidden;
    margin: 6px 0 4px;
}
.util-bar-fill {
    height: 100%;
    border-radius: 8px;
    transition: width 0.6s ease;
}

@media (max-width: 600px) {
    .credits-flow-grid { grid-template-columns: 1fr 1fr; }
    .credits-flow-grid .cf-block:last-child { grid-column: 1 / -1; }
}
@media (max-width: 480px) {
    .topup-stat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .export-buttons  { flex-direction: column; }
    .export-buttons .btn-outline { width: 100%; }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1>Reports</h1>
        <p>Revenue &amp; activity analytics — <?= $monthLabel ?></p>
    </div>
    <div class="reports-actions">
        <form method="GET" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="month" name="month" value="<?= $month ?>" max="<?= date('Y-m') ?>"/>
            <button type="submit" class="btn-primary btn-sm">View</button>
        </form>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=revenue&month=<?= $month ?>" class="btn-outline btn-sm">⬇ Export</a>
        <a href="<?= APP_URL ?>/admin/game_history.php" class="btn-outline btn-sm">📋 Game Log</a>
    </div>
</div>

<!-- ── Monthly Summary Stats ── -->
<div class="dashboard-grid mb-3">
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);">₱<?= number_format($summary['revenue'], 2) ?></div>
        <div class="stat-label">Court Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);">₱<?= number_format($foodSummary['food_revenue'], 2) ?></div>
        <div class="stat-label">🍔 Food Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent2);">₱<?= number_format($combinedRevenue, 2) ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $summary['games_completed'] ?></div>
        <div class="stat-label">Games Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);">₱<?= number_format($summary['refunded'], 2) ?></div>
        <div class="stat-label">Refunded</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $summary['unique_players'] ?></div>
        <div class="stat-label">Unique Players</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent2);">₱<?= number_format($topup['total_loaded'], 2) ?></div>
        <div class="stat-label">Credits Loaded</div>
    </div>
</div>

<!-- ── Credits Flow Card (all-time) ── -->
<div class="card mb-3">
    <div class="flex-between" style="margin-bottom:4px;align-items:flex-start;">
        <div>
            <div class="card-title">💳 Credits Flow</div>
            <div class="card-subtitle">All-time totals across all players</div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <?php
            $utilColor = $utilisationPct >= 80
                ? 'var(--success)'
                : ($utilisationPct >= 40 ? 'var(--warn)' : 'var(--accent2)');
            ?>
            <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:<?= $utilColor ?>;">
                <?= $utilisationPct ?>%
            </div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Utilisation</div>
        </div>
    </div>

    <!-- Utilisation progress bar -->
    <div class="util-bar-wrap">
        <div class="util-bar-fill" style="width:<?= $utilisationPct ?>%;background:<?= $utilColor ?>;"></div>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:18px;">
        <?= number_format($utilisationPct, 1) ?>% of all distributed credits have been spent in games
    </div>

    <!-- Three stat blocks -->
    <div class="credits-flow-grid">

        <div class="cf-block" style="border-color:rgba(0,170,255,0.3);">
            <div style="font-size:22px;margin-bottom:6px;">📤</div>
            <div class="cf-val" style="color:var(--accent2);">₱<?= number_format($creditsDistributed, 0) ?></div>
            <div class="cf-label">Total Distributed</div>
            <div class="cf-sub">All topups ever approved</div>
        </div>

        <div class="cf-block" style="border-color:rgba(239,68,68,0.3);">
            <div style="font-size:22px;margin-bottom:6px;">🎾</div>
            <div class="cf-val" style="color:var(--danger);">₱<?= number_format($creditsSpentInGames, 0) ?></div>
            <div class="cf-label">Spent in Games</div>
            <div class="cf-sub">Deducted via QR scan</div>
        </div>

        <div class="cf-block" style="border-color:rgba(0,229,160,0.3);">
            <div style="font-size:22px;margin-bottom:6px;">💰</div>
            <div class="cf-val" style="color:var(--accent);">₱<?= number_format($creditsUnspent, 0) ?></div>
            <div class="cf-label">Unspent in Wallets</div>
            <div class="cf-sub">Active players only</div>
        </div>

    </div>

    <!-- Breakdown note -->
    <div style="background:rgba(0,229,160,0.04);border:1px solid var(--border);border-radius:10px;
                padding:12px 16px;font-size:12px;color:var(--muted);line-height:2;">
        <span style="color:var(--accent2);font-weight:700;">₱<?= number_format($creditsDistributed, 2) ?></span> distributed
        &nbsp;−&nbsp;
        <span style="color:var(--danger);font-weight:700;">₱<?= number_format($creditsSpentInGames, 2) ?></span> spent
        &nbsp;=&nbsp;
        <span style="color:var(--accent);font-weight:700;">₱<?= number_format($theoreticalRemaining, 2) ?></span> expected remaining
        &nbsp;·&nbsp;
        Wallets hold <span style="color:var(--accent);font-weight:700;">₱<?= number_format($creditsUnspent, 2) ?></span>
        <?php if ($diff > 0.01): ?>
            &nbsp;·&nbsp;
            <span style="color:var(--warn);">
                ₱<?= number_format($diff, 2) ?> gap
                (refunds, manual adjustments, or cancelled deductions)
            </span>
        <?php else: ?>
            &nbsp;·&nbsp;
            <span style="color:var(--success);">✓ Balances reconcile</span>
        <?php endif; ?>
    </div>
</div>

<!-- ── Daily chart + right sidebar ── -->
<div class="layout-main-panel">

    <div class="card">
        <div class="card-title">📊 Daily Revenue — <?= $monthLabel ?></div>
        <div class="card-subtitle">Revenue per day from completed games</div>
        <hr class="divider"/>
        <?php if (empty($daily)): ?>
            <p class="text-muted text-center" style="padding:32px;">No data for this month.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:6px;max-height:360px;overflow-y:auto;">
                <?php foreach ($daily as $d):
                    $pct     = $maxRevenue > 0 ? round($d['revenue'] / $maxRevenue * 100) : 0;
                    $isToday = ($d['day'] === date('Y-m-d'));
                ?>
                    <div class="reports-bar-item">
                        <div class="reports-bar-label"><?= date('M d', strtotime($d['day'])) ?></div>
                        <div class="reports-bar-track">
                            <div style="width:<?= $pct ?>%;height:100%;
                                        background:<?= $isToday ? 'var(--accent2)' : 'var(--accent)' ?>;
                                        border-radius:4px;opacity:0.8;transition:width 0.5s;"></div>
                            <div style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                        font-size:11px;color:var(--text);font-weight:600;">
                                ₱<?= number_format($d['revenue'], 0) ?>
                            </div>
                        </div>
                        <div class="reports-bar-sessions"><?= $d['sessions'] ?>g</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:20px;min-width:0;">

        <div class="card">
            <div class="card-title">🏓 By Court</div>
            <hr class="divider"/>
            <?php if (empty($courtRevenue)): ?>
                <p class="text-muted" style="font-size:13px;">No data.</p>
            <?php else: ?>
                <?php foreach ($courtRevenue as $cr): ?>
                    <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border);">
                        <div style="min-width:0;">
                            <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= clean($cr['court_name']) ?>
                            </div>
                            <div style="font-size:12px;color:var(--muted);"><?= $cr['sessions'] ?> sessions</div>
                        </div>
                        <div style="font-weight:700;color:var(--accent);flex-shrink:0;margin-left:8px;">
                            ₱<?= number_format($cr['revenue'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">💳 Top-Ups (<?= $monthLabel ?>)</div>
            <hr class="divider"/>
            <div class="topup-stat-grid">
                <div style="text-align:center;padding:12px;background:var(--surface2);border-radius:10px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--success);"><?= $topup['approved_count'] ?></div>
                    <div style="font-size:11px;color:var(--muted);">Approved</div>
                </div>
                <div style="text-align:center;padding:12px;background:var(--surface2);border-radius:10px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--warn);"><?= $topup['pending_count'] ?></div>
                    <div style="font-size:11px;color:var(--muted);">Pending</div>
                </div>
            </div>
            <div style="text-align:center;padding:12px;background:rgba(0,229,160,0.06);
                        border:1px solid var(--accent);border-radius:10px;">
                <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);">
                    ₱<?= number_format($topup['total_loaded'], 0) ?>
                </div>
                <div style="font-size:11px;color:var(--muted);">Total Credits Loaded</div>
            </div>
        </div>

    </div>
</div>

<!-- ── Peak Hours + Top Players ── -->
<div class="layout-two-equal">

    <div class="card">
        <div class="card-title">⏰ Peak Hours</div>
        <div class="card-subtitle">When most games are played</div>
        <hr class="divider"/>
        <?php if (empty($peakHours)): ?>
            <p class="text-muted text-center" style="padding:24px;">No data.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:5px;">
                <?php for ($h = 10; $h <= 23; $h++):
                    $count  = $peakHours[$h] ?? 0;
                    $pct    = $maxSessions > 0 ? round($count / $maxSessions * 100) : 0;
                    $label  = date('h A', mktime($h, 0, 0));
                    $isPeak = $count > 0 && $count === max(array_values($peakHours) ?: [0]);
                ?>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="font-size:11px;color:var(--muted);min-width:50px;font-family:monospace;flex-shrink:0;text-align:right;"><?= $label ?></div>
                        <div style="flex:1;background:var(--surface2);border-radius:3px;height:16px;overflow:hidden;min-width:40px;">
                            <div style="width:<?= $pct ?>%;height:100%;
                                        background:<?= $isPeak ? 'var(--warn)' : 'var(--accent2)' ?>;
                                        opacity:0.7;border-radius:3px;"></div>
                        </div>
                        <div style="font-size:11px;color:var(--muted);min-width:20px;text-align:right;flex-shrink:0;"><?= $count ?: '' ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">🏆 Top Players</div>
        <div class="card-subtitle">Most active players this month</div>
        <hr class="divider"/>
        <?php if (empty($topPlayers)): ?>
            <p class="text-muted text-center" style="padding:24px;">No data.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>#</th><th>Player</th><th>Games</th><th>Spent</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPlayers as $i => $p): ?>
                            <tr>
                                <td style="font-family:monospace;color:var(--muted);">
                                    <?php
                                    if ($i === 0)     echo '🥇';
                                    elseif ($i === 1) echo '🥈';
                                    elseif ($i === 2) echo '🥉';
                                    else              echo $i + 1;
                                    ?>
                                </td>
                                <td>
                                    <strong><?= clean($p['full_name']) ?></strong><br/>
                                    <span style="font-size:11px;color:var(--muted);">@<?= clean($p['username']) ?></span>
                                </td>
                                <td style="font-weight:700;color:var(--accent2);"><?= $p['games'] ?></td>
                                <td style="color:var(--accent);">₱<?= number_format($p['spent'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Food Sales ── -->
<div class="layout-main-panel">

    <div class="card">
        <div class="card-title">🍔 Daily Food Revenue — <?= $monthLabel ?></div>
        <div class="card-subtitle">Revenue per day from paid food orders</div>
        <hr class="divider"/>
        <?php if (empty($foodDaily)): ?>
            <p class="text-muted text-center" style="padding:32px;">No food orders this month.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:6px;max-height:360px;overflow-y:auto;">
                <?php foreach ($foodDaily as $d):
                    $pct     = $maxFoodRevenue > 0 ? round($d['revenue'] / $maxFoodRevenue * 100) : 0;
                    $isToday = ($d['day'] === date('Y-m-d'));
                ?>
                    <div class="reports-bar-item">
                        <div class="reports-bar-label"><?= date('M d', strtotime($d['day'])) ?></div>
                        <div class="reports-bar-track">
                            <div style="width:<?= $pct ?>%;height:100%;
                                        background:<?= $isToday ? 'var(--accent2)' : 'var(--accent)' ?>;
                                        border-radius:4px;opacity:0.8;transition:width 0.5s;"></div>
                            <div style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                        font-size:11px;color:var(--text);font-weight:600;">
                                ₱<?= number_format($d['revenue'], 0) ?>
                            </div>
                        </div>
                        <div class="reports-bar-sessions"><?= $d['orders'] ?>o</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:20px;min-width:0;">

        <div class="card">
            <div class="card-title">📦 Food Orders (<?= $monthLabel ?>)</div>
            <hr class="divider"/>
            <div class="topup-stat-grid">
                <div style="text-align:center;padding:12px;background:var(--surface2);border-radius:10px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--success);"><?= $foodSummary['orders_paid'] ?></div>
                    <div style="font-size:11px;color:var(--muted);">Paid Orders</div>
                </div>
                <div style="text-align:center;padding:12px;background:var(--surface2);border-radius:10px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--danger);"><?= $foodSummary['orders_cancelled'] ?></div>
                    <div style="font-size:11px;color:var(--muted);">Cancelled</div>
                </div>
            </div>
            <div style="text-align:center;padding:12px;background:rgba(0,229,160,0.06);
                        border:1px solid var(--accent);border-radius:10px;">
                <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);">
                    <?= $foodSummary['food_customers'] ?>
                </div>
                <div style="font-size:11px;color:var(--muted);">Unique Customers</div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🏅 Top Food Items</div>
            <div class="card-subtitle">Best sellers this month, by quantity</div>
            <hr class="divider"/>
            <?php if (empty($topFoodItems)): ?>
                <p class="text-muted" style="font-size:13px;">No data.</p>
            <?php else: ?>
                <?php foreach ($topFoodItems as $fi): ?>
                    <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border);">
                        <div style="min-width:0;">
                            <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= clean($fi['item_name']) ?>
                            </div>
                            <div style="font-size:12px;color:var(--muted);"><?= $fi['qty_sold'] ?> sold</div>
                        </div>
                        <div style="font-weight:700;color:var(--accent);flex-shrink:0;margin-left:8px;">
                            ₱<?= number_format($fi['revenue'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ── Export ── -->
<div class="card">
    <div class="card-title">⬇ Export Data</div>
    <div class="card-subtitle">Download records as CSV for external use</div>
    <hr class="divider"/>
    <div class="export-buttons">
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=games&month=<?= $month ?>"   class="btn-outline btn-sm">🎮 Game Sessions CSV</a>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=revenue&month=<?= $month ?>" class="btn-outline btn-sm">💰 Daily Revenue CSV</a>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=topups&month=<?= $month ?>"  class="btn-outline btn-sm">💳 Top-Up Requests CSV</a>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=players&month=<?= $month ?>" class="btn-outline btn-sm">👥 Player Activity CSV</a>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=food&month=<?= $month ?>"    class="btn-outline btn-sm">🍔 Food Orders CSV</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>