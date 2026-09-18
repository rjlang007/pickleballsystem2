<?php
/**
 * Admin: Tournament Bracket Editor
 * Path: admin/tournament_bracket_editor.php
 * Allows admin to adjust bracket seeds before tournament starts
 */

require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../config/security.php');
require_once(__DIR__ . '/../includes/security_helpers.php');

// Verify admin access
requireAdmin();

$tournament_id = $_GET['id'] ?? null;
$error = '';
$success = '';

if (!$tournament_id) {
    $error = 'Tournament ID required';
} else {
    // Get tournament
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        $error = 'Tournament not found';
    } elseif ($tournament['status'] !== 'draft') {
        $error = 'Can only edit brackets for draft tournaments';
    }
}

// Get enrolled players with seeds
$players = [];
if (!$error) {
    $stmt = $pdo->prepare("
        SELECT tp.id, tp.player_id, tp.seed, u.name, u.avatar_url 
        FROM tournament_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.tournament_id = ?
        ORDER BY tp.seed ASC
    ");
    $stmt->execute([$tournament_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bracket Editor - <?php echo htmlspecialchars($tournament['name'] ?? 'Tournament'); ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tournament_theme.css">
    <style nonce="<?= getCspNonce() ?>">
        .bracket-editor-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .editor-header {
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .editor-header h2 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .editor-header p {
            margin: 0;
            color: #999;
            font-size: 14px;
        }

        .editor-seeds-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
            min-height: 200px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 6px;
            border: 2px dashed #ddd;
        }

        .seed-card {
            background: #fff;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 6px;
            cursor: move;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            user-select: none;
        }

        .seed-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            border-color: #3498db;
            background: #f0f7ff;
        }

        .seed-card.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .seed-card.drag-over {
            background: #e3f2fd;
            border: 2px solid #3498db;
        }

        .seed-number {
            background: #3498db;
            color: #fff;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: bold;
            flex-shrink: 0;
        }

        .seed-info {
            flex-grow: 1;
            min-width: 0;
        }

        .seed-name {
            font-weight: 500;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .seed-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 8px;
            display: inline-block;
            vertical-align: middle;
        }

        .seed-handle {
            color: #999;
            font-size: 20px;
            cursor: grab;
        }

        .seed-handle:active {
            cursor: grabbing;
        }

        .editor-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .editor-btn {
            flex: 1;
            min-width: 150px;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .editor-btn-primary {
            background: #3498db;
            color: #fff;
        }

        .editor-btn-primary:hover {
            background: #2980b9;
        }

        .editor-btn-secondary {
            background: #95a5a6;
            color: #fff;
        }

        .editor-btn-secondary:hover {
            background: #7f8c8d;
        }

        .editor-btn-success {
            background: #27ae60;
            color: #fff;
        }

        .editor-btn-success:hover {
            background: #229954;
        }

        .editor-btn-danger {
            background: #e74c3c;
            color: #fff;
        }

        .editor-btn-danger:hover {
            background: #c0392b;
        }

        .editor-feedback {
            display: none;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .editor-feedback-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .editor-feedback-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .editor-feedback-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #c3e6cb;
        }

        @media (max-width: 600px) {
            .editor-seeds-container {
                grid-template-columns: 1fr;
            }

            .editor-actions {
                flex-direction: column;
            }

            .editor-btn {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <?php require_once(__DIR__ . '/../includes/header.php'); ?>

    <div class="bracket-editor-container">
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$error): ?>
            <div class="editor-header">
                <h2>Edit Bracket Seeds</h2>
                <p><?php echo htmlspecialchars($tournament['name']); ?> - <?php echo count($players); ?> players</p>
            </div>

            <div id="bracketEditorContainer"></div>
        <?php endif; ?>
    </div>

    <?php require_once(__DIR__ . '/../includes/footer.php'); ?>

    <script src="/assets/js/bracket_editor.js"></script>
    <script nonce="<?= getCspNonce() ?>">
        document.body.dataset.tournamentId = <?php echo json_encode($tournament_id); ?>;
    </script>
</body>
</html>
