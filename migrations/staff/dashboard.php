<?php
// ============================================================
//  FILE: staff/dashboard.php
//  Staff — floor-operations console (queueing, courts, tournament day-ops)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$db   = getDB();
$user = currentUser();

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM falcon.courts WHERE is_active = TRUE)                                          AS active_courts,
        (SELECT COUNT(*) FROM falcon.game_sessions WHERE status IN ('waiting','active'))                      AS live_sessions,
        (SELECT COUNT(*) FROM falcon.game_sessions WHERE status = 'completed' AND ended_at >= CURRENT_DATE)   AS games_today,
        (SELECT COUNT(*) FROM falcon.reservations WHERE status = 'pending')                                   AS pending_reservations,
        (SELECT COUNT(*) FROM falcon.tournaments WHERE status = 'active')                                     AS active_tournaments
")->fetch();

$courts = $db->query("
    SELECT c.id, c.name, c.max_queue,
           gs.id AS session_id, gs.status AS session_status, gs.started_at,
           COUNT(gp.id) AS player_count
    FROM falcon.courts c
    LEFT JOIN falcon.game_sessions gs ON gs.court_id = c.id AND gs.status IN ('waiting','active')
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE c.is_active = TRUE
    GROUP BY c.id, c.name, c.max_queue, gs.id, gs.status, gs.started_at
    ORDER BY c.name ASC
")->fetchAll();

$activeTournaments = $db->query("
    SELECT id, name, status, bracket_type, current_players, max_players
    FROM falcon.tournaments
    WHERE status IN ('active', 'registration_open', 'registration_closed')
    ORDER BY start_date ASC NULLS LAST
    LIMIT 6
")->fetchAll();

$pageTitle = 'Staff Console';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.staff-stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 20px; }
.staff-courts-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
.staff-court-card { border-radius: 12px; }
.staff-quick-actions { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
@media (max-width: 900px) {
    .staff-stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>🧑‍💼 Staff Console</h1>
        <p>Hi <?= clean($user['full_name'] ?: $user['username']) ?> — here's what's happening on the floor right now.</p>
    </div>
    <div class="sa-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/court/scanner.php" class="btn-primary btn-sm">📷 Court Scanner</a>
        <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-outline btn-sm">🎮 Game Monitor</a>
        <a href="<?= APP_URL ?>/staff/tournament_queue.php" class="btn-outline btn-sm">🏆 Tournament Ops</a>
    </div>
</div>

<div class="staff-stats-grid">
    <div class="stat-card">
        <div class="stat-val"><?= (int)$stats['active_courts'] ?></div>
        <div class="stat-label">Active Courts</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= (int)$stats['live_sessions'] ?></div>
        <div class="stat-label">Live Sessions</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);"><?= (int)$stats['games_today'] ?></div>
        <div class="stat-label">Games Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= (int)$stats['pending_reservations'] ?></div>
        <div class="stat-label">Pending Reservations</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--info);"><?= (int)$stats['active_tournaments'] ?></div>
        <div class="stat-label">Active Tournaments</div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title mb-1">🏓 Court Status</div>
    <hr class="divider"/>
    <div class="staff-courts-grid">
        <?php foreach ($courts as $c): ?>
        <div class="card staff-court-card">
            <div class="flex-between" style="align-items:flex-start;">
                <strong><?= clean($c['name']) ?></strong>
                <?php if ($c['session_status'] === 'active'): ?>
                    <span class="badge badge-success">In Play</span>
                <?php elseif ($c['session_status'] === 'waiting'): ?>
                    <span class="badge badge-warn">Queueing</span>
                <?php else: ?>
                    <span class="badge badge-muted">Idle</span>
                <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-top:6px;">
                <?= (int)$c['player_count'] ?> / <?= (int)$c['max_queue'] ?> players
                <?php if ($c['started_at']): ?>
                    · started <?= date('h:i A', strtotime($c['started_at'])) ?>
                <?php endif; ?>
            </div>
            <a href="<?= APP_URL ?>/admin/active_game.php?court_id=<?= $c['id'] ?>" class="btn-outline btn-sm" style="margin-top:10px;width:100%;text-align:center;">Manage →</a>
        </div>
        <?php endforeach; ?>
        <?php if (empty($courts)): ?>
            <p class="text-muted">No active courts configured yet.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title mb-1">🏆 Tournaments Needing Floor Attention</div>
    <hr class="divider"/>
    <?php if (empty($activeTournaments)): ?>
        <p class="text-muted text-center" style="padding:16px;">No tournaments in progress right now.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tournament</th><th>Status</th><th>Format</th><th>Players</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activeTournaments as $t): ?>
                <tr>
                    <td style="font-weight:600;"><?= clean($t['name']) ?></td>
                    <td><span class="badge badge-info"><?= clean(str_replace('_',' ', $t['status'])) ?></span></td>
                    <td style="font-size:12px;color:var(--muted);"><?= clean(str_replace('_',' ', $t['bracket_type'])) ?></td>
                    <td><?= (int)$t['current_players'] ?><?= $t['max_players'] ? ' / ' . (int)$t['max_players'] : '' ?></td>
                    <td><a href="<?= APP_URL ?>/staff/tournament_queue.php?tournament_id=<?= $t['id'] ?>" class="btn-outline btn-sm">Open →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title mb-1">Quick Actions</div>
    <hr class="divider"/>
    <div class="staff-quick-actions">
        <a href="<?= APP_URL ?>/court/scanner.php" class="btn-outline btn-sm">📷 Scan QR / Check-in</a>
        <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-outline btn-sm">🎮 Manage Live Games</a>
        <a href="<?= APP_URL ?>/admin/court_mode.php" class="btn-outline btn-sm">🏓 Switch Court Mode</a>
        <a href="<?= APP_URL ?>/staff/tournament_queue.php" class="btn-outline btn-sm">🏆 Tournament Queue &amp; Bracket</a>
        <a href="<?= APP_URL ?>/admin/kiosk.php" class="btn-outline btn-sm">🖥️ Open Kiosk Display</a>
        <a href="<?= APP_URL ?>/admin/game_history.php" class="btn-outline btn-sm">📋 Game History</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
