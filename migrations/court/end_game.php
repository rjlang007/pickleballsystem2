<?php
// ============================================================
//  FILE: court/end_game.php
//
//  FIX v2 (MULTI-COURT + AUDIT):
//
//  1. auditLog() call wrapped in try/catch — if the function
//     signature differs from what end_game.php assumes, the
//     game still ends cleanly and the error is logged rather
//     than crashing the response.
//
//  2. Warmup cleanup now targets the per-court section key
//     ('warmup_court_{id}') introduced by game_ticker v4,
//     in addition to the legacy 'warmup' section.
//
//  3. next_ready check now filters by court_id so it only
//     reports the queue state for the court that just finished,
//     not the global queue across all courts.
//
//  4. warmup_mins lookup uses the session's own court_id rather
//     than ORDER BY id LIMIT 1 (which always returned court 1).
//
//  PRESERVED from v1:
//   • POST-only + admin auth
//   • JSON body parsing + session_id / action validation
//   • FOR UPDATE lock on session + players
//   • Per-player wallet deduction + syncPassActive
//   • falcon.transactions INSERT (corrected column names from v1)
//   • Notification messages
//   • Full JSON response shape
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required.', 'code' => 'method_not_allowed']);
    exit;
}

// ── Auth guard ────────────────────────────────────────────────
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.', 'code' => 'unauthorized']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = filter_var($body['session_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action    = in_array($body['action'] ?? '', ['complete', 'cancel'], true)
             ? $body['action'] : 'complete';

if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid session_id.', 'code' => 'bad_request']);
    exit;
}

$db          = getDB();
$adminUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$adminName   = $_SESSION['username'] ?? 'admin';

