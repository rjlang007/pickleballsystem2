<?php
// ============================================================
//  FILE: admin/tournament_edit.php
//  Edit tournament details (name, dates, settings, etc.)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

requireAdmin();

$engine  = new TournamentEngine();
$config  = require __DIR__ . '/../config/tournament_config.php';
$adminId = (int) $_SESSION['user_id'];
$error   = '';
$success = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    setFlash('error', 'Tournament ID required.');
    redirect('admin/tournament_admin.php');
}

$tournament = $engine->getTournament($id);
if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    redirect('admin/tournament_admin.php');
}

// Handle POST updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $data = [
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'bracket_type' => $_POST['bracket_type'] ?? $tournament['bracket_type'],
            'max_players'  => (int)($_POST['max_players'] ?? $tournament['max_players']),
            'start_date'  => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
            'end_date'    => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
            'featured'    => !empty($_POST['featured']),
        ];

        // Only allow updates if tournament is in draft status
        if ($tournament['status'] !== 'draft') {
            throw new RuntimeException('Can only edit tournaments in draft status.');
        }

        $engine->updateTournament($id, $data, $adminId);
        $success = 'Tournament updated successfully.';
        $tournament = $engine->getTournament($id); // Refresh data
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Edit Tournament — ' . clean($tournament['name']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>Edit Tournament</h1>
      <p style="color:var(--color-text-2);">
        <strong>Status:</strong>
        <span class="badge badge-<?= $config['status_labels'][$tournament['status']]['badge'] ?? 'secondary' ?>">
          <?= $config['status_labels'][$tournament['status']]['label'] ?? $tournament['status'] ?>
        </span>
        <?php if ($tournament['status'] !== 'draft'): ?>
          <em style="color:var(--color-text-3);font-size:.9rem;">(Can only edit draft tournaments)</em>
        <?php endif; ?>
      </p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin:16px;"><?= clean($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success" style="margin:16px;"><?= clean($success) ?></div>
    <?php endif; ?>

    <form method="POST" style="padding:16px;">
            <?= csrfField() ?>
      <div style="display:grid;gap:20px;max-width:600px;">

        <div class="form-group">
          <label class="form-label">Tournament Name *</label>
          <input type="text" name="name" value="<?= clean($tournament['name']) ?>" class="form-input" required
                 <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" rows="3" class="form-input"
                    <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>><?= clean($tournament['description'] ?? '') ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Bracket Type</label>
            <select name="bracket_type" class="form-input"
                    <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
              <?php foreach ($config['bracket_types'] as $type => $label): ?>
                <option value="<?= $type ?>" <?= $tournament['bracket_type'] === $type ? 'selected' : '' ?>>
                  <?= clean($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Max Players</label>
            <select name="max_players" class="form-input"
                    <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
              <?php foreach ($config['supported_player_counts'] as $count): ?>
                <option value="<?= $count ?>" <?= (int)$tournament['max_players'] === $count ? 'selected' : '' ?>>
                  <?= $count ?> players
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Start Date</label>
            <input type="datetime-local" name="start_date"
                   value="<?= $tournament['start_date'] ? date('Y-m-d\TH:i', strtotime($tournament['start_date'])) : '' ?>"
                   class="form-input" <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
          </div>

          <div class="form-group">
            <label class="form-label">End Date</label>
            <input type="datetime-local" name="end_date"
                   value="<?= $tournament['end_date'] ? date('Y-m-d\TH:i', strtotime($tournament['end_date'])) : '' ?>"
                   class="form-input" <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
          </div>
        </div>

        <div class="form-group">
          <label class="form-check">
            <input type="checkbox" name="featured" value="1"
                   <?= !empty($tournament['featured']) ? 'checked' : '' ?>
                   <?= $tournament['status'] !== 'draft' ? 'disabled' : '' ?>>
            Featured Tournament
          </label>
        </div>

        <?php if ($tournament['status'] === 'draft'): ?>
          <div style="margin-top:24px;">
            <button type="submit" class="btn btn-primary">Update Tournament</button>
            <a href="admin/tournament_admin.php" class="btn btn-secondary" style="margin-left:12px;">Cancel</a>
          </div>
        <?php else: ?>
          <div style="margin-top:24px;">
            <a href="admin/tournament_admin.php" class="btn btn-primary">Back to Tournament Admin</a>
          </div>
        <?php endif; ?>

      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>