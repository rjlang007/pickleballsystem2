<?php
// ============================================================
//  FILE: admin/players.php
//  Full player list — search, filter, ban/unban, reset password
//  FIXED: csrfField() echo, CSP eval, reset forces password change,
//         verify confirmation modal, notifications on both actions
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); // no args — reads $_POST itself

    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    if ($uid && in_array($action, ['ban','unban','reset_password','adjust_balance','verify'])) {

        if ($action === 'ban') {
            $reason = sanitizeString($_POST['ban_reason'] ?? 'Violation of court rules.', 255);
            $db->prepare("UPDATE falcon.users SET is_banned=TRUE, ban_reason=?, updated_at=NOW() WHERE id=? AND role='player'")->execute([$reason, $uid]);
            // Notify player
            $db->prepare("INSERT INTO falcon.notifications (user_id,title,message,type) VALUES (?,?,?,'warning')")
               ->execute([$uid, '🚫 Account Banned', 'Your account has been banned. Reason: ' . $reason]);
            setFlash('success', 'Player banned.');

        } elseif ($action === 'unban') {
            $db->prepare("UPDATE falcon.users SET is_banned=FALSE, ban_reason=NULL, updated_at=NOW() WHERE id=?")->execute([$uid]);
            $db->prepare("INSERT INTO falcon.notifications (user_id,title,message,type) VALUES (?,?,?,'success')")
               ->execute([$uid, '✅ Account Reinstated', 'Your account ban has been lifted. Welcome back!']);
            setFlash('success', 'Player unbanned.');

        } elseif ($action === 'reset_password') {
            $newPassword = trim($_POST['new_password'] ?? '');
            if (strlen($newPassword) >= 6) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                // Force password change on next login
                $db->prepare("UPDATE falcon.users SET password_hash=?, must_change_password=TRUE, updated_at=NOW() WHERE id=? AND role='player'")->execute([$hash, $uid]);
                $db->prepare("INSERT INTO falcon.notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                   ->execute([$uid, '🔑 Password Reset', 'Your password has been reset by an admin. Please log in with your new password.']);
                setFlash('success', 'Password reset. Player will be prompted to change it on next login.');
            } else {
                setFlash('error', 'Password must be at least 6 characters.');
            }

        } elseif ($action === 'verify') {
            $db->prepare("UPDATE falcon.users SET is_verified=TRUE, updated_at=NOW() WHERE id=? AND role='player'")->execute([$uid]);
            $db->prepare("INSERT INTO falcon.notifications (user_id,title,message,type) VALUES (?,?,?,'success')")
               ->execute([$uid, '✅ Account Verified', 'Your account has been verified by an admin. You now have full access.']);
            setFlash('success', 'Player verified successfully.');

        } elseif ($action === 'adjust_balance') {
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $type   = ($_POST['adj_type'] ?? 'add') === 'add' ? 'add' : 'deduct';
            $reason = sanitizeString($_POST['adj_reason'] ?? 'Admin adjustment', 255);
            if ($amount && $amount > 0) {
                $db->beginTransaction();
                try {
                    $walStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id=? FOR UPDATE");
                    $walStmt->execute([$uid]);
                    $current = (float)($walStmt->fetchColumn() ?? 0);
                    $newBal  = $type === 'add' ? $current + $amount : max(0, $current - $amount);
                    $db->prepare("INSERT INTO falcon.wallets(user_id,balance) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET balance=EXCLUDED.balance,updated_at=NOW()")->execute([$uid, $newBal]);
                    $db->prepare("INSERT INTO falcon.transactions (user_id,type,method,amount,note,status,balance_before,balance_after,processed_by) VALUES(?,?,?,?,?,?,?,?,?)")
                       ->execute([$uid, $type==='add'?'topup':'deduction', 'admin', $amount, 'Admin '.$type.': '.$reason, 'approved', $current, $newBal, $_SESSION['user_id']]);
                    $db->prepare("INSERT INTO falcon.notifications(user_id,title,message,type) VALUES(?,?,?,'info')")
                       ->execute([$uid, $type==='add'?'💰 Credits Added':'💸 Credits Deducted', ($type==='add'?'+':'-').'₱'.number_format($amount,2).' — '.$reason]);
                    $db->commit();
                    setFlash('success', 'Balance updated. New balance: ₱' . number_format($newBal,2));
                } catch (PDOException $e) {
                    $db->rollBack();
                    setFlash('error', 'Failed to adjust balance.');
                }
            } else {
                setFlash('error', 'Enter a valid amount.');
            }
        }
    }
    redirect('admin/players.php?' . http_build_query(array_intersect_key($_GET, array_flip(['search','status','page']))));
}

