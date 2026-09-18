<?php
// ============================================================
//  FILE: player/scan_topup_qr.php
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db    = getDB();
$uid   = $_SESSION['user_id'];
$token = trim($_GET['token'] ?? '');

$result = ['status' => 'error', 'message' => 'No token provided.'];

if ($token) {
    $token = preg_replace('/^TOPUP:/i', '', $token);
    try {
        $db->beginTransaction();
        $qrStmt = $db->prepare("SELECT tq.*, u.username, u.full_name FROM falcon.topup_qr_codes tq JOIN falcon.users u ON u.id = tq.user_id WHERE tq.token = ? FOR UPDATE");
        $qrStmt->execute([$token]);
        $qr = $qrStmt->fetch();

        if (!$qr) {
            $result = ['status' => 'error', 'message' => 'Invalid QR code. Token not found.']; $db->rollBack();
        } elseif ($qr['is_used']) {
            $result = ['status' => 'error', 'message' => 'This QR code has already been used.']; $db->rollBack();
        } elseif (strtotime($qr['expires_at']) < time()) {
            $result = ['status' => 'error', 'message' => 'This QR code has expired. Please ask the court owner for a new one.']; $db->rollBack();
        } elseif ($qr['user_id'] != $uid) {
            $result = ['status' => 'error', 'message' => 'This QR code does not belong to your account.']; $db->rollBack();
        } else {
            $amount = $qr['amount'];
            $db->prepare("UPDATE falcon.topup_qr_codes SET is_used = TRUE, used_at = NOW() WHERE id = ?")->execute([$qr['id']]);
            $db->prepare("INSERT INTO falcon.wallets (user_id, balance) VALUES (?, ?) ON CONFLICT (user_id) DO UPDATE SET balance = falcon.wallets.balance + EXCLUDED.balance, updated_at = NOW()")->execute([$uid, $amount]);
            $db->prepare("INSERT INTO falcon.transactions (user_id, type, amount, description, reference_id) VALUES (?, 'topup', ?, ?, ?)")->execute([$uid, $amount, 'In-person top-up (QR)', $qr['id']]);
            $passCheck = $db->prepare("SELECT id FROM falcon.player_passes WHERE user_id = ? AND is_active = TRUE AND expires_at > NOW()");
            $passCheck->execute([$uid]);
            if (!$passCheck->fetch()) {
                $db->prepare("INSERT INTO falcon.player_passes (user_id, qr_token, expires_at) VALUES (?, ?, NOW() + INTERVAL '8 hours')")
                   ->execute([$uid, bin2hex(random_bytes(24))]);
            }
            $db->prepare("INSERT INTO falcon.notifications (user_id, title, message, type) VALUES (?, '₱' || ? || ' Credits Added 💰', ?, 'success')")->execute([$uid, $amount, "₱{$amount} was added to your account via in-person top-up."]);
            $db->commit();
            $balStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
            $balStmt->execute([$uid]);
            $result = ['status' => 'success', 'message' => 'Credits added successfully!', 'amount' => $amount, 'new_balance' => $balStmt->fetchColumn()];
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('scan_topup_qr error: ' . $e->getMessage());
        $result = ['status' => 'error', 'message' => 'A database error occurred. Please try again.'];
    }
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$pageTitle = 'Top-Up Result';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.topup-result-wrap {
    max-width: 480px;
    margin: 40px auto 0;
    text-align: center;
    padding: 0 8px;
}
.result-card {
    border-radius: 16px;
    padding: clamp(28px, 6vw, 48px) clamp(16px, 5vw, 32px);
    border: 2px solid var(--border);
}
.result-card.success { border-color: var(--accent); background: rgba(0,229,160,0.05); }
.result-card.error   { border-color: var(--danger);  background: rgba(239,68,68,0.05); }

.result-icon  { font-size: clamp(40px, 12vw, 64px); margin-bottom: 12px; }
.result-title { font-family: 'Bebas Neue', sans-serif; font-size: clamp(22px, 6vw, 32px); margin-bottom: 8px; }
.result-amount {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(36px, 12vw, 56px);
    color: var(--accent);
    margin: 12px 0;
}
.result-sub { font-size: 14px; color: var(--muted); line-height: 1.6; }
.result-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}
.result-actions a { flex: 1; min-width: 120px; max-width: 200px; text-align: center; touch-action: manipulation; }
</style>

<div class="topup-result-wrap">

    <?php if ($result['status'] === 'success'): ?>
        <div class="result-card success">
            <div class="result-icon">💰</div>
            <div class="result-title">Credits Added!</div>
            <div class="result-amount">+₱<?= number_format($result['amount'], 2) ?></div>
            <div class="result-sub">
                New balance: <strong style="color:var(--accent);">₱<?= number_format($result['new_balance'], 2) ?></strong><br/>
                Your court pass has been updated. You're ready to play!
            </div>
        </div>
        <div class="result-actions">
            <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-primary">Go to Dashboard →</a>
        </div>

    <?php else: ?>
        <div class="result-card error">
            <div class="result-icon">❌</div>
            <div class="result-title">Top-Up Failed</div>
            <div class="result-sub"><?= clean($result['message']) ?></div>
        </div>
        <div class="result-actions">
            <a href="<?= APP_URL ?>/player/topup.php"     class="btn-outline">← Try Again</a>
            <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-primary">Dashboard</a>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>