<?php
// ============================================================
//  FILE: api/leaderboard_admin_api.php
//  Admin leaderboard API actions.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

requireAdmin();

// SECURITY FIX: this endpoint used to accept `action` from either POST body
// or the GET query string with no CSRF check and no method restriction —
// meaning `save_settings`/`adjust_points` could be triggered by a plain GET
// request (e.g. an <img> tag or link an admin is tricked into loading).
// Now POST-only, CSRF-verified, and only reads `action` from the POST body.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
verifyCsrf();
if (!checkRateLimit('leaderboard_admin_' . (int)($_SESSION['user_id'] ?? 0), 30, 60)) {
    http_response_code(429);
    exit('Too many requests. Please wait.');
}

$action = $_POST['action'] ?? '';
$engine = new LeaderboardEngine();
$currentSeason = (int) date('Y');
$adminId = (int) ($_SESSION['user_id'] ?? 0);

switch ($action) {
    case 'save_settings':
        $pointDistribution = trim($_POST['point_distribution'] ?? '');
        $participationPoints = (int)($_POST['participation_points'] ?? 5);
        $swissRounds = (int)($_POST['swiss_rounds'] ?? 4);

        if ($pointDistribution === '') {
            setFlash('error', 'Point distribution cannot be empty.');
            redirect('admin/leaderboard_settings.php');
        }

        $settings = [
            'point_distribution'   => array_filter(array_map('trim', explode(',', $pointDistribution)), 'strlen'),
            'participation_points' => $participationPoints,
            'swiss_default_rounds' => $swissRounds,
        ];

        saveTournamentConfig($settings);

        setFlash('success', 'Leaderboard settings saved successfully.');
        redirect('admin/leaderboard_settings.php');
        break;

    case 'adjust_points':
        $playerId = (int)($_POST['player_id'] ?? 0);
        $points = (int)($_POST['points'] ?? 0);
        $season = (int)($_POST['season'] ?? $currentSeason);
        if (!$playerId || $points === 0) {
            setFlash('error', 'Enter a valid player and point adjustment amount.');
            redirect('admin/leaderboard_settings.php');
        }
        $engine->adjustPoints($playerId, $season, $points);
        logActivity('Leaderboard Points Adjusted', 'admin', 'normal',
            sprintf('%s adjusted player #%d by %+d pts (season %d) via API', $_SESSION['username'] ?? 'admin', $playerId, $points, $season));
        setFlash('success', 'Point adjustment processed for player ID ' . $playerId . '.');
        redirect('admin/leaderboard_settings.php');
        break;

    default:
        http_response_code(400);
        echo 'Invalid leaderboard admin action.';
        exit;
}

function saveTournamentConfig(array $settings): void
{
    $configPath = __DIR__ . '/../config/tournament_config.php';
    if (!is_writable($configPath)) {
        setFlash('error', 'Unable to write config file. Check filesystem permissions.');
        redirect('admin/leaderboard_settings.php');
    }

    $currentConfig = require $configPath;
    $merged = array_merge($currentConfig, $settings);
    $content = "<?php\nreturn " . var_export($merged, true) . ";\n";
    file_put_contents($configPath, $content);
}
