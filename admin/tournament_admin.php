<?php
// ============================================================
//  FILE: admin/tournament_admin.php
// ============================================================
// SECURITY FIX: this file used to bootstrap itself with a bare
// session_start() + hand-rolled role check instead of the shared
// config/app.php pipeline. That meant:
//   • it used PHP's default file-based session store under the default
//     PHPSESSID cookie name, instead of the app's DB-backed session
//     (config/session.php, cookie name FALCON_SESS) — a different store
//     than every other page, so $_SESSION here wasn't reliably the same
//     session a user is logged in with elsewhere in the app.
//   • it skipped checkSessionTimeout()/session-fingerprint validation,
//     the CSP/HSTS security headers, and — most importantly — csrfToken()/
//     verifyCsrf() were never loaded, so every form on this page posted
//     with no CSRF protection at all.
// Now uses the same bootstrap and requireAdmin() as the rest of the app.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

requireAdmin();

$engine  = new TournamentEngine();
$config  = require __DIR__ . '/../config/tournament_config.php';
$adminId = (int) $_SESSION['user_id'];
$error   = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!checkRateLimit('tournament_admin_' . $adminId, 30, 60)) {
        $error = 'Too many requests. Please slow down.';
    } else {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                $engine->createTournament($_POST, $adminId);
                $success = 'Tournament created successfully.';
                break;
            case 'open_registration':
                $engine->openRegistration((int)$_POST['tournament_id']);
                $success = 'Registration opened.';
                break;
            case 'start':
                $engine->startTournament((int)$_POST['tournament_id']);
                $success = 'Tournament started! Bracket generated automatically.';
                break;
            case 'regenerate_bracket':
                // Only allowed when in_progress (wipes and rebuilds)
                $engine->regenerateBracket((int)$_POST['tournament_id']);
                $success = 'Bracket regenerated successfully.';
                break;
            case 'complete':
                $engine->completeTournament((int)$_POST['tournament_id'], $adminId);
                $success = 'Tournament completed. Leaderboard updated.';
                break;
            case 'cancel':
                $engine->cancelTournament((int)$_POST['tournament_id']);
                $success = 'Tournament cancelled.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    }
}

$tournaments = $engine->listTournaments();

// Separate active tournaments needing bracket attention
$bracketTournaments = array_filter($tournaments, fn($t) =>
    in_array($t['status'], ['registration_open', 'in_progress'], true)
);