// ── CSV Export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $expStmt = $db->query("SELECT u.id,u.username,u.full_name,u.email,u.phone,u.is_verified,u.is_banned,u.created_at,COALESCE(w.balance,0) AS balance,COUNT(DISTINCT gp.session_id) AS games_played FROM falcon.users u LEFT JOIN falcon.wallets w ON w.user_id=u.id LEFT JOIN falcon.game_players gp ON gp.user_id=u.id WHERE u.role='player' GROUP BY u.id,w.balance ORDER BY u.created_at DESC");
    $rows = $expStmt->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="players_'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['ID','Username','Full Name','Email','Phone','Verified','Banned','Balance','Games','Joined']);
    foreach ($rows as $r) {
        fputcsv($out,[$r['id'],$r['username'],$r['full_name'],$r['email'],$r['phone'],
                      $r['is_verified']?'Yes':'No',$r['is_banned']?'Yes':'No',
                      $r['balance'],$r['games_played'],$r['created_at']]);
    }
    fclose($out); exit;
}

// ── Filters ──────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$fStatus = $_GET['status'] ?? 'all';
$fSort   = $_GET['sort']   ?? 'newest';
$fPage   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($fPage - 1) * $perPage;

$where  = "WHERE u.role = 'player'";
$params = [];

if ($search !== '') {
    $where   .= " AND (u.username ILIKE ? OR u.full_name ILIKE ? OR u.phone ILIKE ? OR u.email ILIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($fStatus === 'banned')     { $where .= " AND u.is_banned = TRUE"; }
if ($fStatus === 'active')     { $where .= " AND u.is_banned = FALSE AND u.is_verified = TRUE"; }
if ($fStatus === 'unverified') { $where .= " AND u.is_verified = FALSE"; }
if ($fStatus === 'zero')       { $where .= " AND COALESCE(w.balance,0) = 0"; }

$orderBy = match($fSort) {
    'balance' => 'w.balance DESC',
    'games'   => 'games_played DESC',
    'alpha'   => 'u.full_name ASC',
    default   => 'u.created_at DESC',
};

$countStmt = $db->prepare("SELECT COUNT(*) FROM falcon.users u LEFT JOIN falcon.wallets w ON w.user_id=u.id $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT u.id, u.username, u.full_name, u.email, u.phone,
           u.role, u.is_verified, u.is_banned, u.ban_reason,
           u.created_at, u.avatar_path,
           COALESCE(w.balance, 0) AS balance,
           COUNT(DISTINCT gp.session_id) AS games_played,
           MAX(gs.started_at) AS last_played
    FROM falcon.users u
    LEFT JOIN falcon.wallets w ON w.user_id = u.id
    LEFT JOIN falcon.game_players gp ON gp.user_id = u.id
    LEFT JOIN falcon.game_sessions gs ON gs.id = gp.session_id AND gs.status='completed'
    $where
    GROUP BY u.id, u.username, u.full_name, u.email, u.phone,
             u.role, u.is_verified, u.is_banned, u.ban_reason,
             u.created_at, u.avatar_path, w.balance
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$players = $stmt->fetchAll();

$counts = $db->query("
    SELECT
        COUNT(*)                                    AS total,
        COUNT(*) FILTER (WHERE is_banned)           AS banned,
        COUNT(*) FILTER (WHERE NOT is_verified)     AS unverified,
        COALESCE(SUM(w.balance),0)                  AS total_balance,
        COUNT(*) FILTER (WHERE COALESCE(w.balance,0)=0 AND NOT is_banned) AS zero_balance
    FROM falcon.users u LEFT JOIN falcon.wallets w ON w.user_id=u.id WHERE role='player'
")->fetch();

// Pre-generate one CSRF token for all JS-triggered forms
$csrf = csrfToken();

$pageTitle = 'Manage Players';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.players-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.players-filter {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.players-filter .pf-group { flex: 1; min-width: 140px; max-width: 100%; }
.players-filter .pf-group.wide { flex: 2; min-width: 200px; }
.players-filter .pf-group label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 5px;
}
.players-filter .pf-actions { display: flex; gap: 6px; align-items: flex-end; flex-shrink: 0; flex-wrap: wrap; }

.player-cell { display: flex; align-items: center; gap: 8px; min-width: 0; }
.player-avatar { width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0; }
.player-avatar-initial {
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,#059669,#00e5a0);
    display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:800;color:#022c22;flex-shrink:0;
}
.player-name-wrap { min-width:0;overflow:hidden; }
.player-name-wrap strong { font-size:13px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.player-name-wrap span   { font-size:11px;color:var(--muted); }

.bal-chip { display:inline-block;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap; }
.player-actions { display:flex;gap:4px;flex-wrap:wrap;align-items:center; }

/* Modal */
.pm-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,0.78);
    z-index:9999;align-items:center;justify-content:center;
    padding:16px;padding-bottom:max(16px,env(safe-area-inset-bottom));box-sizing:border-box;
}
.pm-overlay.open { display:flex; }
.pm-box {
    background:var(--surface);border:1px solid var(--border);border-radius:16px;
    padding:28px;width:100%;max-width:440px;max-height:90dvh;overflow-y:auto;box-sizing:border-box;
}
.pm-title { font-family:'Bebas Neue',sans-serif;font-size:24px;margin-bottom:4px; }
.pm-sub   { font-size:13px;color:var(--muted);margin-bottom:18px; }
.pm-group { margin-bottom:14px; }
.pm-group label { display:block;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px; }
.pm-group textarea { resize:vertical; }
.pm-footer { display:flex;gap:10px;margin-top:16px; }
.pm-footer button, .pm-footer a { flex:1; }
.bal-type-grid { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px; }
.bal-type-label { cursor:pointer;padding:12px;border-radius:10px;text-align:center;transition:opacity 0.2s; }

@media (max-width:600px) {
    .players-filter .pf-group,
    .players-filter .pf-group.wide { flex:1 1 calc(50% - 10px);min-width:120px; }
    .players-filter .pf-actions { width:100%; }
    .players-filter .pf-actions .btn-primary,
    .players-filter .pf-actions .btn-outline { flex:1; }
}
@media (max-width:420px) {
    .players-filter .pf-group,
    .players-filter .pf-group.wide { flex:1 1 100%; }
    .pm-box { padding:20px;border-radius:14px; }
    .pm-footer { flex-direction:column; }
    .pm-footer button, .pm-footer a { width:100%; }
    .bal-type-grid { grid-template-columns:1fr; }
}
</style>

<!-- Page Header -->
<div class="page-header flex-between">
    <div>
        <h1>Players</h1>
        <p>Manage all registered players · <?= number_format($counts['total']) ?> total</p>
    </div>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>1])) ?>" class="btn-outline btn-sm">⬇ Export CSV</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- Summary Stats -->
