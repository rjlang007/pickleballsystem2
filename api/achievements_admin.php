<?php
// ============================================================
//  FILE: api/achievements_admin.php
//  Admin achievement API.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../leaderboard/achievement_manager.php';

requireAdmin();

// SECURITY FIX: previously accepted `action` from GET too, with zero CSRF
// check and no method guard, so this admin action (awarding an achievement)
// could be triggered by a plain GET request. Now POST-only + CSRF-verified.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
verifyCsrf();
if (!checkRateLimit('achievements_admin_' . (int)($_SESSION['user_id'] ?? 0), 30, 60)) {
    http_response_code(429);
    exit('Too many requests. Please wait.');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'award':
        $playerId = (int)($_POST['player_id'] ?? 0);
        $achievementKey = $_POST['achievement_key'] ?? '';
        if (!$playerId || !$achievementKey) {
            setFlash('error', 'Missing player or achievement selection.');
            break;
        }

        if (AchievementManager::awardAchievementToPlayer($playerId, $achievementKey)) {
            setFlash('success', 'Achievement awarded successfully.');
        } else {
            setFlash('error', 'Failed to award achievement. Confirm the achievement exists.');
        }
        break;
    default:
        http_response_code(400);
        echo 'Invalid achievement action.';
        exit;
}

header('Location: ' . APP_URL . '/admin/achievements_admin.php');
exit;
