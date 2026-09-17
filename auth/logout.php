<?php
// ============================================================
//  FILE: auth/logout.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isLoggedIn()) {
    redirect('auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['force']) && $_GET['force'] === '1') {
    _destroyAndRedirect("⏱ You were logged out due to inactivity.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    _destroyAndRedirect("👋 You've been logged out. See you next time!");
}

$user      = currentUser();
$pageTitle = 'Logout';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Full-viewport centering, safe-area aware ───────────────── */
.auth-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: calc(100dvh - var(--navbar-h, 60px));
    padding: clamp(16px, 4vw, 48px) clamp(12px, 4vw, 24px);
    padding-bottom: max(clamp(16px, 4vw, 48px), env(safe-area-inset-bottom, 16px));
    box-sizing: border-box;
}

/* ── Logout card ────────────────────────────────────────────── */
.logout-card {
    width: 100%;
    max-width: 400px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: clamp(28px, 6vw, 48px) clamp(24px, 6vw, 40px);
    box-sizing: border-box;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    text-align: center;
}

.logout-emoji {
    font-size: clamp(40px, 10vw, 56px);
    margin-bottom: 14px;
    display: block;
    line-height: 1;
}

.logout-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(20px, 5vw, 28px);
    letter-spacing: 1px;
    margin-bottom: 10px;
    line-height: 1.2;
}

.logout-body {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 28px;
    line-height: 1.6;
}

.logout-body strong {
    color: var(--text);
}

/* ── Action buttons ─────────────────────────────────────────── */
.logout-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.logout-actions .btn-danger,
.logout-actions .btn-outline {
    flex: 1;
    min-width: 110px;
    max-width: 180px;
    padding: 12px 16px;
    font-size: 14px;
    border-radius: 10px;
    text-align: center;
    white-space: nowrap;
}

/* ── Mobile ─────────────────────────────────────────────────── */
@media (max-width: 400px) {
    .logout-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .logout-actions .btn-danger,
    .logout-actions .btn-outline {
        max-width: 100%;
    }
}
</style>

<div class="auth-page">
    <div class="logout-card">

        <span class="logout-emoji">👋</span>

        <div class="logout-title">Logging Out</div>

        <div class="logout-body">
            Are you sure you want to log out,&nbsp;
            <strong><?= clean($user['full_name'] ?? $user['username'] ?? 'Player') ?></strong>?
        </div>

        <form method="POST" class="logout-actions">
            <?= csrfField() ?>
            <button type="submit" class="btn-danger">
                ✓ Yes, Log Out
            </button>
            <a href="javascript:history.back()" class="btn-outline">
                Cancel
            </a>
        </form>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
function _destroyAndRedirect(string $message): never {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
    setFlash('success', $message);
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}
?>