<div class="players-stats">
    <div class="stat-card"><div class="stat-val"><?= number_format($counts['total']) ?></div><div class="stat-label">Total Players</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--danger);"><?= $counts['banned'] ?></div><div class="stat-label">Banned</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--warn);"><?= $counts['unverified'] ?></div><div class="stat-label">Unverified</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--warn);"><?= $counts['zero_balance'] ?></div><div class="stat-label">Zero Balance</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--accent);">₱<?= number_format($counts['total_balance'],0) ?></div><div class="stat-label">Credits Held</div></div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="players-filter">
        <div class="pf-group wide">
            <label>Search</label>
            <input type="text" name="search" value="<?= clean($search) ?>" placeholder="Username, name, phone, email…"/>
        </div>
        <div class="pf-group">
            <label>Status</label>
            <select name="status">
                <?php foreach (['all'=>'All Players','active'=>'Active','banned'=>'Banned','unverified'=>'Unverified','zero'=>'Zero Balance'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pf-group">
            <label>Sort By</label>
            <select name="sort">
                <?php foreach (['newest'=>'Newest First','alpha'=>'Name A–Z','balance'=>'Highest Balance','games'=>'Most Games'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $fSort===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pf-actions">
            <button type="submit" class="btn-primary btn-sm">Search</button>
            <a href="?" class="btn-outline btn-sm">Reset</a>
        </div>
    </form>
</div>

<!-- Players Table -->
<div class="card">
    <div class="flex-between mb-2">
        <div class="card-title">👥 Player List</div>
        <div style="font-size:13px;color:var(--muted);"><?= $totalRows ?> results · Page <?= $fPage ?>/<?= $totalPages ?></div>
    </div>
    <hr class="divider"/>

    <?php if (empty($players)): ?>
        <div style="text-align:center;padding:40px;">
            <div style="font-size:48px;">👤</div>
            <p class="text-muted mt-1">No players found.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Player</th>
                        <th class="col-hide-sm">Phone</th>
                        <th>Balance</th>
                        <th>Games</th>
                        <th class="col-hide-sm">Last Played</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($players as $p): ?>
                        <tr id="player-row-<?= $p['id'] ?>">
                            <td>
                                <div class="player-cell">
                                    <?php
                                    $avatarUrl = ($p['avatar_path'] && file_exists(UPLOAD_AVATARS . $p['avatar_path']))
                                        ? APP_URL . '/uploads/avatars/' . urlencode($p['avatar_path']) : null;
                                    ?>
                                    <?php if ($avatarUrl): ?>
                                        <img src="<?= $avatarUrl ?>" class="player-avatar" alt=""/>
                                    <?php else: ?>
                                        <div class="player-avatar-initial"><?= strtoupper(substr($p['username'],0,1)) ?></div>
                                    <?php endif; ?>
                                    <div class="player-name-wrap">
                                        <strong>
                                            <?= clean($p['full_name']) ?>
                                            <?php if (!$p['is_verified']): ?>
                                                <span title="Unverified" style="color:var(--warn);">⚠</span>
                                            <?php endif; ?>
                                        </strong>
                                        <span>@<?= clean($p['username']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:12px;"><?= clean($p['phone'] ?? '') ?></td>
                            <td>
                                <?php
                                $bal = (float)$p['balance'];
                                $balColor = $bal >= 50 ? 'var(--success)' : ($bal > 0 ? 'var(--warn)' : 'var(--danger)');
                                ?>
                                <span class="bal-chip" style="background:rgba(0,0,0,0.2);color:<?= $balColor ?>;border:1px solid <?= $balColor ?>30;">
                                    ₱<?= number_format($bal,2) ?>
                                </span><br/>
                                <button
                                    data-action="balance"
                                    data-uid="<?= $p['id'] ?>"
                                    data-name="<?= clean($p['full_name']) ?>"
                                    data-balance="<?= number_format($bal, 2, '.', '') ?>"
                                    style="font-size:10px;color:var(--muted);background:none;border:none;cursor:pointer;margin-top:3px;padding:0;touch-action:manipulation;">
                                    ✏️ adjust
                                </button>
                            </td>
                            <td style="text-align:center;font-weight:700;color:<?= $p['games_played']>0?'var(--accent)':'var(--muted)' ?>;">
                                <?= $p['games_played'] ?>
                            </td>
                            <td class="col-hide-sm" style="font-size:11px;color:var(--muted);">
                                <?php if ($p['last_played']): ?>
                                    <?= date('M d', strtotime($p['last_played'])) ?><br/>
                                    <?= date('h:i A', strtotime($p['last_played'])) ?>
                                <?php else: ?>
                                    <span style="color:var(--border);">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['is_banned']): ?>
                                    <span class="badge badge-danger" title="<?= clean($p['ban_reason']??'') ?>">🚫 Banned</span>
                                <?php elseif (!$p['is_verified']): ?>
                                    <span class="badge badge-warn">⚠️ Unverified</span>
                                <?php else: ?>
                                    <span class="badge badge-success">✅ Active</span>
                                <?php endif; ?>
                                <br/>
                                <span style="font-size:10px;color:var(--muted);"><?= date('M d, Y', strtotime($p['created_at'])) ?></span>
                            </td>
                            <td>
                                <div class="player-actions">
                                    <a href="<?= APP_URL ?>/admin/player_view.php?id=<?= $p['id'] ?>"
                                       class="btn-outline btn-sm" title="View Profile">👁</a>

                                    <?php if (!$p['is_verified']): ?>
                                    <button
                                        class="btn-outline btn-sm"
                                        data-action="verify"
                                        data-uid="<?= $p['id'] ?>"
                                        data-name="<?= clean($p['full_name']) ?>">
                                        ✓ Verify
                                    </button>
                                    <?php endif; ?>

                                    <button
                                        class="btn-outline btn-sm"
                                        data-action="reset"
                                        data-uid="<?= $p['id'] ?>"
                                        data-username="<?= clean($p['username']) ?>">
                                        🔑
                                    </button>

                                    <?php if ($p['is_banned']): ?>
                                        <button
                                            class="btn-success btn-sm"
                                            data-action="unban"
                                            data-uid="<?= $p['id'] ?>"
                                            data-name="<?= clean($p['username']) ?>">
                                            ✅ Unban
                                        </button>
                                    <?php else: ?>
                                        <button
                                            class="btn-danger btn-sm"
                                            data-action="ban"
                                            data-uid="<?= $p['id'] ?>"
                                            data-name="<?= clean($p['username']) ?>">
                                            🚫 Ban
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($fPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$fPage-1])) ?>" class="btn-outline btn-sm">← Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$fPage-2); $pg <= min($totalPages,$fPage+2); $pg++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pg])) ?>"
                       class="btn-<?= $pg===$fPage?'primary':'outline' ?> btn-sm"><?= $pg ?></a>
                <?php endfor; ?>
                <?php if ($fPage < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$fPage+1])) ?>" class="btn-outline btn-sm">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ──────────────────────────────────────────────────────────
     SINGLE shared hidden form — all modal actions POST through here.
     One CSRF token, no inline forms inside the table loop.
