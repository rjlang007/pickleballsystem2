<?php
/**
 * scripts/verify_open_play_schema.php
 *
 * Fallback check invoked by start.sh only if run_migrations.php reports
 * failure after all retries. This file was referenced but did not exist,
 * meaning a failed migration attempt would always fall through to
 * "refusing to start" even when the schema was actually fine.
 *
 * This does a lightweight, read-only check that the tables the app
 * depends on for its core flows (auth, courts/booking, wallet,
 * tournaments/open play, leaderboard) are present. It intentionally does
 * not try to validate every column — config/db.php already self-heals
 * many columns on each request via its ensure*() helpers. This script
 * just answers: "is the schema usable enough to boot?"
 *
 * Exit 0  -> schema looks usable, safe to continue starting the app.
 * Exit 1  -> a required table is missing, refuse to start.
 */

require_once __DIR__ . '/../config/db.php';

$requiredTables = [
    'users',
    'courts',
    'reservations',
    'transactions',
    'tournaments',
    'leaderboard',
    'game_sessions',
];

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = 'falcon' AND table_name = ANY(?)"
    );
    $stmt->execute(['{' . implode(',', $requiredTables) . '}']);
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    fwrite(STDERR, "[verify] Could not query schema: " . $e->getMessage() . "\n");
    exit(1);
}

$missing = array_diff($requiredTables, $found);

if (!empty($missing)) {
    fwrite(STDERR, "[verify] Missing required tables: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "[verify] All required tables present.\n";
exit(0);
