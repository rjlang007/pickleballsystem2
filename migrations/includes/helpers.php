<?php
// ============================================================
//  FILE: includes/helpers.php
//  Shared utility functions used across multiple API files.
//  Include this instead of re-declaring functions locally.
// ============================================================
if (defined('HELPERS_LOADED')) return;
define('HELPERS_LOADED', true);

/**
 * Returns an array of all active admin and super_admin user IDs.
 * Used to fan-out notifications to all staff when a player
 * creates a booking, review, withdrawal request, etc.
 */
function getAdminUserIds(PDO $db): array {
    $stmt = $db->query(
        "SELECT id FROM falcon.users
          WHERE role IN ('admin', 'super_admin')
            AND is_active = TRUE"
    );
    return array_map(fn($row) => (int)$row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Notify all active admins with a given title + message.
 * Optionally attach a reservation_id for deep-linking.
 */
function notifyAdmins(PDO $db, string $title, string $message, ?int $reservationId = null): void {
    $adminIds = getAdminUserIds($db);
    if (empty($adminIds)) return;

    $stmt = $db->prepare(
        "INSERT INTO falcon.notifications
            (user_id, title, message, type, reservation_id, created_at)
         VALUES (?, ?, ?, 'info', ?, NOW())"
    );
    foreach ($adminIds as $adminId) {
        $stmt->execute([$adminId, $title, $message, $reservationId]);
    }
}

/**
 * Send a notification to a single user. Optional $link deep-links
 * the "View →" button on player/notifications.php (e.g. to a
 * specific tournament page for a "you're up next" alert).
 */
function notifyUser(PDO $db, int $userId, string $title, string $message, ?int $reservationId = null, ?string $link = null): void {
    $db->prepare(
        "INSERT INTO falcon.notifications
            (user_id, title, message, type, reservation_id, link, created_at)
         VALUES (?, ?, ?, 'info', ?, ?, NOW())"
    )->execute([$userId, $title, $message, $reservationId, $link]);
}

/**
 * Require admin access or redirect to login.
 * Used by admin pages and API endpoints.
 *
 * NOTE: the real implementation lives in config/security.php (it re-checks
 * the live DB role rather than trusting the session). Defined here too for
 * backward compatibility with pages that only include this file, but guarded
 * with function_exists() so requiring both files never fatals.
 */
if (!function_exists('requireAdmin')) {
    function requireAdmin(): void {
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
            header('Location: /pickleball/login.php');
            exit;
        }
    }
}

/**
 * Save tournament configuration settings to config file.
 * Merges provided settings with existing config.
 */
function saveTournamentConfig(array $newSettings): void {
    $configPath = __DIR__ . '/../config/tournament_config.php';
    $currentConfig = require $configPath;

    if (!is_array($currentConfig)) {
        $currentConfig = [];
    }

    $updatedConfig = array_merge($currentConfig, $newSettings);

    $configContent = "<?php\n// ============================================================\n";
    $configContent .= "//  FILE: config/tournament_config.php\n";
    $configContent .= "//  Central configuration for all tournament-related behaviour.\n";
    $configContent .= "//  Consumed by: TournamentEngine, ScoringEngine,\n";
    $configContent .= "//               LeaderboardEngine, bracket_viewer.php,\n";
    $configContent .= "//               public/tournaments.php, public/leaderboard.php\n";
    $configContent .= "// ============================================================\n";
    $configContent .= "return [\n\n";

    foreach ($updatedConfig as $key => $value) {
        $configContent .= "    '{$key}' => ";
        if (is_array($value)) {
            $configContent .= "[" . implode(', ', array_map(function($v) {
                return is_string($v) ? "'{$v}'" : $v;
            }, $value)) . "]";
        } elseif (is_string($value)) {
            $configContent .= "'{$value}'";
        } elseif (is_bool($value)) {
            $configContent .= $value ? 'true' : 'false';
        } else {
            $configContent .= $value;
        }
        $configContent .= ",\n";
    }

    $configContent .= "\n];";

    file_put_contents($configPath, $configContent);
}