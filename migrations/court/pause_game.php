<?php
// ============================================================
//  FILE: court/pause_game.php
//  Pause / Resume / Reset the active game timer.
//
//  Pause state is stored in falcon.site_content:
//    section='game_pause', key='session_id'  → active session
//    section='game_pause', key='paused_rem'  → seconds remaining when paused
//
//  Reset: rewrites game_sessions.started_at = NOW() so the
//         client recalculates full duration remaining.
//
//  POST JSON:
//    { action: "pause",  session_id: N, rem_secs: N }
//    { action: "resume", session_id: N }
//    { action: "reset",  session_id: N }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['status'=>'error','message'=>'POST required.']); exit;
}
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403); echo json_encode(['status'=>'error','message'=>'Forbidden.']); exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$action    = $body['action']     ?? '';
$sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;
$remSecs   = isset($body['rem_secs'])   ? max(0,(int)$body['rem_secs']) : 0;

if (!$sessionId || !in_array($action, ['pause','resume','reset'])) {
    echo json_encode(['status'=>'error','message'=>'Invalid parameters.']); exit;
}

$db = getDB();

// Verify session is active
$row = $db->prepare("SELECT id, duration_mins, started_at FROM falcon.game_sessions WHERE id=? AND status='active'");
$row->execute([$sessionId]);
$session = $row->fetch();
if (!$session) {
    echo json_encode(['status'=>'error','message'=>'Active session not found.']); exit;
}

function sc_set(PDO $db, string $key, string $val): void {
    $db->prepare("
        INSERT INTO falcon.site_content (section,key,value,updated_at)
        VALUES ('game_pause',:k,:v,NOW())
        ON CONFLICT (section,key) DO UPDATE SET value=EXCLUDED.value, updated_at=NOW()
    ")->execute([':k'=>$key,':v'=>$val]);
}
function sc_get(PDO $db, string $key): ?string {
    $s=$db->prepare("SELECT value FROM falcon.site_content WHERE section='game_pause' AND key=:k");
    $s->execute([':k'=>$key]); $r=$s->fetch(); return $r?$r['value']:null;
}
function sc_clear(PDO $db): void {
    $db->exec("DELETE FROM falcon.site_content WHERE section='game_pause'");
}
function notify_players(PDO $db, int $sid, string $title, string $msg, string $type='info'): void {
    $pl=$db->prepare("SELECT user_id FROM falcon.game_players WHERE session_id=?");
    $pl->execute([$sid]);
    $n=$db->prepare("INSERT INTO falcon.notifications(user_id,title,message,type,created_at) VALUES(?,?,?,?,NOW())");
    foreach($pl->fetchAll() as $p) $n->execute([$p['user_id'],$title,$msg,$type]);
}

try {

    // ── PAUSE ─────────────────────────────────────────────
    if ($action === 'pause') {
        if (sc_get($db,'session_id') === (string)$sessionId) {
            echo json_encode(['status'=>'already_paused','message'=>'Game is already paused.']); exit;
        }
        $maxSecs = $session['duration_mins'] * 60;
        $rem     = $remSecs > 0 ? min($remSecs, $maxSecs) : $maxSecs;

        sc_set($db,'session_id',(string)$sessionId);
        sc_set($db,'paused_rem',(string)$rem);

        notify_players($db,$sessionId,'⏸️ Game Paused','Admin has paused the game timer. Please wait on court.','info');

        echo json_encode(['status'=>'paused','message'=>'Game paused — '.gmdate('i:s',$rem).' remaining.','paused_rem'=>$rem]);
        exit;
    }

    // ── RESUME ────────────────────────────────────────────
    if ($action === 'resume') {
        $storedSid = sc_get($db,'session_id');
        $pausedRem = sc_get($db,'paused_rem');

        if ($storedSid === null || $pausedRem === null) {
            echo json_encode(['status'=>'not_paused','message'=>'Game is not paused.']); exit;
        }

        $rem = max(0,(int)$pausedRem);
        // Rewrite started_at so the remaining time is exactly $rem from now
        $maxSecs     = $session['duration_mins'] * 60;
        $newStartedAt = date('Y-m-d H:i:sP', time() - ($maxSecs - $rem));
        $db->prepare("UPDATE falcon.game_sessions SET started_at=? WHERE id=?")->execute([$newStartedAt,$sessionId]);

        sc_clear($db);

        notify_players($db,$sessionId,'▶️ Game Resumed','Timer has resumed. Play on! 🏓','success');

        echo json_encode([
            'status'   =>'resumed',
            'message'  =>'Game resumed — '.gmdate('i:s',$rem).' remaining.',
            'rem_secs' =>$rem,
            'end_ts'   =>time()+$rem,
        ]);
        exit;
    }

    // ── RESET ─────────────────────────────────────────────
    if ($action === 'reset') {
        $fullSecs = $session['duration_mins'] * 60;

        // started_at = NOW() means the full duration is available again
        $db->prepare("UPDATE falcon.game_sessions SET started_at=NOW() WHERE id=?")->execute([$sessionId]);
        sc_clear($db);  // also clear any pause state

        notify_players($db,$sessionId,'🔄 Timer Reset','Admin reset the game timer. Full time restored!','info');

        echo json_encode([
            'status'   =>'reset',
            'message'  =>'Timer reset to '.$session['duration_mins'].' minutes.',
            'rem_secs' =>$fullSecs,
            'end_ts'   =>time()+$fullSecs,
        ]);
        exit;
    }

} catch (PDOException $e) {
    error_log('pause_game: '.$e->getMessage());
    http_response_code(500);
    error_log('[migrations/court/pause_game] ' . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'Unable to pause the game.']);
}