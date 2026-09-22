<?php
// ============================================================
//  FILE: api/wallet.php
//
//  GET  ?balance=1                    → player balance
//  GET  ?transactions=1               → player transaction history
//  GET  ?withdrawals=1                → admin: all withdrawal requests
//  POST ?action=withdraw              → player: submit withdrawal request
//  PATCH body {id, status, admin_note}→ admin: approve or reject withdrawal
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_wallet_' . getClientIp();
if (!checkRateLimit($rlKey, 30, 60)) {
    header('Retry-After: 60');
    apiError('RATE_LIMITED', 'Too many requests. Please wait.', [], 429);
}

if (!isLoggedIn()) {
    apiError('UNAUTHORIZED', 'Not logged in.', [], 401);
}
verifySameOrigin();

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────
//  GET
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET ?balance=1
    if (isset($_GET['balance'])) {
        $stmt = $db->prepare(
            "SELECT COALESCE(balance, 0) AS balance FROM falcon.wallets WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        apiSuccess(['data' => ['balance' => (float)$stmt->fetchColumn()]]);
    }

    // GET ?transactions=1
    if (isset($_GET['transactions'])) {
        $limit  = min(100, max(1, (int)($_GET['limit']  ?? 20)));
        $offset = max(0,          (int)($_GET['offset'] ?? 0));

        $stmt = $db->prepare(
            "SELECT id, type, amount, note, balance_before, balance_after, created_at
               FROM falcon.transactions
              WHERE user_id = ?
              ORDER BY created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([$uid, $limit, $offset]);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // GET ?withdrawals=1  (admin only)
    if (isset($_GET['withdrawals'])) {
        if (!isAdmin()) {
            apiError('FORBIDDEN', 'Admin access required.', [], 403);
        }

        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $limit        = min(100, max(1, (int)($_GET['limit']  ?? 20)));
        $offset       = max(0,          (int)($_GET['offset'] ?? 0));

        $where  = ['1=1'];
        $params = [];

        if ($statusFilter !== '') {
            $where[]  = 'wr.status = ?';
            $params[] = $statusFilter;
        }

        $whereClause = implode(' AND ', $where);
        $params[]    = $limit;
        $params[]    = $offset;

        $stmt = $db->prepare(
            "SELECT wr.id, wr.user_id, u.username, u.full_name,
                    wr.amount, wr.bank_name, wr.account_number, wr.account_name,
                    wr.status, wr.admin_note, wr.processed_by, wr.processed_at, wr.created_at
               FROM falcon.withdrawal_requests wr
               JOIN falcon.users u ON u.id = wr.user_id
              WHERE {$whereClause}
              ORDER BY wr.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    apiError('INVALID_REQUEST', 'Invalid GET request.');
}

// ─────────────────────────────────────────────────────────────
//  POST — player submits a withdrawal request
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $action = trim((string)($_GET['action'] ?? ''));
    if ($action !== 'withdraw') {
        apiError('INVALID_REQUEST', 'Invalid action.');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $balanceStmt = $db->prepare(
        "SELECT COALESCE(balance, 0) AS balance FROM falcon.wallets WHERE user_id = ? LIMIT 1"
    );
    $balanceStmt->execute([$uid]);
    $balance = (float)$balanceStmt->fetchColumn();

    $errors = validateWithdrawal($body, $balance);
    if (!empty($errors)) {
        apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);
    }

    // Only one pending request at a time
    $pendingStmt = $db->prepare(
        "SELECT 1 FROM falcon.withdrawal_requests
          WHERE user_id = ? AND status = 'pending' LIMIT 1"
    );
    $pendingStmt->execute([$uid]);
    if ($pendingStmt->fetch()) {
        apiError('CONFLICT', 'You already have a pending withdrawal request.', [], 409);
    }

    // RETURNING id — lastInsertId() does not work in PostgreSQL
    $insert = $db->prepare(
        "INSERT INTO falcon.withdrawal_requests
            (user_id, amount, bank_name, account_number, account_name, status, created_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NOW())
         RETURNING id"
    );
    $insert->execute([
        $uid,
        number_format((float)$body['amount'], 2, '.', ''),
        trim($body['bank_name']),
        trim($body['account_number']),
        trim($body['account_name']),
    ]);
    $requestId = (int)$insert->fetchColumn();

    // Notify all admins
    notifyAdmins($db, 'New Withdrawal Request', 'A player submitted a withdrawal request.');

    apiSuccess(['data' => ['request_id' => $requestId]]);
}

// ─────────────────────────────────────────────────────────────
//  PATCH — admin approves or rejects a withdrawal
//
//  Body: { "id": 123, "status": "approved"|"rejected", "admin_note": "..." }
//
//  On approved:
//   • Deduct amount from player wallet (atomic: fails if insufficient)
//   • Log a withdrawal_debit transaction
//   • Notify player: approved
//
//  On rejected:
//   • Just update status + admin_note
//   • Notify player: rejected
// ─────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    if (!isAdmin()) {
        apiError('FORBIDDEN', 'Admin access required.', [], 403);
    }

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id        = isset($body['id'])     ? (int)$body['id']                  : 0;
    $newStatus = isset($body['status']) ? trim((string)$body['status'])      : '';
    $adminNote = trim((string)($body['admin_note'] ?? ''));

    if ($id <= 0 || !in_array($newStatus, ['approved', 'rejected'], true)) {
        apiError('VALIDATION_ERROR',
            'Request ID is required and status must be "approved" or "rejected".', [], 422);
    }

    // Fetch the withdrawal request
    $reqStmt = $db->prepare(
        "SELECT wr.*, u.full_name AS player_name
           FROM falcon.withdrawal_requests wr
           JOIN falcon.users u ON u.id = wr.user_id
          WHERE wr.id = ?
          LIMIT 1"
    );
    $reqStmt->execute([$id]);
    $req = $reqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        apiError('NOT_FOUND', 'Withdrawal request not found.', [], 404);
    }
    if ($req['status'] !== 'pending') {
        apiError('CONFLICT',
            "This request has already been {$req['status']}. Only pending requests can be updated.", [], 409);
    }

    $playerId = (int)$req['user_id'];
    $amount   = (float)$req['amount'];
    $adminId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $db->beginTransaction();
    try {
        if ($newStatus === 'approved') {
            // Lock the wallet row and verify balance
            $walletStmt = $db->prepare(
                "SELECT COALESCE(balance, 0) AS balance
                   FROM falcon.wallets
                  WHERE user_id = ?
                  FOR UPDATE"
            );
            $walletStmt->execute([$playerId]);
            $walletRow     = $walletStmt->fetch(PDO::FETCH_ASSOC);
            $balanceBefore = $walletRow ? (float)$walletRow['balance'] : 0.0;

            if ($balanceBefore < $amount) {
                $db->rollBack();
                apiError('INSUFFICIENT_FUNDS',
                    "Player only has ₱" . number_format($balanceBefore, 2) .
                    " but the request is for ₱" . number_format($amount, 2) . ".", [], 402);
            }

            // Deduct from wallet (atomic guard on balance)
            $deductStmt = $db->prepare(
                "UPDATE falcon.wallets
                    SET balance    = balance - :amt,
                        updated_at = NOW()
                  WHERE user_id    = :uid
                    AND balance    >= :amt"
            );
            $deductStmt->execute([':amt' => $amount, ':uid' => $playerId]);
            if ($deductStmt->rowCount() !== 1) {
                throw new RuntimeException('Wallet deduction failed — concurrent modification suspected.');
            }

            $balanceAfter = $balanceBefore - $amount;

            // Log the transaction
            $db->prepare(
    "INSERT INTO falcon.transactions
        (user_id, type, amount, reason,
         related_table, related_id,
         balance_before, balance_after)
     VALUES
        (:uid, 'deduction', :amt, :note,
         'withdrawal_requests', :rid,
         :before, :after)"
)->execute([
    ':uid'    => $playerId,
    ':amt'    => $amount,
    ':note'   => 'Approved withdrawal to ' . $req['bank_name'],
    ':rid'    => $id,
    ':before' => $balanceBefore,
    ':after'  => $balanceAfter,
]);

            // Notify player: approved
            notifyUser(
                $db,
                $playerId,
                'Withdrawal Approved',
                "Your withdrawal of ₱" . number_format($amount, 2) .
                " to {$req['bank_name']} has been approved and processed."
            );

        } else {
            // Rejected — no wallet change, just notify player
            notifyUser(
                $db,
                $playerId,
                'Withdrawal Rejected',
                "Your withdrawal request of ₱" . number_format($amount, 2) .
                " was rejected." . ($adminNote !== '' ? " Reason: {$adminNote}" : '')
            );
        }

        // Update the withdrawal request
        $db->prepare(
            "UPDATE falcon.withdrawal_requests
                SET status       = :status,
                    admin_note   = :note,
                    processed_by = :admin,
                    processed_at = NOW()
              WHERE id           = :id"
        )->execute([
            ':status' => $newStatus,
            ':note'   => $adminNote !== '' ? $adminNote : null,
            ':admin'  => $adminId,
            ':id'     => $id,
        ]);

        // Audit log
        auditLog(
            $db,
            "withdrawal_{$newStatus}",
            $adminId,
            'withdrawal_requests',
            $id,
            ['status' => 'pending'],
            ['status' => $newStatus, 'admin_note' => $adminNote],
            'success',
            "₱" . number_format($amount, 2) . " for user_id={$playerId}"
        );

        $db->commit();

        apiSuccess([
            'data' => [
                'withdrawal_id' => $id,
                'status'        => $newStatus,
                'player_id'     => $playerId,
                'amount'        => $amount,
            ],
        ]);

    } catch (RuntimeException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[wallet PATCH] ' . $e->getMessage());
        apiError('SERVER_ERROR', $e->getMessage(), [], 500);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[wallet PATCH] ' . $e->getMessage());
        apiError('SERVER_ERROR', 'Failed to process withdrawal.', [], 500);
    }
}

apiError('INVALID_REQUEST', 'Invalid request method.');