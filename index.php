<?php
// ============================================================
//  FILE: index.php (project root)
//  Purpose: the project has no separate marketing home page —
//  visiting the bare project URL should just land you on your
//  dashboard (or the login page if you're not signed in yet).
//  Previously this redirected to public/index.php, a leftover
//  duplicate folder that has since been removed; that file was
//  itself a copy of this one, which caused a redirect loop.
// ============================================================
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    redirect(roleDashboard());
}

redirect('auth/login.php');