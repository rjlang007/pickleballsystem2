<?php
// ============================================================
//  FILE: court/game_ticker.php
//
//  FIX v4 (MULTI-COURT):
//
//  v3 fixed the exit/return bug so process_scan.php gets its
//  response back. v4 fixes the remaining single-court assumptions:
//
//  1. Removed hardcoded `$courtId = 1`.
//     The ticker now iterates ALL active, non-maintenance courts
//     via runTickerForAllCourts(). Each court gets its own
//     warmup key: section='warmup_court_{id}'.
//
//  2. Queue queries now filter by court_id:
//       WHERE session_id IS NULL AND (court_id = ? OR court_id IS NULL)
//     The IS NULL fallback preserves compat with pre-migration rows.
//
//  3. startGameInternal() / endGameInternal() accept $courtId
//     explicitly — no more implicit assumption of court 1.
//
//  4. When called internally by process_scan.php, the ticker
//     runs only for the court the player just joined
//     ($GLOBALS['_ticker_court_id'] if set, else all courts).
//     process_scan.php sets this before require'ing the file:
//       $GLOBALS['_ticker_court_id'] = $courtId;
//
//  5. All warmup site_content keys are now per-court:
//       section = 'warmup_court_{id}', key = 'started_at'
//     Old single-court section='warmup' rows are cleaned up on
//     first run.
//
//  PRESERVED from v3:
//   • exit vs return fix (internal vs direct call)
//   • flock race-condition guard
//   • upsert / getSC helpers
//   • Pause-state awareness in runTicker
//   • startGameInternal / endGameInternal transaction logic
//   • GAME_TICKER_INTERNAL / $GLOBALS['_ticker_result'] contract
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/websocket.php';

$_tickerInternal = defined('GAME_TICKER_INTERNAL');

if (!$_tickerInternal) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
}

// ── Race-condition lock ───────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/game_ticker.lock';
$lock     = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$_tickerInternal) {
        echo json_encode(['status' => 'skipped', 'reason' => 'lock_busy']);
        exit;
    }
    $GLOBALS['_ticker_result'] = ['status' => 'skipped', 'reason' => 'lock_busy'];
    return;
}

$db = getDB();

