<?php
/**
 * API: Bracket Editor - Manage tournament bracket seeds and layout
 * Path: /api/bracket_edit.php
 */

require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../includes/api_response.php');
require_once(__DIR__ . '/../tournament/bracket_generator.php');
require_once(__DIR__ . '/../tournament/tournament_engine.php');

requireAdmin();
verifySameOrigin();
if (!checkRateLimit('bracket_edit_' . (int)($_SESSION['user_id'] ?? 0), 60, 60)) {
    header('Content-Type: application/json');
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$tournament_id = $_GET['tournament_id'] ?? null;
$action = $_GET['action'] ?? null;

try {
    if (!$tournament_id) {
        return apiError('Missing tournament_id', 400);
    }

    // Get tournament
    $stmt = $pdo->prepare("SELECT * FROM falcon.tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        return apiError('Tournament not found', 404);
    }

    // Can only edit draft tournaments
    if ($tournament['status'] !== 'draft') {
        return apiError('Cannot edit bracket for non-draft tournaments', 400);
    }

    if ($method === 'GET') {
        // Get current bracket seeds
        $stmt = $pdo->prepare("
            SELECT tp.id, tp.player_id, tp.seed, u.full_name AS name, u.avatar AS avatar_url
            FROM falcon.tournament_players tp
            JOIN falcon.users u ON tp.player_id = u.id
            WHERE tp.tournament_id = ?
            ORDER BY tp.seed ASC
        ");
        $stmt->execute([$tournament_id]);
        $seeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return apiSuccess([
            'tournament_id' => $tournament_id,
            'bracket_type' => $tournament['bracket_type'],
            'seeds' => $seeds,
            'total_players' => count($seeds)
        ]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['action'])) {
            return apiError('Missing action', 400);
        }

        if ($input['action'] === 'reorder_seeds') {
            // Reorder player seeds
            if (!isset($input['new_order']) || !is_array($input['new_order'])) {
                return apiError('Invalid new_order', 400);
            }

            // Validate all player IDs exist in tournament
            $player_ids = array_column($input['new_order'], 'player_id');
            $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt FROM falcon.tournament_players 
                WHERE tournament_id = ? AND player_id IN ($placeholders)
            ");
            $params = array_merge([$tournament_id], $player_ids);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['cnt'] != count($player_ids)) {
                return apiError('Invalid player IDs', 400);
            }

            // Update seeds
            $pdo->beginTransaction();
            try {
                foreach ($input['new_order'] as $index => $item) {
                    $seed = $index + 1;
                    $stmt = $pdo->prepare("
                        UPDATE falcon.tournament_players 
                        SET seed = ? 
                        WHERE tournament_id = ? AND player_id = ?
                    ");
                    $stmt->execute([$seed, $tournament_id, $item['player_id']]);
                }

                // Audit log
                $audit_data = [
                    'action' => 'bracket_seeds_reordered',
                    'tournament_id' => $tournament_id,
                    'total_players' => count($player_ids),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                $stmt = $pdo->prepare("
                    INSERT INTO falcon.audit_log (admin_id, action, details, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], 'bracket_edit', json_encode($audit_data)]);

                $pdo->commit();

                return apiSuccess([
                    'message' => 'Seeds reordered successfully',
                    'tournament_id' => $tournament_id,
                    'total_players' => count($player_ids)
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($input['action'] === 'randomize_seeds') {
            // Randomize seeds
            $stmt = $pdo->prepare("
                SELECT player_id FROM falcon.tournament_players 
                WHERE tournament_id = ? 
                ORDER BY seed ASC
            ");
            $stmt->execute([$tournament_id]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $player_ids = array_column($players, 'player_id');
            
            // Shuffle
            shuffle($player_ids);

            $pdo->beginTransaction();
            try {
                foreach ($player_ids as $index => $player_id) {
                    $seed = $index + 1;
                    $stmt = $pdo->prepare("
                        UPDATE falcon.tournament_players 
                        SET seed = ? 
                        WHERE tournament_id = ? AND player_id = ?
                    ");
                    $stmt->execute([$seed, $tournament_id, $player_id]);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO falcon.audit_log (admin_id, action, details, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $audit_data = [
                    'action' => 'bracket_seeds_randomized',
                    'tournament_id' => $tournament_id,
                    'total_players' => count($player_ids),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                $stmt->execute([$_SESSION['user_id'], 'bracket_edit', json_encode($audit_data)]);

                $pdo->commit();

                return apiSuccess([
                    'message' => 'Seeds randomized successfully',
                    'tournament_id' => $tournament_id
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($input['action'] === 'auto_seed_by_rating') {
            // Auto-seed by leaderboard rating
            $stmt = $pdo->prepare("
                SELECT tp.player_id, l.rank AS ranking
                FROM falcon.tournament_players tp
                LEFT JOIN falcon.leaderboard l ON tp.player_id = l.player_id AND l.season = EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER
                WHERE tp.tournament_id = ?
                ORDER BY l.rank ASC NULLS LAST, tp.seed ASC
            ");
            $stmt->execute([$tournament_id]);
            $rated_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                foreach ($rated_players as $index => $player) {
                    $seed = $index + 1;
                    $stmt = $pdo->prepare("
                        UPDATE falcon.tournament_players 
                        SET seed = ? 
                        WHERE tournament_id = ? AND player_id = ?
                    ");
                    $stmt->execute([$seed, $tournament_id, $player['player_id']]);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO falcon.audit_log (admin_id, action, details, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $audit_data = [
                    'action' => 'bracket_auto_seeded_by_rating',
                    'tournament_id' => $tournament_id,
                    'total_players' => count($rated_players),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                $stmt->execute([$_SESSION['user_id'], 'bracket_edit', json_encode($audit_data)]);

                $pdo->commit();

                return apiSuccess([
                    'message' => 'Seeds auto-assigned by rating successfully',
                    'tournament_id' => $tournament_id
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return apiError('Unknown action', 400);
    }

    return apiError('Method not allowed', 405);

} catch (Exception $e) {
    error_log('Bracket edit API error: ' . $e->getMessage());
    return apiError('Server error. Please try again.', 500);
}
?>
