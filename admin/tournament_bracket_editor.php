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
         SELECT tp.id, tp.player_id, tp.seed,
             COALESCE(u.full_name, u.username) AS name,
             COALESCE(u.avatar_url, u.avatar_path, u.avatar) AS avatar_url
        FROM tournament_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.tournament_id = ?
        ORDER BY tp.seed ASC
    ");
    $stmt->execute([$tournament_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Bracket Editor - ' . ($tournament['name'] ?? 'Tournament');
require_once(__DIR__ . '/../includes/header.php');
?>
<style nonce="<?= getCspNonce() ?>">
    /* Seed-editor widget. Class/id names below are also referenced directly by
       assets/js/bracket_editor.js — keep them as-is if you touch this block. */
    .bracket-editor-container {
        max-width: 900px;
        margin: 0 auto;
        padding: var(--space-lg);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
    }

    .editor-header {
        margin-bottom: var(--space-lg);
        border-bottom: 1px solid var(--border);
        padding-bottom: var(--space-md);
    }

    .editor-header h2 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 26px;
        letter-spacing: 0.5px;
        margin: 0 0 4px;
        color: var(--text);
    }

    .editor-header p {
        margin: 0;
        color: var(--muted);
        font-size: 14px;
    }

    .editor-seeds-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 10px;
        margin-bottom: var(--space-lg);
        min-height: 200px;
        padding: 10px;
        background: var(--bg);
        border-radius: var(--radius-sm);
        border: 2px dashed var(--border);
    }

    .seed-card {
        background: var(--surface2);
        border: 1px solid var(--border);
        color: var(--text);
        padding: 12px;
        border-radius: var(--radius-sm);
        cursor: move;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        user-select: none;
    }

    .seed-card:hover {
        box-shadow: var(--shadow-sm);
        border-color: var(--accent2);
        background: rgba(0, 170, 255, 0.08);
    }

    .seed-card.dragging {
        opacity: 0.5;
        transform: scale(0.95);
    }

    .seed-card.drag-over {
        background: rgba(0, 170, 255, 0.14);
        border: 2px solid var(--accent2);
    }

    .seed-number {
        background: var(--accent2);
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
        color: var(--text);
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
        color: var(--muted);
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
        margin-bottom: var(--space-md);
    }

    .editor-btn {
        flex: 1;
        min-width: 150px;
        padding: 10px 15px;
        border: 1.5px solid transparent;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 14px;
        min-height: 40px;
    }

    .editor-btn-primary {
        background: var(--accent2);
        color: #fff;
    }

    .editor-btn-primary:hover {
        filter: brightness(1.1);
    }

    .editor-btn-secondary {
        background: var(--surface2);
        color: var(--text);
        border-color: var(--border);
    }

    .editor-btn-secondary:hover {
        background: var(--surface3);
        border-color: var(--border-soft);
    }

    .editor-btn-success {
        background: var(--success);
        color: #fff;
    }

    .editor-btn-success:hover {
        background: #059669;
    }

    .editor-btn-danger {
        background: var(--danger);
        color: #fff;
    }

    .editor-btn-danger:hover {
        background: #dc2626;
    }

    .editor-feedback {
        display: none;
        padding: 12px;
        border-radius: var(--radius-sm);
        margin-bottom: 15px;
        font-weight: 500;
        border: 1px solid transparent;
    }

    .editor-feedback-success {
        background: rgba(16, 185, 129, 0.15);
        color: #6ee7b7;
        border-color: rgba(16, 185, 129, 0.3);
    }

    .editor-feedback-error {
        background: rgba(239, 68, 68, 0.15);
        color: #fca5a5;
        border-color: rgba(239, 68, 68, 0.3);
    }

    .editor-feedback-info {
        background: rgba(0, 170, 255, 0.15);
        color: #7dd3fc;
        border-color: rgba(0, 170, 255, 0.3);
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

<?php if ($error): ?>
    <div class="alert alert-error"><span class="alert-icon">⚠️</span><div class="alert-content"><?= clean($error) ?></div></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><span class="alert-icon">✅</span><div class="alert-content"><?= clean($success) ?></div></div>
<?php endif; ?>

<?php if (!$error): ?>
    <div class="bracket-editor-container">
        <div class="editor-header">
            <h2>Edit Bracket Seeds</h2>
            <p><?= clean($tournament['name']) ?> · <?= count($players) ?> players · drag cards to reorder, or use the buttons below</p>
        </div>

        <div id="bracketEditorContainer"></div>
    </div>
<?php endif; ?>

<script src="<?= APP_URL ?>/assets/js/bracket_editor.js"></script>
<script nonce="<?= getCspNonce() ?>">
    document.body.dataset.tournamentId = <?php echo json_encode($tournament_id); ?>;
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>