// ── Helpers ───────────────────────────────────────────────────
function ticker_upsert(PDO $db, string $section, string $key, string $value): void {
    $db->prepare("
        INSERT INTO falcon.site_content (section, key, value, updated_at)
        VALUES (:s, :k, :v, NOW())
        ON CONFLICT (section, key) DO UPDATE
            SET value = EXCLUDED.value, updated_at = NOW()
    ")->execute([':s' => $section, ':k' => $key, ':v' => $value]);
}

function ticker_getSC(PDO $db, string $section, string $key): ?string {
    $stmt = $db->prepare(
        "SELECT value FROM falcon.site_content WHERE section = :s AND key = :k"
    );
    $stmt->execute([':s' => $section, ':k' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : null;
}

// ── Clean up legacy single-court warmup rows (one-time) ──────
try {
    $db->exec("
        DELETE FROM falcon.site_content
        WHERE section = 'warmup'
          AND key IN ('started_at', 'court_id')
    ");
} catch (PDOException $e) { /* non-critical */ }

// ── Determine which courts to tick ───────────────────────────
// When called from process_scan.php, only tick the court the
// player just joined to avoid unnecessary cross-court work.
$targetCourtId = isset($GLOBALS['_ticker_court_id'])
    ? (int)$GLOBALS['_ticker_court_id']
    : null;

if ($targetCourtId) {
    $courtsStmt = $db->prepare("
        SELECT id, name, short_code, game_duration, warmup_mins,
               credit_cost, is_active
        FROM falcon.courts
        WHERE id = ? AND is_active = TRUE AND is_maintenance = FALSE
        LIMIT 1
    ");
    $courtsStmt->execute([$targetCourtId]);
} else {
    $courtsStmt = $db->query("
        SELECT id, name, short_code, game_duration, warmup_mins,
               credit_cost, is_active
        FROM falcon.courts
        WHERE is_active = TRUE AND is_maintenance = FALSE
        ORDER BY sort_order, id
    ");
}
$courts = $courtsStmt->fetchAll();

// ── Tick each court ───────────────────────────────────────────
function runTickerForAllCourts(PDO $db, array $courts): array {
    $results = [];
    foreach ($courts as $court) {
        $results[(int)$court['id']] = runTickerForCourt($db, $court);
    }
    return $results;
}

function runTickerForCourt(PDO $db, array $court): array {
    $courtId = (int)$court['id'];
    $now     = time();
    $warmupSection = 'warmup_court_' . $courtId;

    // ── STEP 1: Check for active game on THIS court ───────────
    $activeGame = $db->prepare("
        SELECT gs.id, gs.started_at, gs.duration_mins, gs.court_id
        FROM falcon.game_sessions gs
        WHERE gs.court_id = ? AND gs.status = 'active'
        LIMIT 1
    ");
    $activeGame->execute([$courtId]);
    $game = $activeGame->fetch();

    if ($game) {
        // Check pause state
        $pausedSessId = ticker_getSC($db, 'game_pause', 'session_id');
        $isPaused     = ($pausedSessId !== null && (int)$pausedSessId === (int)$game['id']);

        if (!$isPaused) {
            $endTs   = strtotime($game['started_at']) + ($game['duration_mins'] * 60);
            $remSecs = $endTs - $now;

            if ($remSecs <= 0) {
                endGameInternal($db, (int)$game['id'], $courtId);
                return ['status' => 'game_ended', 'session_id' => $game['id'], 'court_id' => $courtId];
            }
        }

        // Clear any stale warmup for this court now that a game is running
        try {
            $db->exec("DELETE FROM falcon.site_content WHERE section = '{$warmupSection}'");
        } catch (PDOException $e) { /* non-critical */ }

        return [
            'status'     => 'game_running',
            'session_id' => $game['id'],
            'court_id'   => $courtId,
            'rem_secs'   => $isPaused
                ? (int)(ticker_getSC($db, 'game_pause', 'paused_rem') ?? 0)
                : max(0, strtotime($game['started_at']) + ($game['duration_mins'] * 60) - $now),
            'is_paused'  => $isPaused,
        ];
    }

    // ── STEP 2: No active game — count queue for THIS court ───
    // Re-query cleanly for queue count
    $qStmt = $db->prepare("
        SELECT COUNT(*) FROM falcon.game_queue
        WHERE session_id IS NULL
          AND (court_id = ? OR court_id IS NULL)
    ");
    $qStmt->execute([$courtId]);
    $queueCount = (int)$qStmt->fetchColumn();

    if ($queueCount < PLAYERS_PER_GAME) {
        // Not enough players — clear any stale warmup
        try {
            $db->exec("DELETE FROM falcon.site_content WHERE section = '{$warmupSection}'");
        } catch (PDOException $e) { /* non-critical */ }

        return [
            'status'   => 'waiting',
            'court_id' => $courtId,
            'queued'   => $queueCount,
            'needed'   => PLAYERS_PER_GAME - $queueCount,
        ];
    }

    // ── STEP 3: Enough players — check warmup for THIS court ──
    $warmupMins      = max(0, (int)($court['warmup_mins'] ?? 2));
    $warmupSecs      = $warmupMins * 60;
    $warmupStartedAt = ticker_getSC($db, $warmupSection, 'started_at');

    if (!$warmupStartedAt) {
        // Start warmup countdown for this court
        ticker_upsert($db, $warmupSection, 'started_at', (string)$now);
        return [
            'status'      => 'warmup_started',
            'court_id'    => $courtId,
            'warmup_secs' => $warmupSecs,
            'queued'      => $queueCount,
        ];
    }

    $elapsed    = $now - (int)$warmupStartedAt;
    $warmupLeft = $warmupSecs - $elapsed;

    if ($warmupLeft > 0) {
        return [
            'status'      => 'warmup_running',
            'court_id'    => $courtId,
            'warmup_left' => $warmupLeft,
            'queued'      => $queueCount,
        ];
    }

    // ── STEP 4: Warmup done — start the game on this court ────
    try {
        $db->exec("DELETE FROM falcon.site_content WHERE section = '{$warmupSection}'");
    } catch (PDOException $e) { /* non-critical */ }

    return startGameInternal($db, $courtId, $court);
}

// ════════════════════════════════════════════════════════════
//  INTERNAL: Start game for a specific court
// ════════════════════════════════════════════════════════════
function startGameInternal(PDO $db, int $courtId, array $court): array
{
    try {
        $db->beginTransaction();

        $duration = (int)$court['game_duration'];

        // Lock the next PLAYERS_PER_GAME rows for this court
        $queueStmt = $db->prepare("
            SELECT gq.id AS queue_id, gq.user_id,
                   u.username, u.full_name,
                   COALESCE(u.display_name, u.full_name) AS screen_name,
                   COALESCE(u.show_display_name, TRUE)   AS show_name,
                   COALESCE(pp.is_active, FALSE)          AS is_active,
                   COALESCE(pp.expires_at, '2099-12-31 23:59:59+00') AS expires_at
            FROM falcon.game_queue gq
            JOIN falcon.users u               ON u.id  = gq.user_id
            LEFT JOIN falcon.player_passes pp ON pp.id = gq.pass_id
            WHERE gq.session_id IS NULL
              AND (gq.court_id = ? OR gq.court_id IS NULL)
            ORDER BY gq.joined_at ASC
            LIMIT " . PLAYERS_PER_GAME . "
            FOR UPDATE OF gq
        ");
        $queueStmt->execute([$courtId]);
        $players = $queueStmt->fetchAll();

        if (count($players) < PLAYERS_PER_GAME) {
            $db->rollBack();
            return [
                'status'   => 'waiting',
                'court_id' => $courtId,
                'message'  => 'Not enough players after lock.',
            ];
        }

        // Create session
        $sesStmt = $db->prepare("
            INSERT INTO falcon.game_sessions
                (court_id, status, credit_cost, duration_mins, started_at, created_at)
            VALUES (?, 'active', ?, ?, NOW(), NOW())
            RETURNING id, started_at
        ");
        $sesStmt->execute([$courtId, (int)$court['credit_cost'], $duration]);
        $sesRow    = $sesStmt->fetch();
        $sessionId = (int)$sesRow['id'];
        $startedAt = $sesRow['started_at'];
        $endTs     = strtotime($startedAt) + $duration * 60;

        $insPlayer = $db->prepare("
            INSERT INTO falcon.game_players
                (session_id, user_id, credits_charged, payment_status, joined_at)
            VALUES (?, ?, ?, 'paid', NOW())
        ");
        $linkQueue = $db->prepare("
            UPDATE falcon.game_queue SET session_id = ? WHERE id = ?
        ");
        $notify = $db->prepare("
            INSERT INTO falcon.notifications (user_id, title, message, type, created_at)
            VALUES (?, ?, ?, 'success', NOW())
        ");

        $names     = array_column($players, 'full_name');
        $playerOut = [];
        $courtName = $court['name'];
        $shortCode = $court['short_code'] ?? ('C' . $courtId);

        foreach ($players as $p) {
            $insPlayer->execute([$sessionId, $p['user_id'], (float)$court['credit_cost']]);
            $linkQueue->execute([$sessionId, $p['queue_id']]);

            $others = implode(', ', array_filter($names, fn($n) => $n !== $p['full_name']));
            $notify->execute([
                $p['user_id'],
                '🎮 Game Started!',
                "Your {$duration}-min game has begun on {$courtName} ({$shortCode})! Playing with: {$others}.",
            ]);

            $playerOut[] = [
                'user_id'      => (int)$p['user_id'],
                'username'     => $p['username'],
                'full_name'    => $p['full_name'],
                'display_name' => $p['show_name'] ? ($p['screen_name'] ?: $p['full_name']) : null,
            ];
        }

        $db->commit();

        // Broadcast game start event
        broadcastCourtStatus($courtId);
        broadcastQueueStatus($courtId);

        error_log("[game_ticker] Game #{$sessionId} started on {$courtName} ({$shortCode}). Players: " .
            implode(', ', array_column($players, 'username')));

        return [
            'status'     => 'started',
            'message'    => "Game started! " . PLAYERS_PER_GAME . " players — {$duration} min on {$courtName}.",
            'session_id' => $sessionId,
            'duration'   => $duration,
            'started_at' => $startedAt,
            'end_ts'     => $endTs,
            'court_id'   => $courtId,
            'court_name' => $courtName,
            'short_code' => $shortCode,
            'players'    => $playerOut,
        ];

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[game_ticker] startGameInternal error: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage(), 'court_id' => $courtId];
    }
}

// ════════════════════════════════════════════════════════════
//  INTERNAL: End game for a specific court
// ════════════════════════════════════════════════════════════
function endGameInternal(PDO $db, int $sessionId, int $courtId): void
{
    $warmupSection = 'warmup_court_' . $courtId;

    try {
        $db->beginTransaction();

        $sesStmt = $db->prepare("
            SELECT gs.id, gs.status, gs.started_at, gs.duration_mins,
                   c.name AS court_name, c.short_code, c.credit_cost
            FROM falcon.game_sessions gs
            JOIN falcon.courts c ON c.id = gs.court_id
            WHERE gs.id = ? AND gs.status = 'active'
            FOR UPDATE
        ");
        $sesStmt->execute([$sessionId]);
        $sess = $sesStmt->fetch();

        if (!$sess) {
            $db->rollBack();
            return;
        }

        $db->prepare("
            UPDATE falcon.game_sessions
            SET status = 'completed', ended_at = NOW()
            WHERE id = ?
        ")->execute([$sessionId]);

        $db->prepare("DELETE FROM falcon.game_queue WHERE session_id = ?")
           ->execute([$sessionId]);

        $players = $db->prepare("
            SELECT gp.user_id, u.username, u.full_name
            FROM falcon.game_players gp
            JOIN falcon.users u ON u.id = gp.user_id
            WHERE gp.session_id = ?
        ");
        $players->execute([$sessionId]);

        $actualMins = round((time() - strtotime($sess['started_at'])) / 60, 1);
        $courtName  = $sess['court_name'];
        $shortCode  = $sess['short_code'] ?? ('C' . $courtId);

        $notify = $db->prepare("
            INSERT INTO falcon.notifications (user_id, type, title, message, created_at)
            VALUES (?, 'info', ?, ?, NOW())
        ");

        foreach ($players->fetchAll() as $p) {
            $passRow = $db->prepare("
                SELECT is_active, expires_at
                FROM falcon.player_passes WHERE user_id = ? LIMIT 1
            ");
            $passRow->execute([$p['user_id']]);
            $pp = $passRow->fetch();

            $isPlaceholder = $pp && strtotime($pp['expires_at']) > strtotime('+50 years');
            $isExpired     = $pp && !$isPlaceholder && strtotime($pp['expires_at']) < time();
            $stillActive   = $pp && $pp['is_active'] && !$isExpired;

            $msg = $stillActive
                ? "Great game on {$courtName}! ({$actualMins} min). Your pass is still active — scan to play again!"
                : "Game over on {$courtName}! ({$actualMins} min). Top up to play again!";

            $notify->execute([$p['user_id'], '🏁 Game Over!', $msg]);
        }

        // Clear warmup for this court
        try {
            $db->exec("DELETE FROM falcon.site_content WHERE section = '{$warmupSection}'");
        } catch (PDOException $e) { /* non-critical */ }

        $db->commit();

        // Broadcast game end event
        broadcastCourtStatus($courtId);
        broadcastQueueStatus($courtId);

        error_log("[game_ticker] Game #{$sessionId} ended on {$courtName} ({$shortCode}) after {$actualMins} min.");

        // Check if next group is ready for THIS court
        $nextStmt = $db->prepare("
            SELECT COUNT(*) FROM falcon.game_queue
            WHERE session_id IS NULL
              AND (court_id = ? OR court_id IS NULL)
        ");
        $nextStmt->execute([$courtId]);
        $nextCount = (int)$nextStmt->fetchColumn();

        if ($nextCount >= PLAYERS_PER_GAME) {
            try {
                ticker_upsert($db, $warmupSection, 'started_at', (string)time());
                error_log("[game_ticker] Next group ready on {$courtName} ({$nextCount} players) — warmup started.");
            } catch (PDOException $e) {
                error_log('[game_ticker] warmup upsert failed: ' . $e->getMessage());
            }
        }

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[game_ticker] endGameInternal error: ' . $e->getMessage());
    }
}

// ── Run and respond ───────────────────────────────────────────
try {
    if (empty($courts)) {
        $result = ['status' => 'no_courts', 'message' => 'No active courts found.'];
    } else {
        $allResults = runTickerForAllCourts($db, $courts);

        // For internal calls, return the result for the specific court
        // that triggered the scan (most relevant to process_scan.php)
        if ($targetCourtId && isset($allResults[$targetCourtId])) {
            $result = $allResults[$targetCourtId];
        } else {
            // For direct HTTP calls return all courts' results
            $result = ['status' => 'ok', 'courts' => $allResults];
        }
    }

    flock($lock, LOCK_UN);
    fclose($lock);

    if (!$_tickerInternal) {
        echo json_encode($result);
        exit;
    }

    $GLOBALS['_ticker_result'] = $result;
    return;

} catch (PDOException $e) {
    flock($lock, LOCK_UN);
    fclose($lock);
    error_log('[game_ticker] DB error: ' . $e->getMessage());

    if (!$_tickerInternal) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $GLOBALS['_ticker_result'] = ['status' => 'error', 'message' => $e->getMessage()];
    return;
}