$statusLabels = [
    'draft'             => ['label' => 'Draft',        'class' => 'badge-secondary'],
    'registration_open' => ['label' => 'Registration', 'class' => 'badge-info'],
    'in_progress'       => ['label' => 'In Progress',  'class' => 'badge-warning'],
    'completed'         => ['label' => 'Completed',    'class' => 'badge-success'],
    'cancelled'         => ['label' => 'Cancelled',    'class' => 'badge-danger'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Admin — Pickleball</title>
    <link rel="stylesheet" href="/pickleball/assets/css/tournament.css">
    <link rel="stylesheet" href="/pickleball/assets/css/leaderboard.css">
    <style nonce="<?= getCspNonce() ?>">
        /* ── Bracket Management Panel ── */
        .bracket-mgmt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .bracket-mgmt-card {
            background: var(--surface, #f9f9f9);
            border: 1px solid var(--border, #ddd);
            border-radius: 12px;
            padding: 18px 20px;
        }
        .bracket-mgmt-card h3 {
            margin: 0 0 4px;
            font-size: 15px;
        }
        .bracket-mgmt-meta {
            font-size: 12px;
            color: #888;
            margin-bottom: 14px;
        }
        .bracket-mgmt-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .bracket-info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            margin-bottom: 10px;
            padding: 8px 10px;
            background: rgba(0,0,0,0.03);
            border-radius: 8px;
        }
        .bracket-info-row .bi-label { color: #888; min-width: 80px; }
        .bracket-seeding-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(0,180,120,0.1);
            color: #0a7a50;
            border: 1px solid rgba(0,180,120,0.25);
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 10px;
        }
        .section-note {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
            font-style: italic;
        }
    </style>
</head>
<body class="admin-body">
<div class="admin-wrap">

    <header class="admin-header">
        <h1>🏆 Tournament Admin</h1>
        <a href="/pickleball/admin/tournament_scoring.php" class="btn btn-secondary">Score Entry →</a>
    </header>

    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- ── Create Tournament ───────────────────────────────── -->
    <section class="admin-card">
        <h2>Create New Tournament</h2>
        <form method="POST" class="tournament-form">
            <input type="hidden" name="action" value="create">
            <?= csrfField() ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Tournament Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Summer Open 2026">
                </div>
                <div class="form-group">
                    <label>Bracket Type *</label>
                    <select name="bracket_type" required>
                        <?php foreach ($config['bracket_types'] as $val => $desc): ?>
                        <option value="<?= $val ?>"><?= htmlspecialchars($desc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Max Players *</label>
                    <select name="max_players" required>
                        <?php foreach ($config['supported_player_counts'] as $n): ?>
                        <option value="<?= $n ?>"><?= $n ?> Players</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="datetime-local" name="start_date">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="datetime-local" name="end_date">
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" placeholder="Optional tournament details..."></textarea>
            </div>

            <div class="form-group form-check">
                <label><input type="checkbox" name="featured"> Feature on homepage</label>
            </div>

            <button type="submit" class="btn btn-primary">Create Tournament</button>
        </form>
    </section>

    <!-- ══════════════════════════════════════════════════════
         BRACKET MANAGEMENT (Admin only)
         Visible for tournaments in registration_open or in_progress
    ══════════════════════════════════════════════════════ -->
    <section class="admin-card" id="bracket-mgmt-section">
        <h2>🎯 Bracket Management</h2>
        <p class="section-note">
            Brackets are auto-generated when you click <strong>▶ Start</strong>.
            Players are seeded by season leaderboard rank (highest points = seed #1).
            You can adjust seeds before starting, or edit seeds while in progress via the editor.
        </p>

        <?php if (empty($bracketTournaments)): ?>
            <p class="empty-state" style="margin-top:12px;">
                No tournaments in Registration or In Progress state.
            </p>
        <?php else: ?>
        <div class="bracket-mgmt-grid">
            <?php foreach ($bracketTournaments as $t):
                $sl          = $statusLabels[$t['status']];
                $bracketType = str_replace('_', ' ', ucfirst($t['bracket_type']));
                $playerCount = (int)$t['current_players'];
                $maxPlayers  = (int)$t['max_players'];
            ?>
            <div class="bracket-mgmt-card">
                <h3><?= htmlspecialchars($t['name']) ?></h3>
                <div class="bracket-mgmt-meta">
                    <span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span>
                    &nbsp;<?= $bracketType ?>
                    &nbsp;·&nbsp;<?= $playerCount ?>/<?= $maxPlayers ?> players
                    <?php if ($t['start_date']): ?>
                        &nbsp;·&nbsp;<?= date('M d, Y', strtotime($t['start_date'])) ?>
                    <?php endif; ?>
                </div>

                <!-- Seeding info -->
                <div class="bracket-info-row">
                    <span class="bi-label">Seeding</span>
                    <span class="bracket-seeding-badge">📊 Auto — Leaderboard Rank</span>
                </div>

                <div class="bracket-info-row">
                    <span class="bi-label">Bracket</span>
                    <span><?= $bracketType ?></span>
                </div>

                <div class="bracket-info-row">
                    <span class="bi-label">Players</span>
                    <span><?= $playerCount ?> registered
                        <?php if ($t['status'] === 'registration_open'): ?>
                            <em style="color:#888;">(<?= $maxPlayers - $playerCount ?> slots left)</em>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="bracket-mgmt-actions">

                    <!-- Phase 11B: "👁 View Bracket" now points to bracket_viewer.php -->
                    <a href="<?= APP_URL ?>/public/bracket_viewer.php?id=<?= $t['id'] ?>"
                       class="btn btn-xs btn-outline" target="_blank">
                        👁 View Bracket
                    </a>

                    <?php if ($t['status'] === 'registration_open'): ?>

                        <!-- Seed editor — adjust seeds BEFORE generating bracket -->
                        <a href="/pickleball/admin/tournament_bracket_editor.php?id=<?= $t['id'] ?>"
                           class="btn btn-xs btn-secondary">
                            ✏️ Edit Seeds
                        </a>

                        <!-- Generate bracket = start tournament -->
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Generate bracket and start tournament?\n\nPlayers will be seeded by leaderboard rank. You can edit seeds first.')">
                            <input type="hidden" name="action" value="start">
                            <?= csrfField() ?>
                            <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                            <button class="btn btn-xs btn-success">
                                ⚡ Auto-Generate Bracket
                            </button>
                        </form>

                    <?php elseif ($t['status'] === 'in_progress'): ?>

                        <!-- Score entry -->
                        <a href="/pickleball/admin/tournament_scoring.php?id=<?= $t['id'] ?>"
                           class="btn btn-xs btn-warning">
                            📝 Score Entry
                        </a>

                        <!-- Seed editor (re-seed while in progress, for swiss/RR) -->
                        <?php if (in_array($t['bracket_type'], ['swiss', 'round_robin'], true)): ?>
                        <a href="/pickleball/admin/tournament_bracket_editor.php?id=<?= $t['id'] ?>"
                           class="btn btn-xs btn-secondary">
                            ✏️ Edit Seeds
                        </a>
                        <?php endif; ?>

                        <!-- Regenerate bracket (danger action) -->
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('⚠️ WARNING: This will DELETE all existing matches and rebuild the bracket.\n\nAll recorded scores will be lost. Are you sure?')">
                            <input type="hidden" name="action" value="regenerate_bracket">
                            <?= csrfField() ?>
                            <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                            <button class="btn btn-xs btn-danger">
                                🔄 Regenerate Bracket
                            </button>
                        </form>

                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ── Tournament List ─────────────────────────────────── -->
    <section class="admin-card">
        <h2>All Tournaments</h2>
        <?php if (empty($tournaments)): ?>
            <p class="empty-state">No tournaments yet. Create one above.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Players</th>
                    <th>Status</th>
                    <th>Start</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tournaments as $t):
                $sl = $statusLabels[$t['status']] ?? ['label' => $t['status'], 'class' => 'badge-secondary'];
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($t['name']) ?></strong>
                    <?php if ($t['featured']): ?><span class="badge badge-info">⭐ Featured</span><?php endif; ?>
                </td>
                <td><?= str_replace('_', ' ', $t['bracket_type']) ?></td>
                <td><?= $t['current_players'] ?> / <?= $t['max_players'] ?></td>
                <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
                <td><?= $t['start_date'] ? date('M d, Y', strtotime($t['start_date'])) : '—' ?></td>
                <td class="action-cell">
                    <!-- "View" in the table keeps pointing to tournament_details.php (per Phase 11B) -->
                    <a href="<?= APP_URL ?>/public/tournament_details.php?id=<?= $t['id'] ?>"
                       class="btn btn-xs btn-outline">View</a>

                    <?php if ($t['status'] === 'draft'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="open_registration">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn btn-xs btn-info">Open Registration</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($t['status'] === 'registration_open'): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Start tournament and auto-generate bracket?')">
                        <input type="hidden" name="action" value="start">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn btn-xs btn-success">▶ Start</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($t['status'] === 'in_progress'): ?>
                    <a href="/pickleball/admin/tournament_scoring.php?id=<?= $t['id'] ?>"
                       class="btn btn-xs btn-warning">📝 Score Entry</a>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Complete tournament and update leaderboard?')">
                        <input type="hidden" name="action" value="complete">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn btn-xs btn-success">✓ Complete</button>
                    </form>
                    <?php endif; ?>

                    <?php if (!in_array($t['status'], ['completed', 'cancelled'])): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Cancel this tournament?')">
                        <input type="hidden" name="action" value="cancel">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn btn-xs btn-danger">✕ Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

</div>
</body>
</html>