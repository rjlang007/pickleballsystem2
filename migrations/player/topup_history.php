<?php
// ============================================================
//  FILE: player/topup_history.php — FIXED
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

$wallet = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$wallet->execute([$uid]);
$balance = $wallet->fetchColumn() ?? 0;

$reqStmt = $db->prepare("
    SELECT id, amount, method, gcash_ref_no, screenshot_path,
           status, created_at, reviewed_at, review_note
    FROM falcon.topup_requests
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$reqStmt->execute([$uid]);
$requests = $reqStmt->fetchAll();

$txStmt = $db->prepare("
    SELECT type, amount, created_at
    FROM falcon.transactions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$txStmt->execute([$uid]);
$transactions = $txStmt->fetchAll();

$totalIn = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM falcon.transactions WHERE user_id = ? AND type = 'topup'");
$totalIn->execute([$uid]);
$creditIn = $totalIn->fetchColumn();

$totalOut = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM falcon.transactions WHERE user_id = ? AND type = 'deduction'");
$totalOut->execute([$uid]);
$creditOut = $totalOut->fetchColumn();

$pageTitle = 'Credit History';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= csrfNonce() ?>">
.tx-credit { color: var(--success); font-weight: 700; white-space: nowrap; }
.tx-debit  { color: var(--danger);  font-weight: 700; white-space: nowrap; }
.stat-val-success { color: var(--success); }
.stat-val-danger  { color: var(--danger); }
.topup-date { font-size: 12px; white-space: nowrap; }
.topup-date-sub { color: var(--muted); }
.topup-amount { color: var(--accent); font-weight: 700; white-space: nowrap; }
</style>

<div class="page-header flex-between">
    <div>
        <h1>Credit History</h1>
        <p>All your top-up requests and credit transactions.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/player/topup.php"     class="btn-primary btn-sm">+ Load Credits</a>
        <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- Summary -->
<div class="dashboard-grid stats-3col mb-3">
    <div class="stat-card">
        <div class="stat-val">₱<?= number_format($balance, 2) ?></div>
        <div class="stat-label">Current Balance</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-success">₱<?= number_format($creditIn, 2) ?></div>
        <div class="stat-label">Total Loaded</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-danger">₱<?= number_format($creditOut, 2) ?></div>
        <div class="stat-label">Total Spent</div>
    </div>
</div>

<!-- Top-Up Requests -->
<div class="card mb-3">
    <div class="card-title mb-1">📱 GCash Top-Up Requests</div>
    <div class="card-subtitle">Track the status of your manual top-up submissions</div>
    <hr class="divider"/>

    <?php if (empty($requests)): ?>
        <p class="text-muted text-center" style="padding:24px;">No top-up requests submitted yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th class="col-hide-sm">Method</th>
                        <th class="col-hide-sm">GCash Ref</th>
                        <th class="col-hide-xs">Screenshot</th>
                        <th>Status</th>
                        <th class="col-hide-sm">Reviewed</th>
                        <th class="col-hide-xs">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r):
                        $badge = match($r['status']) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default    => 'warn'
                        };
                    ?>
                        <tr>
                            <td class="topup-date">
                                <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                                <span class="topup-date-sub"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                            </td>
                            <td class="topup-amount">₱<?= number_format($r['amount'], 2) ?></td>
                            <td class="col-hide-sm">
                                <span class="badge badge-muted"><?= ucfirst($r['method'] ?? 'gcash') ?></span>
                            </td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:12px;">
                                <?= $r['gcash_ref_no'] ? clean($r['gcash_ref_no']) : '—' ?>
                            </td>
                            <td class="col-hide-xs">
                                <?php if ($r['screenshot_path']): ?>
                                    <a href="<?= APP_URL ?>/uploads/screenshots/<?= urlencode($r['screenshot_path']) ?>"
                                       target="_blank" class="btn-outline btn-sm">View</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td class="col-hide-sm topup-date-sub" style="font-size:12px;white-space:nowrap;">
                                <?= $r['reviewed_at'] ? date('M d, h:i A', strtotime($r['reviewed_at'])) : '—' ?>
                            </td>
                            <td class="col-hide-xs text-muted" style="font-size:12px;">
                                <?= $r['review_note'] ? clean($r['review_note']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Transaction Ledger -->
<div class="card">
    <div class="card-title mb-1">💳 Transaction Ledger</div>
    <div class="card-subtitle">Last 50 credit movements on your account</div>
    <hr class="divider"/>

    <?php if (empty($transactions)): ?>
        <p class="text-muted text-center" style="padding:24px;">No transactions yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t):
                        $isCredit = in_array($t['type'], ['topup','refund']);
                    ?>
                        <tr>
                            <td class="topup-date">
                                <?= date('M d, Y', strtotime($t['created_at'])) ?><br>
                                <span class="topup-date-sub"><?= date('h:i A', strtotime($t['created_at'])) ?></span>
                            </td>
                            <td>
                                <?php if ($t['type'] === 'topup'): ?>
                                    <span class="badge badge-success">Top-Up</span>
                                <?php elseif ($t['type'] === 'deduction'): ?>
                                    <span class="badge badge-danger">Game Fee</span>
                                <?php elseif ($t['type'] === 'refund'): ?>
                                    <span class="badge badge-info">Refund</span>
                                <?php else: ?>
                                    <span class="badge badge-muted"><?= clean($t['type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= $isCredit ? 'tx-credit' : 'tx-debit' ?>">
                                <?= $isCredit ? '+' : '-' ?>₱<?= number_format($t['amount'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>