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
    in_array($t['status'], ['draft', 'registration_open', 'in_progress'], true)
);

// NOTE: class names below match the app's real, shared design system
// (assets/css/app.css + assets/css/components.css, loaded via
// includes/header.php) instead of the old badge-secondary / badge-warning /
// admin-card / admin-table / etc. classes, which were never defined in any
// stylesheet this page loaded and rendered completely unstyled.
$statusLabels = [
    'draft'             => ['label' => 'Draft',        'class' => 'badge-muted'],
    'registration_open' => ['label' => 'Registration', 'class' => 'badge-info'],
    'in_progress'       => ['label' => 'In Progress',  'class' => 'badge-warning'],
    'completed'         => ['label' => 'Completed',    'class' => 'badge-success'],
    'cancelled'         => ['label' => 'Cancelled',    'class' => 'badge-error'],
];

$pageTitle = 'Tournament Admin — Pickleball';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    /* ── Bracket Management Panel ── */
    .bracket-mgmt-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: var(--space-md);
        margin-top: var(--space-md);
    }
    .bracket-mgmt-card {
        background: var(--surface2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 18px 20px;
        min-width: 0;
    }
    .bracket-mgmt-card h3 {
        margin: 0 0 4px;
        font-size: 15px;
        font-family: 'DM Sans', sans-serif;
        font-weight: 700;
        color: var(--text);
    }
    .bracket-mgmt-meta {
        font-size: 12px;
        color: var(--muted);
        margin-bottom: 14px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
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
        background: rgba(255,255,255,0.03);
        border-radius: var(--radius-sm);
        color: var(--text);
    }
    .bracket-info-row .bi-label { color: var(--muted); min-width: 80px; flex-shrink: 0; }
    .bracket-seeding-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: rgba(16,185,129,0.12);
        color: #6ee7b7;
        border: 1px solid rgba(16,185,129,0.3);
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 10px;
    }
    .section-note {
        font-size: 12px;
        color: var(--muted);
        margin-top: 6px;
    }
    #swissRoundsGroup.is-hidden { display: none; }
</style>

<!-- Page Header -->
<div class="page-header flex-between">
    <div>
        <h1>🏆 Tournament Admin</h1>
        <p>Create tournaments, manage brackets, and track results.</p>
    </div>
    <div>
        <a href="<?= appUrl('admin/tournament_scoring.php') ?>" class="btn-outline btn-sm">Score Entry →</a>
    </div>
</div>

<?php if ($error):   ?><div class="alert alert-error"><span class="alert-icon">⚠️</span><div class="alert-content"><?= clean($error) ?></div></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><span class="alert-icon">✅</span><div class="alert-content"><?= clean($success) ?></div></div><?php endif; ?>

<!-- ── Create Tournament ───────────────────────────────── -->
<div class="card mb-3">
    <div class="card-title mb-2">Create New Tournament</div>
    <hr class="divider" style="margin: 0 0 var(--space-lg);">

    <form method="POST">
        <input type="hidden" name="action" value="create">
        <?= csrfField() ?>

        <div class="form-row">
            <div class="form-group">
                <label>Tournament Name *</label>
                <input type="text" name="name" required placeholder="e.g. Summer Open 2026">
            </div>
            <div class="form-group">
                <label>Bracket Type *</label>
                <select name="bracket_type" id="bracketTypeSelect" required>
                    <?php foreach ($config['bracket_types'] as $val => $desc): ?>
                    <option value="<?= $val ?>"><?= clean($desc) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="section-note">Open Play is posted separately under Staff → Open Play Control.</p>
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
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>End Date</label>
                <input type="datetime-local" name="end_date">
            </div>
            <div class="form-group">
                <label>Registration Price (₱)</label>
                <input type="number" name="price" min="0" step="0.01" value="0">
                <p class="section-note">Set 0 for a free tournament.</p>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2" placeholder="Optional tournament details..."></textarea>
        </div>

        <div class="form-group" id="swissRoundsGroup">
            <label>Swiss Rounds</label>
            <input type="number" name="swiss_rounds" min="1" max="15" value="<?= (int)($config['swiss_default_rounds'] ?? 5) ?>">
            <p class="section-note">Used only when Swiss System is selected.</p>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;color:var(--text);">
                <input type="checkbox" name="featured" style="width:auto;min-height:0;"> Feature on homepage
            </label>
        </div>

        <button type="submit" class="btn-primary">Create Tournament</button>
    </form>
</div>

<!-- ══════════════════════════════════════════════════════
     BRACKET MANAGEMENT (Admin only)
     Visible for tournaments in registration_open or in_progress