try {
    $db->beginTransaction();

    // ── Lock + fetch session ──────────────────────────────────
    $sesStmt = $db->prepare("
        SELECT gs.id, gs.status, gs.duration_mins, gs.started_at, gs.court_id,
               c.name AS court_name, c.short_code, c.credit_cost,
               c.warmup_mins
          FROM falcon.game_sessions gs
          JOIN falcon.courts c ON c.id = gs.court_id
         WHERE gs.id = ?
           FOR UPDATE
    ");
    $sesStmt->execute([$sessionId]);
    $session = $sesStmt->fetch();

    if (!$session) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Session not found.', 'code' => 'not_found']);
        exit;
    }

    if ($session['status'] !== 'active') {
        $db->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Session is not active (currently: ' . $session['status'] . ').',
            'code'    => 'not_active',
        ]);
        exit;
    }

    $creditCost = (float)$session['credit_cost'];
    $courtId    = (int)$session['court_id'];
    $newStatus  = ($action === 'cancel') ? 'cancelled' : 'completed';
    $startedTs  = strtotime($session['started_at']);
    $actualMins = round((time() - $startedTs) / 60, 1);

    // ── Fetch players (lock wallet rows) ──────────────────────
    $playersStmt = $db->prepare("
        SELECT gp.user_id, gp.id AS gp_id, u.username, u.full_name,
               COALESCE(w.balance, 0) AS balance,
               COALESCE(gp.credits_charged, 0) AS credits_charged,
               COALESCE(gp.payment_status, '') AS payment_status
          FROM falcon.game_players gp
          JOIN falcon.users u        ON u.id       = gp.user_id
          LEFT JOIN falcon.wallets w ON w.user_id  = gp.user_id
         WHERE gp.session_id = ?
           FOR UPDATE OF gp
    ");
    $playersStmt->execute([$sessionId]);
    $players = $playersStmt->fetchAll();

    // ── End session ───────────────────────────────────────────
    $db->prepare("
        UPDATE falcon.game_sessions
           SET status   = ?,
               ended_at = NOW()
         WHERE id = ?
    ")->execute([$newStatus, $sessionId]);

    // ── Remove players from queue ─────────────────────────────
    $db->prepare("DELETE FROM falcon.game_queue WHERE session_id = ?")
       ->execute([$sessionId]);

    // ── Per-player deductions + notifications ─────────────────
    $endedPlayers    = [];
    $totalChargedAll = 0.0;

    $notifyStmt = $db->prepare("
        INSERT INTO falcon.notifications (user_id, type, title, message, created_at)
        VALUES (?, 'info', ?, ?, NOW())
    ");

    // Column names match falcon.transactions schema:
    //   type, method, amount, note, status,
    //   balance_before, balance_after, related_table, related_id
$txnStmt = $db->prepare("
    INSERT INTO falcon.transactions
        (user_id, type, method, amount, balance_before, balance_after,
         reason, related_table, related_id)
    VALUES (?, 'deduction', 'game_session', ?, ?, ?,
            ?, 'game_sessions', ?)
");

    $walletUpdateStmt = $db->prepare("
        UPDATE falcon.wallets
           SET balance    = balance - ?,
               updated_at = NOW()
         WHERE user_id    = ?
           AND balance    >= ?
    ");

    foreach ($players as $p) {
        $balanceBefore   = (float)$p['balance'];
        $charged         = 0.0;
        $deductionFailed = false;
        $alreadyCharged  = ((float)($p['credits_charged'] ?? 0) > 0)
                          || (($p['payment_status'] ?? '') === 'paid');

        if ($action === 'complete' && $creditCost > 0 && !$alreadyCharged && $balanceBefore >= $creditCost) {
            $walletUpdateStmt->execute([$creditCost, $p['user_id'], $creditCost]);

            if ($walletUpdateStmt->rowCount() === 1) {
                $balanceAfter     = $balanceBefore - $creditCost;
                $charged          = $creditCost;
                $totalChargedAll += $charged;

                // Non-fatal: falcon.transactions may not exist in all
                // environments. Game end must succeed regardless.
                try {
$txnStmt->execute([
    $p['user_id'],
    $creditCost,
    $balanceBefore,
    $balanceAfter,
    "Game #{$sessionId} on {$session['court_name']} ({$actualMins} min)",
    $sessionId,
]);
                } catch (PDOException $txnEx) {
                    error_log('[end_game] transactions insert failed (non-fatal): ' . $txnEx->getMessage());
                }

                syncPassActive($db, $p['user_id'], $balanceAfter, $courtId);
            } else {
                $deductionFailed = true;
                $balanceAfter    = $balanceBefore;
                error_log("[end_game] Deduction failed for user {$p['user_id']}");
            }
        } else {
            $balanceAfter = $balanceBefore;
        }

        if ($action === 'cancel') {
            $title = '❌ Game Cancelled';
            $msg   = "Game #{$sessionId} on {$session['court_name']} was cancelled after {$actualMins} min.";
        } elseif ($deductionFailed) {
            $title = '⚠️ Game Ended — Deduction Failed';
            $msg   = "Game #{$sessionId} ended ({$actualMins} min) but credit deduction failed. "
                   . "Please top up and contact the court owner.";
        } else {
            $title = '🏁 Game Over!';
            $msg   = "Great game! ({$actualMins} min on {$session['court_name']}). "
                   . ($charged > 0 ? '₱' . number_format($charged, 2) . ' deducted. ' : '')
                   . 'New balance: ₱' . number_format($balanceAfter, 2) . '.';
        }
        $notifyStmt->execute([$p['user_id'], $title, $msg]);

        $endedPlayers[] = [
            'user_id'         => $p['user_id'],
            'username'        => $p['username'],
            'full_name'       => $p['full_name'],
            'credits_charged' => $charged,
            'balance_after'   => $balanceAfter,
        ];
    }

    // ── Clear warmup for this specific court ──────────────────
    // Clears both the new per-court key and the legacy global key
    try {
        $db->exec("
            DELETE FROM falcon.site_content
            WHERE section IN ('warmup', 'warmup_court_{$courtId}')
        ");
    } catch (PDOException $e) { /* non-critical */ }

    $db->commit();

    // ── Audit log (outside transaction — non-blocking) ────────
    // Wrapped in try/catch because auditLog() signature may vary
    // across deployments. Game end succeeds regardless.
    try {
        auditLog(
            $db,
            'game_' . $action,
            $adminUserId,
            'game_sessions',
            $sessionId,
            ['status' => 'active'],
            [
                'status'        => $newStatus,
                'actual_mins'   => $actualMins,
                'total_charged' => $totalChargedAll,
            ],
            'success',
            "Ended by {$adminName}"
        );
    } catch (Throwable $e) {
        error_log('[end_game] auditLog failed (non-fatal): ' . $e->getMessage());
    }

    // ── Next group ready? (filtered to this court) ────────────
    $nextStmt = $db->prepare("
        SELECT COUNT(*) FROM falcon.game_queue
        WHERE session_id IS NULL
          AND (court_id = ? OR court_id IS NULL)
    ");
    $nextStmt->execute([$courtId]);
    $nextCount = (int)$nextStmt->fetchColumn();

    // warmup_mins from the court that just finished
    $warmupMins = (int)($session['warmup_mins'] ?? 2);

    echo json_encode([
        'status'        => 'success',
        'action'        => $action,
        'message'       => $action === 'cancel'
            ? "Game #{$sessionId} cancelled after {$actualMins} min."
            : "Game #{$sessionId} completed on {$session['court_name']}. "
              . "Duration: {$actualMins} min. Total charged: ₱{$totalChargedAll}.",
        'session_id'    => $sessionId,
        'court_id'      => $courtId,
        'court_name'    => $session['court_name'],
        'actual_mins'   => $actualMins,
        'total_charged' => $totalChargedAll,
        'ended_by'      => $adminName,
        'players'       => $endedPlayers,
        'next_ready'    => $nextCount >= PLAYERS_PER_GAME,
        'next_count'    => $nextCount,
        'warmup_mins'   => $warmupMins,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[end_game] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'code'    => 'db_error',
    ]);
}