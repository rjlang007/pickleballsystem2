<?php
/**
 * Verify the production schema required by Open Play.
 * Run inside Railway from /app after migrations:
 *   php scripts/verify_open_play_schema.php
 */
require_once __DIR__ . '/../config/db.php';
$db = getDB();

$checks = [
    "SELECT to_regclass('falcon.tournaments') IS NOT NULL",
    "SELECT to_regclass('falcon.tournament_players') IS NOT NULL",
    "SELECT to_regclass('falcon.open_play_matches') IS NOT NULL",
    "SELECT to_regclass('falcon.open_play_match_checkins') IS NOT NULL",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'tournament_players' AND column_name = 'arrival_at')",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'tournament_players' AND column_name = 'queued_at')",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'tournament_players' AND column_name = 'arrived_at')",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'tournament_players' AND column_name = 'queue_status')",
    "SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'falcon.tournament_players'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) ILIKE '%pending_approval%')",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'users' AND column_name = 'totp_secret')",
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'topup_requests' AND column_name = 'paymongo_payment_id')",
];

foreach ($checks as $index => $sql) {
    if (!$db->query($sql)->fetchColumn()) {
        fwrite(STDERR, "FAIL: schema check " . ($index + 1) . "\n");
        exit(1);
    }
}

echo "Open Play schema checks passed.\n";