─────────────────────────────────────────────────────────────── -->
<form id="shared-action-form" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>"/>
    <input type="hidden" name="action"  id="sf-action"/>
    <input type="hidden" name="user_id" id="sf-user-id"/>
    <!-- ban -->
    <input type="hidden" name="ban_reason"   id="sf-ban-reason"/>
    <!-- reset -->
    <input type="hidden" name="new_password" id="sf-new-password"/>
    <!-- balance -->
    <input type="hidden" name="adj_type"   id="sf-adj-type"/>
    <input type="hidden" name="amount"     id="sf-amount"/>
    <input type="hidden" name="adj_reason" id="sf-adj-reason"/>
</form>

<!-- ── Verify Modal ── -->
<div id="verify-modal" class="pm-overlay" role="dialog" aria-modal="true" aria-labelledby="verify-modal-title">
    <div class="pm-box">
        <div class="pm-title" id="verify-modal-title" style="color:var(--success);">✅ Verify Player</div>
        <div class="pm-sub" id="verify-modal-name"></div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;">
            Verifying this account grants the player full access to the system.
            They will receive a notification confirming their verification.
        </p>
        <div class="pm-footer">
            <button type="button" id="verify-confirm-btn" class="btn-success">✅ Confirm Verify</button>
            <button type="button" onclick="closeModal('verify-modal')" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Ban Modal ── -->
