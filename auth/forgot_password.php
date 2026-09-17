<?php
// ============================================================
//  FILE: auth/forgot_password.php
// Email password resets are temporarily unavailable. Users must contact
// the court admin or operator to have their password reset.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

if (isLoggedIn()) { redirect(roleDashboard()); }

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <h1>Forgot Password</h1>
        <p class="subtitle">Email password reset is not available right now.</p>

        <div class="flash flash-success auth-alert">
            Email password reset is not available for now. Please contact the admin or the court operator to reset your password.
        </div>
        <div class="auth-footer" style="margin-top:16px;">
            <a href="<?= APP_URL ?>/auth/login.php">← Back to Login</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
