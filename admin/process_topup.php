<?php
// ============================================================
//  FILE: admin/process_topup.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/email_helpers.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/topup_requests.php');
}

verifyCsrf();

$db        = getDB();
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT,
                          ['options' => ['min_range' => 1]]);
$action    = $_POST['action'] ?? '';
$note      = sanitizeString($_POST['review_note'] ?? '', 500);

if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
    setFlash('error', 'Invalid parameters.');
    redirect('admin/topup_requests.php');
}

// Fetch the request
$reqStmt = $db->prepare("
    SELECT tr.*, u.username, u.full_name
      FROM falcon.topup_requests tr
      JOIN falcon.users u ON u.id = tr.user_id
     WHERE tr.id = ?
");
$reqStmt->execute([$requestId]);
$req = $reqStmt->fetch();

if (!$req) {
    setFlash('error', 'Top-up request not found.');
    redirect('admin/topup_requests.php');
}

if ($req['status'] !== 'pending') {
    setFlash('warn', 'This request has already been processed.');
    redirect('admin/topup_requests.php');
}

try {
    $db->beginTransaction();

    if ($action === 'approve') {

        // -- Re-fetch + lock the request row
        $lockStmt = $db->prepare("
            SELECT id, status FROM falcon.topup_requests WHERE id = ? FOR UPDATE
        ");
        $lockStmt->execute([$requestId]);
        $lockedReq = $lockStmt->fetch();

        if (!$lockedReq || $lockedReq['status'] !== 'pending') {
            $db->rollBack();
            setFlash('warn', 'Request was already processed by another admin.');
            redirect('admin/topup_requests.php');
        }

        // -- Duplicate reference_no guard
        if (!empty($req['gcash_ref_no'])) {
            $dupStmt = $db->prepare("
                SELECT COUNT(*) FROM falcon.topup_requests
                 WHERE gcash_ref_no = ?
                   AND status       = 'approved'
                   AND id           != ?
            ");
            $dupStmt->execute([$req['gcash_ref_no'], $requestId]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $db->rollBack();
                setFlash('error', 'This reference number was already approved on a different request.');
                redirect('admin/topup_requests.php');
            }
        }

        // -- Lock + read wallet balance
        $walStmt = $db->prepare("
            SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE
        ");
        $walStmt->execute([$req['user_id']]);
        $balanceBefore = (float)($walStmt->fetchColumn() ?? 0.0);
        $balanceAfter  = $balanceBefore + (float)$req['amount'];

        // -- Mark request approved
        $db->prepare("
            UPDATE falcon.topup_requests
               SET status      = 'approved',
                   reviewed_at = NOW(),
                   reviewed_by = ?,
                   review_note = ?
             WHERE id = ?
        ")->execute([$_SESSION['user_id'], $note ?: null, $requestId]);

        // -- Credit wallet
        $db->prepare("
            INSERT INTO falcon.wallets (user_id, balance, updated_at)
            VALUES (?, ?, NOW())
            ON CONFLICT (user_id) DO UPDATE
               SET balance    = EXCLUDED.balance,
                   updated_at = NOW()
        ")->execute([$req['user_id'], $balanceAfter]);

        // -- Transaction audit record (FIXED: correct columns for a topup)
        $db->prepare("
            INSERT INTO falcon.transactions
                (user_id, type, method, amount, note, status,
                 balance_before, balance_after, reference_number, processed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $req['user_id'],
            'topup',
            $req['method'] ?? 'gcash',
            (float)$req['amount'],
            'Top-up approved — ref: ' . ($req['gcash_ref_no'] ?? 'N/A'),
            'approved',
            $balanceBefore,
            $balanceAfter,
            $req['gcash_ref_no'] ?? null,
            $_SESSION['user_id'],
        ]);

        // -- Activate / create player pass (no updated_at column on this table)
        $passCheck = $db->prepare("SELECT id FROM falcon.player_passes WHERE user_id = ?");
        $passCheck->execute([$req['user_id']]);

        if ($passCheck->fetch()) {
            // Row exists — update it
            $db->prepare("
                UPDATE falcon.player_passes
                   SET is_active  = :active,
                       expires_at = CASE
                           WHEN expires_at < NOW()
                           THEN '2099-12-31 23:59:59+00'::timestamptz
                           ELSE expires_at
                       END
                 WHERE user_id = :uid
            ")->execute([
                ':active' => $balanceAfter > 0 ? 't' : 'f',
                ':uid'    => $req['user_id'],
            ]);
        } else {
            // No row yet — insert one
            $db->prepare("
                INSERT INTO falcon.player_passes
                    (user_id, qr_token, expires_at, is_active)
                VALUES (?, encode(gen_random_bytes(24), 'hex'), '2099-12-31 23:59:59+00', TRUE)
                ON CONFLICT (user_id) DO UPDATE
                   SET is_active  = TRUE,
                       expires_at = CASE
                           WHEN falcon.player_passes.expires_at < NOW()
                           THEN '2099-12-31 23:59:59+00'::timestamptz
                           ELSE falcon.player_passes.expires_at
                       END
            ")->execute([$req['user_id']]);
        }

        // -- Notify player (FIXED: 4 explicit ? placeholders so type is never NULL)
        $db->prepare("
            INSERT INTO falcon.notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $req['user_id'],
            'Top-Up Approved!',
            'PHP ' . number_format($req['amount'], 2) . ' added to your account. '
                . 'New balance: PHP ' . number_format($balanceAfter, 2) . '. '
                . 'Your QR pass is now active - scan to play!',
            'success',
        ]);

        $db->commit();

        // Send email notification
        sendTopupNotification($requestId);

        // Audit log (outside transaction — non-blocking)
        auditLog(
            $db,
            'topup_approved',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'topup_requests',
            $requestId,
            ['status' => 'pending', 'amount' => $req['amount']],
            ['status' => 'approved', 'balance_after' => $balanceAfter],
            'success',
            'Approved by ' . ($_SESSION['username'] ?? 'admin')
        );

        setFlash('success',
            'Approved! PHP ' . number_format($req['amount'], 2)
            . ' credited to @' . clean($req['username']) . '. QR pass activated.'
        );

    } else {
        // -- REJECT
        if (empty($note)) {
            $note = 'Payment could not be verified. Please re-submit with a clearer screenshot.';
        }

        $db->prepare("
            UPDATE falcon.topup_requests
               SET status      = 'rejected',
                   reviewed_at = NOW(),
                   reviewed_by = ?,
                   review_note = ?
             WHERE id = ?
        ")->execute([$_SESSION['user_id'], $note, $requestId]);

        // -- Notify player (FIXED: 4 explicit ? placeholders so type is never NULL)
        $db->prepare("
            INSERT INTO falcon.notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $req['user_id'],
            'Top-Up Request Rejected',
            'Your PHP ' . number_format($req['amount'], 2) . ' top-up request was rejected. '
                . 'Reason: ' . $note . ' - please re-submit with a clear screenshot.',
            'warn',
        ]);

        $db->commit();

        // Send email notification
        sendTopupNotification($requestId);

        auditLog(
            $db,
            'topup_rejected',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'topup_requests',
            $requestId,
            ['status' => 'pending'],
            ['status' => 'rejected', 'note' => $note],
            'success',
            'Rejected by ' . ($_SESSION['username'] ?? 'admin')
        );

        setFlash('warn',
            'Top-up from @' . clean($req['username'])
            . ' (PHP ' . number_format($req['amount'], 2) . ') rejected.'
        );
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[process_topup] ' . $e->getMessage());
    setFlash('error', IS_PRODUCTION
        ? 'Something went wrong while processing this request. Please try again.'
        : 'DB Error: ' . $e->getMessage());
}

redirect('admin/topup_requests.php');