<div id="ban-modal" class="pm-overlay" role="dialog" aria-modal="true" aria-labelledby="ban-modal-title">
    <div class="pm-box">
        <div class="pm-title" id="ban-modal-title" style="color:var(--danger);">🚫 Ban Player</div>
        <div class="pm-sub" id="ban-modal-name"></div>
        <div class="pm-group">
            <label>Reason for ban</label>
            <textarea id="ban-reason-input" rows="3">Violation of court rules.</textarea>
        </div>
        <div class="pm-footer">
            <button type="button" id="ban-confirm-btn" class="btn-danger">Confirm Ban</button>
            <button type="button" onclick="closeModal('ban-modal')" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Reset Password Modal ── -->
<div id="reset-modal" class="pm-overlay" role="dialog" aria-modal="true" aria-labelledby="reset-modal-title">
    <div class="pm-box">
        <div class="pm-title" id="reset-modal-title">🔑 Reset Password</div>
        <div class="pm-sub" id="reset-modal-name"></div>
        <div class="pm-group">
            <label>New Password</label>
            <div style="position:relative;">
                <input type="text" id="new-password-inp" placeholder="Min. 6 characters" style="padding-right:90px;"/>
                <button type="button" id="gen-password-btn"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                               background:rgba(0,229,160,0.12);border:1px solid var(--accent);
                               color:var(--accent);border-radius:6px;padding:3px 10px;
                               font-size:11px;cursor:pointer;white-space:nowrap;touch-action:manipulation;">
                    🎲 Generate
                </button>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">
                Player will be required to change this on next login.
            </div>
        </div>
        <div class="pm-footer">
            <button type="button" id="reset-confirm-btn" class="btn-primary">Set Password</button>
            <button type="button" onclick="closeModal('reset-modal')" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Balance Adjust Modal ── -->
