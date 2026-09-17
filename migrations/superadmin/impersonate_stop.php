<?php
// ============================================================
//  FILE: superadmin/impersonate_stop.php
//  Ends impersonation and restores super admin session
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

if (!isImpersonating()) {
    redirect('superadmin/dashboard.php');
}

// Update impersonation log with end time
if (!empty($_SESSION['_imp_log_id'])) {
    $db = getDB();
    $db->prepare("UPDATE falcon.impersonation_logs SET ended_at = NOW() WHERE id = ?")
       ->execute([$_SESSION['_imp_log_id']]);
}

// Restore real super admin identity
$_SESSION['user_id']   = $_SESSION['_real_user_id'];
$_SESSION['username']  = $_SESSION['_real_username'];
$_SESSION['full_name'] = $_SESSION['_real_full_name'];
$_SESSION['role']      = $_SESSION['_real_role'];
$_SESSION['avatar']    = $_SESSION['_real_avatar'];

// Clean up impersonation keys
unset(
    $_SESSION['_real_user_id'],
    $_SESSION['_real_username'],
    $_SESSION['_real_full_name'],
    $_SESSION['_real_role'],
    $_SESSION['_real_avatar'],
    $_SESSION['_imp_log_id']
);

setFlash('success', '✅ Returned to your Super Admin account.');
redirect('superadmin/dashboard.php');