══════════════════════════════════════════════════════ -->
<div class="card mb-3" id="bracket-mgmt-section">
    <div class="card-title mb-2">🎯 Bracket Management</div>
    <hr class="divider" style="margin: 0 0 var(--space-md);">
    <p class="section-note">
        Brackets are auto-generated when you click <strong>Auto-Generate Bracket</strong>.
        Players are seeded by season leaderboard rank (highest points = seed #1).
        You can adjust seeds before starting, or edit seeds while in progress via the editor.
    </p>

    <?php if (empty($bracketTournaments)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🎯</div>
            <p class="empty-state-message">No tournaments in Registration or In Progress state.</p>
        </div>
    <?php else: ?>
    <div class="bracket-mgmt-grid">
        <?php foreach ($bracketTournaments as $t):
            $sl          = $statusLabels[$t['status']];
            $bracketType = str_replace('_', ' ', ucfirst($t['bracket_type']));
            $playerCount = (int)$t['current_players'];
            $maxPlayers  = (int)$t['max_players'];
        ?>
        <div class="bracket-mgmt-card">
            <h3><?= clean($t['name']) ?></h3>
            <div class="bracket-mgmt-meta">
                <span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span>
                <span><?= $bracketType ?></span>
                <span>·</span>
                <span><?= $playerCount ?>/<?= $maxPlayers ?> players</span>
                <?php if ($t['start_date']): ?>
                    <span>·</span>
                    <span><?= date('M d, Y', strtotime($t['start_date'])) ?></span>
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
                        <em style="color:var(--muted);"> (<?= $maxPlayers - $playerCount ?> slots left)</em>
                    <?php endif; ?>
                </span>
            </div>

            <div class="bracket-mgmt-actions">

                <?php if (in_array($t['status'], ['draft', 'registration_open'], true)): ?>
                    <a href="<?= APP_URL ?>/admin/tournament_edit.php?id=<?= $t['id'] ?>"
                       class="btn-outline btn-xs">
                        ✏️ Edit Posting
                    </a>
                <?php endif; ?>

                <!-- Phase 11B: "👁 View Bracket" now points to bracket_viewer.php -->
                <a href="<?= APP_URL ?>/public/bracket_viewer.php?id=<?= $t['id'] ?>"
                   class="btn-ghost btn-xs" target="_blank">
                    👁 View Bracket
                </a>

                <?php if ($t['status'] === 'registration_open'): ?>

                    <!-- Seed editor — adjust seeds BEFORE generating bracket -->
                    <a href="<?= appUrl('admin/tournament_bracket_editor.php') ?>?id=<?= $t['id'] ?>"
                       class="btn-outline btn-xs">
                        ✏️ Edit Seeds
                    </a>

                    <!-- Generate bracket = start tournament -->
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Generate bracket and start tournament?\n\nPlayers will be seeded by leaderboard rank. You can edit seeds first.')">
                        <input type="hidden" name="action" value="start">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn-success btn-xs">
                            ⚡ Auto-Generate Bracket
                        </button>
                    </form>

                <?php elseif ($t['status'] === 'in_progress'): ?>

                    <!-- Score entry -->
                    <a href="<?= appUrl('admin/tournament_scoring.php') ?>?id=<?= $t['id'] ?>"
                       class="btn-warn btn-xs">
                        📝 Score Entry
                    </a>

                    <!-- Seed editor (re-seed while in progress, for swiss/RR) -->
                    <?php if (in_array($t['bracket_type'], ['swiss', 'round_robin'], true)): ?>
                    <a href="<?= appUrl('admin/tournament_bracket_editor.php') ?>?id=<?= $t['id'] ?>"
                       class="btn-outline btn-xs">
                        ✏️ Edit Seeds
                    </a>
                    <?php endif; ?>

                    <!-- Regenerate bracket (danger action) -->
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('⚠️ WARNING: This will DELETE all existing matches and rebuild the bracket.\n\nAll recorded scores will be lost. Are you sure?')">
                        <input type="hidden" name="action" value="regenerate_bracket">
                        <?= csrfField() ?>
                        <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                        <button class="btn-danger btn-xs">
                            🔄 Regenerate Bracket
                        </button>
                    </form>

                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Tournament List ─────────────────────────────────── -->
<div class="card">
    <div class="card-title mb-2">All Tournaments</div>
    <hr class="divider" style="margin: 0 0 var(--space-md);">
    <?php if (empty($tournaments)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏆</div>
            <p class="empty-state-message">No tournaments yet. Create one above.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
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
            $sl = $statusLabels[$t['status']] ?? ['label' => $t['status'], 'class' => 'badge-muted'];
        ?>
        <tr>
            <td>
                <strong><?= clean($t['name']) ?></strong>
                <?php if ($t['featured']): ?> <span class="badge badge-info">⭐ Featured</span><?php endif; ?>
            </td>
            <td><?= str_replace('_', ' ', $t['bracket_type']) ?></td>
            <td><?= $t['current_players'] ?> / <?= $t['max_players'] ?></td>
            <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
            <td><?= $t['start_date'] ? date('M d, Y', strtotime($t['start_date'])) : '—' ?></td>
            <td>
                <div class="btn-group">
                <!-- "View" in the table keeps pointing to tournament_details.php (per Phase 11B) -->
                <a href="<?= APP_URL ?>/public/tournament_details.php?id=<?= $t['id'] ?>"
                   class="btn-ghost btn-xs">View</a>

                <?php if ($t['status'] === 'draft'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="open_registration">
                    <?= csrfField() ?>
                    <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                    <button class="btn-outline btn-xs">Open Registration</button>
                </form>
                <?php endif; ?>

                <!-- Start / Score Entry are handled from the Bracket Management
                     panel above (with seeding context) — not duplicated here. -->

                <?php if ($t['status'] === 'in_progress'): ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Complete tournament and update leaderboard?')">
                    <input type="hidden" name="action" value="complete">
                    <?= csrfField() ?>
                    <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                    <button class="btn-success btn-xs">✓ Complete</button>
                </form>
                <?php endif; ?>

                <?php if (!in_array($t['status'], ['completed', 'cancelled'])): ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Cancel this tournament?')">
                    <input type="hidden" name="action" value="cancel">
                    <?= csrfField() ?>
                    <input type="hidden" name="tournament_id" value="<?= $t['id'] ?>">
                    <button class="btn-danger btn-xs">✕ Cancel</button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= getCspNonce() ?>">
// Progressive disclosure: only show "Swiss Rounds" when Swiss System is
// the selected bracket type — it has no effect on any other bracket type.
(function () {
    var select = document.getElementById('bracketTypeSelect');
    var group  = document.getElementById('swissRoundsGroup');
    if (!select || !group) return;
    function sync() {
        group.classList.toggle('is-hidden', select.value !== 'swiss');
    }
    select.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>