<div id="balance-modal" class="pm-overlay" role="dialog" aria-modal="true" aria-labelledby="balance-modal-title">
    <div class="pm-box">
        <div class="pm-title" id="balance-modal-title">💰 Adjust Balance</div>
        <div class="pm-sub" id="balance-modal-name"></div>
        <div class="bal-type-grid">
            <label id="bal-add-label" class="bal-type-label" style="border:2px solid var(--success);background:rgba(16,185,129,0.08);">
                <input type="radio" name="bal_type_ui" value="add" id="bal-type-add" style="display:none;"/>
                <div style="font-size:20px;">➕</div>
                <div style="font-size:13px;font-weight:700;color:var(--success);">Add Credits</div>
            </label>
            <label id="bal-deduct-label" class="bal-type-label" style="border:2px solid var(--border);background:transparent;">
                <input type="radio" name="bal_type_ui" value="deduct" id="bal-type-deduct" style="display:none;"/>
                <div style="font-size:20px;">➖</div>
                <div style="font-size:13px;font-weight:700;color:var(--danger);">Deduct Credits</div>
            </label>
        </div>
        <div class="pm-group">
            <label>Amount (₱)</label>
            <input type="number" id="balance-amount-inp" min="1" step="0.01" placeholder="e.g. 50.00"/>
        </div>
        <div class="pm-group">
            <label>Reason</label>
            <input type="text" id="balance-reason-inp" placeholder="e.g. Correction, refund…" maxlength="255"/>
        </div>
        <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;
                    padding:10px;font-size:12px;color:var(--warn);margin-bottom:14px;">
            ⚠️ Current balance: <strong id="balance-modal-current"></strong>
        </div>
        <div class="pm-footer">
            <button type="button" id="balance-confirm-btn" class="btn-primary">Confirm</button>
            <button type="button" onclick="closeModal('balance-modal')" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────
    function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }

    // Close on backdrop click or Escape
    ['verify-modal','ban-modal','reset-modal','balance-modal'].forEach(function (id) {
        var el = document.getElementById(id);
        el.addEventListener('click', function (e) { if (e.target === el) closeModal(id); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') ['verify-modal','ban-modal','reset-modal','balance-modal'].forEach(closeModal);
    });

    // ── Submit shared form ────────────────────────────────────
    function submitForm(action, uid, extra) {
        document.getElementById('sf-action').value  = action;
        document.getElementById('sf-user-id').value = uid;
        // Extra fields
        document.getElementById('sf-ban-reason').value   = (extra && extra.banReason)   || '';
        document.getElementById('sf-new-password').value = (extra && extra.newPassword) || '';
        document.getElementById('sf-adj-type').value     = (extra && extra.adjType)     || '';
        document.getElementById('sf-amount').value       = (extra && extra.amount)      || '';
        document.getElementById('sf-adj-reason').value   = (extra && extra.adjReason)   || '';
        document.getElementById('shared-action-form').submit();
    }

    // ── Toast (no eval, no setTimeout string) ────────────────
    function showToast(msg) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--surface);' +
            'border:1px solid var(--accent);color:var(--accent);padding:12px 20px;' +
            'border-radius:10px;font-size:13px;font-weight:600;z-index:99999;';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3000); // setTimeout with function ref, NOT a string — CSP safe
    }

    // ── Password generator ────────────────────────────────────
    function genPasswordValue() {
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
        var pw = '';
        for (var i = 0; i < 10; i++) pw += chars[Math.floor(Math.random() * chars.length)];
        return pw;
    }

    document.getElementById('gen-password-btn').addEventListener('click', function () {
        var pw  = genPasswordValue();
        var inp = document.getElementById('new-password-inp');
        inp.value = pw;
        inp.select();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(pw).then(function () { showToast('✅ Password copied!'); });
        } else {
            showToast('✅ Password generated!');
        }
    });

    // ── Balance type toggle ───────────────────────────────────
    document.getElementById('bal-add-label').addEventListener('click', function () {
        document.getElementById('bal-type-add').checked = true;
        document.getElementById('bal-add-label').style.borderColor    = 'var(--success)';
        document.getElementById('bal-add-label').style.background     = 'rgba(16,185,129,0.08)';
        document.getElementById('bal-deduct-label').style.borderColor = 'var(--border)';
        document.getElementById('bal-deduct-label').style.background  = 'transparent';
    });
    document.getElementById('bal-deduct-label').addEventListener('click', function () {
        document.getElementById('bal-type-deduct').checked = true;
        document.getElementById('bal-deduct-label').style.borderColor = 'var(--danger)';
        document.getElementById('bal-deduct-label').style.background  = 'rgba(239,68,68,0.08)';
        document.getElementById('bal-add-label').style.borderColor    = 'var(--border)';
        document.getElementById('bal-add-label').style.background     = 'transparent';
    });

    // ── Delegate all action buttons ───────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;

        var action   = btn.dataset.action;
        var uid      = btn.dataset.uid;
        var name     = btn.dataset.name     || '';
        var username = btn.dataset.username || '';
        var balance  = btn.dataset.balance  || '0';

        if (action === 'verify') {
            document.getElementById('verify-modal-name').textContent = 'Player: ' + name;
            document.getElementById('verify-confirm-btn').onclick = function () {
                submitForm('verify', uid);
            };
            openModal('verify-modal');

        } else if (action === 'ban') {
            document.getElementById('ban-modal-name').textContent = 'Banning: @' + name;
            document.getElementById('ban-confirm-btn').onclick = function () {
                var reason = document.getElementById('ban-reason-input').value.trim() || 'Violation of court rules.';
                submitForm('ban', uid, { banReason: reason });
            };
            openModal('ban-modal');

        } else if (action === 'unban') {
            if (!window.confirm('Unban @' + name + '?')) return;
            submitForm('unban', uid);

        } else if (action === 'reset') {
            document.getElementById('reset-modal-name').textContent = 'Player: @' + username;
            document.getElementById('new-password-inp').value = '';
            document.getElementById('reset-confirm-btn').onclick = function () {
                var pw = document.getElementById('new-password-inp').value.trim();
                if (pw.length < 6) { showToast('⚠️ Password must be at least 6 characters.'); return; }
                submitForm('reset_password', uid, { newPassword: pw });
            };
            openModal('reset-modal');

        } else if (action === 'balance') {
            document.getElementById('balance-modal-name').textContent = name;
            document.getElementById('balance-modal-current').textContent = '₱' + balance;
            document.getElementById('balance-amount-inp').value  = '';
            document.getElementById('balance-reason-inp').value  = '';
            // Reset to "add" state
            document.getElementById('bal-type-add').checked = true;
            document.getElementById('bal-add-label').style.borderColor    = 'var(--success)';
            document.getElementById('bal-add-label').style.background     = 'rgba(16,185,129,0.08)';
            document.getElementById('bal-deduct-label').style.borderColor = 'var(--border)';
            document.getElementById('bal-deduct-label').style.background  = 'transparent';
            document.getElementById('balance-confirm-btn').onclick = function () {
                var amt    = parseFloat(document.getElementById('balance-amount-inp').value);
                var type   = document.getElementById('bal-type-deduct').checked ? 'deduct' : 'add';
                var reason = document.getElementById('balance-reason-inp').value.trim() || 'Admin adjustment';
                if (!amt || amt <= 0) { showToast('⚠️ Enter a valid amount.'); return; }
                submitForm('adjust_balance', uid, { adjType: type, amount: amt, adjReason: reason });
            };
            openModal('balance-modal');
        }
    });

    // ── Expose closeModal globally for inline onclick on Cancel buttons ──
    window.closeModal = closeModal;
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>