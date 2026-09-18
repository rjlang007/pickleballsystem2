<?php
// ============================================================
//  FILE: court/auto_end_games.php
//  FIXED: booking_state_machine.php require is now guarded
//         so a missing file never kills the whole app.
// ============================================================

$isCron = (PHP_SAPI === 'cli' && in_array('--cron', $argv ?? [], true));

if (!$isCron) {
    if (!defined('DB_LOADED') || !defined('APP_NAME')) {
        return;
    }
} else {
    $appRoot = realpath(__DIR__ . '/..');
    require_once $appRoot . '/config/db.php';
    require_once $appRoot . '/config/app.php';
}

// ── Guard: load booking state machine only if it exists ───────
// Missing file previously caused a fatal on EVERY page load,
// blanking the entire app. Now it degrades gracefully.
$_bsmPath = __DIR__ . '/../includes/booking_state_machine.php';
$_bsmLoaded = false;
if (file_exists($_bsmPath)) {
    try {
        require_once $_bsmPath;
        $_bsmLoaded = true;
    } catch (Throwable $e) {
        error_log('[auto_end_games] booking_state_machine load failed: ' . $e->getMessage());
    }
}

// ── Rate-limit: run at most once every 30 seconds ────────────
function _autoend_should_run(PDO $db): bool {
    try {
        $row = $db->query("
            SELECT value FROM falcon.site_content
            WHERE section = 'autoend' AND key = 'last_run'
            LIMIT 1
        ")->fetch();

        $last = $row ? (int)$row['value'] : 0;
        if ((time() - $last) < 30) {
            return false;
        }

        $db->prepare("
            INSERT INTO falcon.site_content (section, key, value, updated_at)
            VALUES ('autoend', 'last_run', :v, NOW())
            ON CONFLICT (section, key)
            DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
        ")->execute([':v' => (string)time()]);

        return true;
    } catch (Throwable $e) {
        error_log('[auto_end_games] rate-limit check failed: ' . $e->getMessage());
        return false;
    }
}

function _autoend_resolve_reservations(PDO $db): int {
    // Only run if BookingStateMachine class is available
    if (!class_exists('BookingStateMachine')) {
        return 0;
    }

    try {
        $rows = $db->query(
            "SELECT id
               FROM falcon.reservations
              WHERE status = 'confirmed'
                AND (slot_date + slot_time)::timestamptz <= NOW() - INTERVAL '30 minutes'"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 0;
        }

        $completed = 0;
        foreach ($rows as $row) {
            $reservationId = (int)$row['id'];
            try {
                $result = BookingStateMachine::transition($reservationId, 'completed', ['auto' => true]);
                if ($result['ok']) {
                    $completed++;
                }
            } catch (Throwable $e) {
                error_log('[auto_end_games] BSM transition failed for reservation ' . $reservationId . ': ' . $e->getMessage());
            }
        }

        if ($completed > 0) {
            error_log("[auto_end_games] auto-completed {$completed} reservations.");
        }
        return $completed;
    } catch (Throwable $e) {
        error_log('[auto_end_games] reservation auto-complete failed: ' . $e->getMessage());
        return 0;
    }
}

// ── Main runner ───────────────────────────────────────────────
function _autoend_run(): void {
    try {
        $db = getDB();
    } catch (Throwable $e) {
        error_log('[auto_end_games] DB connect: ' . $e->getMessage());
        return;
    }

    if (!_autoend_should_run($db)) {
        return;
    }

    _autoend_resolve_reservations($db);

    // ── Find overdue sessions ─────────────────────────────────
    try {
        $overdue = $db->query("
            SELECT
                gs.id            AS session_id,
                gs.court_id,
                gs.session_type,
                gs.reservation_id,
                gs.started_at,
                gs.duration_mins,
                gs.credit_cost,
                c.name           AS court_name,

                CASE
                    WHEN gs.session_type = 'reservation' AND r.id IS NOT NULL
                        THEN (r.slot_date + r.slot_end)::timestamptz
                    ELSE gs.started_at + (gs.duration_mins * INTERVAL '1 minute')
                END AS effective_end_at

            FROM falcon.game_sessions  gs
            JOIN falcon.courts         c  ON c.id  = gs.court_id
            LEFT JOIN falcon.reservations r
                   ON r.id = gs.reservation_id
                  AND gs.session_type = 'reservation'

            WHERE gs.status = 'active'
              AND (
                    (
                      gs.session_type != 'reservation'
                      AND gs.started_at + (gs.duration_mins * INTERVAL '1 minute') <= NOW()
                    )
                    OR
                    (
                      gs.session_type = 'reservation'
                      AND r.id IS NOT NULL
                      AND (r.slot_date + r.slot_end)::timestamptz <= NOW()
                    )
                  )
            ORDER BY gs.id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        error_log('[auto_end_games] overdue query: ' . $e->getMessage());
        return;
    }

    if (empty($overdue)) {
        return;
    }

    // Prepare reusable statements
    try {
        $walletUpdateStmt = $db->prepare("
            UPDATE falcon.wallets
               SET balance    = balance - :cost,
                   updated_at = NOW()
             WHERE user_id    = :uid
               AND balance    >= :cost
        ");

        $notifyStmt = $db->prepare("
            INSERT INTO falcon.notifications
                (user_id, type, title, message, created_at)
            VALUES (:uid, 'info', :title, :msg, NOW())
        ");

        $getBalanceStmt = $db->prepare("
            SELECT COALESCE(balance, 0) AS balance
              FROM falcon.wallets
             WHERE user_id = :uid
        ");

        $getPlayersStmt = $db->prepare("
            SELECT gp.user_id, u.full_name, u.username,
                   gp.credits_charged
              FROM falcon.game_players gp
              JOIN falcon.users u ON u.id = gp.user_id
             WHERE gp.session_id = :sid
        ");

        $endSessionStmt = $db->prepare("
            UPDATE falcon.game_sessions
               SET status   = 'completed',
                   ended_at = NOW()
             WHERE id       = :sid
               AND status   = 'active'
        ");

        $clearPauseStmt = $db->prepare("
            DELETE FROM falcon.site_content
             WHERE section = 'game_pause'
               AND key     = 'session_id'
               AND value   = :sid
        ");

    } catch (Throwable $e) {
        error_log('[auto_end_games] statement prep: ' . $e->getMessage());
        return;
    }

    // wallet_transactions may or may not exist — check once
    $hasTxnTable = false;
    try {
        $hasTxnTable = (bool)$db->query(
            "SELECT 1 FROM information_schema.tables
              WHERE table_schema = 'falcon' AND table_name = 'transactions'
              LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) { /* non-fatal */ }

    $txnStmt = null;
    if ($hasTxnTable) {
        try {
            $txnStmt = $db->prepare("
                INSERT INTO falcon.transactions
                    (user_id, type, amount, reason, related_table,
                     related_id, balance_before, balance_after)
                VALUES
                    (:uid, 'deduction', :amt, :note, 'game_sessions',
                     :ref_id, :before, :after)
            ");
        } catch (Throwable $e) {
            error_log('[auto_end_games] txn stmt prep: ' . $e->getMessage());
        }
    }

    // ── Process each overdue session ──────────────────────────
    global $isCron;
    foreach ($overdue as $sess) {
        $sessionId  = (int)$sess['session_id'];
        $creditCost = (float)$sess['credit_cost'];
        $courtName  = $sess['court_name'];
        $startedAt  = $sess['started_at'];
        $actualMins = round((time() - strtotime($startedAt)) / 60, 1);

        try {
            $locked = $db->query(
                "SELECT pg_try_advisory_lock({$sessionId})"
            )->fetchColumn();

            if (!$locked) {
                continue;
            }
        } catch (Throwable $e) {
            error_log("[auto_end_games] advisory lock #{$sessionId}: " . $e->getMessage());
            continue;
        }

        try {
            $db->beginTransaction();

            $stillActive = $db->prepare(
                "SELECT id FROM falcon.game_sessions WHERE id = ? AND status = 'active' FOR UPDATE"
            );
            $stillActive->execute([$sessionId]);
            if (!$stillActive->fetch()) {
                $db->rollBack();
                continue;
            }

            $endSessionStmt->execute([':sid' => $sessionId]);

            $getPlayersStmt->execute([':sid' => $sessionId]);
            $players = $getPlayersStmt->fetchAll();

            foreach ($players as $p) {
                $uid = (int)$p['user_id'];

                $getBalanceStmt->execute([':uid' => $uid]);
                $balanceRow    = $getBalanceStmt->fetch();
                $balanceBefore = $balanceRow ? (float)$balanceRow['balance'] : 0.0;

                $charged      = 0.0;
                $balanceAfter = $balanceBefore;

                if ($creditCost > 0 && $balanceBefore >= $creditCost) {
                    $walletUpdateStmt->execute([
                        ':cost' => $creditCost,
                        ':uid'  => $uid,
                    ]);

                    if ($walletUpdateStmt->rowCount() === 1) {
                        $charged      = $creditCost;
                        $balanceAfter = $balanceBefore - $creditCost;

                        if ($txnStmt) {
                            try {
                                $txnStmt->execute([
                                    ':uid'    => $uid,
                                    ':amt'    => $creditCost,
                                    ':note'   => "Auto-ended Game #{$sessionId} on {$courtName} ({$actualMins} min)",
                                    ':before' => $balanceBefore,
                                    ':after'  => $balanceAfter,
                                    ':ref_id' => $sessionId,
                                ]);
                            } catch (Throwable $e) {
                                error_log('[auto_end_games] txn insert: ' . $e->getMessage());
                            }
                        }

                        syncPassActive($db, $uid, $balanceAfter);
                    }
                }

                $title = '🏁 Game Over!';
                $msg   = "Your game on {$courtName} ended automatically after {$actualMins} min."
                       . ($charged > 0
                            ? " ₱" . number_format($charged, 2) . " deducted. Balance: ₱" . number_format($balanceAfter, 2) . "."
                            : " No credits deducted.");

                try {
                    $notifyStmt->execute([
                        ':uid'   => $uid,
                        ':title' => $title,
                        ':msg'   => $msg,
                    ]);
                } catch (Throwable $e) {
                    error_log('[auto_end_games] notify insert: ' . $e->getMessage());
                }
            }

            $clearPauseStmt->execute([':sid' => (string)$sessionId]);

            $db->prepare("
                DELETE FROM falcon.site_content WHERE section = 'warmup'
            ")->execute();

            $db->commit();

            error_log("[auto_end_games] ✅ Session #{$sessionId} auto-completed. "
                . count($players) . " players, ₱{$creditCost} each, {$actualMins} min.");

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[auto_end_games] ❌ Session #{$sessionId} failed: " . $e->getMessage());
        }

        if ($isCron) {
            try {
                $db->query("SELECT pg_advisory_unlock({$sessionId})");
            } catch (Throwable $e) { /* ignore */ }
        }
    }
}

// ── Execute ───────────────────────────────────────────────────
_autoend_run();