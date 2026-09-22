<?php
// ============================================================
//  FILE: config/tournament_config.php
//  Central configuration for all tournament-related behaviour.
//  Consumed by: TournamentEngine, ScoringEngine,
//               LeaderboardEngine, bracket_viewer.php,
//               public/tournaments.php, public/leaderboard.php
// ============================================================
return [

    // ── Bracket types ────────────────────────────────────────
    'bracket_types' => [
        'single_elimination' => 'Single Elimination',
        'round_robin'        => 'Round Robin',
        'double_elimination' => 'Double Elimination',
        'swiss'              => 'Swiss System',
    ],

    // Alias used in display/label contexts
    'bracket_labels' => [
        'single_elimination' => 'Single Elimination',
        'round_robin'        => 'Round Robin',
        'double_elimination' => 'Double Elimination',
        'swiss'              => 'Swiss System',
    ],

    'bracket_descriptions' => [
        'single_elimination' => 'Lose once and you are eliminated.',
        'double_elimination' => 'A second loss eliminates a player or team.',
        'round_robin'        => 'Every participant plays every other participant.',
        'swiss'              => 'Players compete for a fixed number of rounds without elimination.',
    ],

    // ── Player count constraints ─────────────────────────────
    'supported_player_counts'      => [4, 8, 16, 32, 64],
    'default_bracket'              => 'single_elimination',
    'min_players_for_tournament'   => 2,

    // ── Leaderboard ──────────────────────────────────────────
    'leaderboard_page_size' => 20,

    // ── Swiss (future) ───────────────────────────────────────
    'swiss_default_rounds' => 5,

    // ── Point distribution (placement → points) ─────────────
    // Used by ScoringEngine::getDefaultPointDistribution().
    // Override per-tournament via tournaments.settings->point_distribution.
    'point_distribution' => [
        1 => 100,
        2 => 75,
        3 => 50,
        4 => 30,
        5 => 20,
        6 => 15,
        7 => 10,
        8 => 5,
    ],

    // Points awarded to every participant regardless of placement
    'participation_points' => 5,

    // ── Open Play point distribution (season leaderboard) ────
    // Fixed scoring rule for Open Play events specifically:
    // only the top 3 finishers of each session earn leaderboard
    // points (1st = 3, 2nd = 2, 3rd = 1); everyone else earns 0.
    // This is intentionally separate from 'point_distribution'
    // above, which is only used for bracket-style tournaments.
    // Used by OpenPlayEngine::finalizeEvent() and is NOT
    // overridable per-event, so it stays consistent across every
    // Open Play session regardless of what's stored in an older
    // event's settings.
    'open_play_point_distribution' => [
        1 => 3,
        2 => 2,
        3 => 1,
    ],
    'open_play_participation_points' => 0,

    // ── Status labels + badge CSS classes ───────────────────
    // badge class values must match your CSS (badge-success, etc.)
    'status_labels' => [
        'draft' => [
            'label' => 'Draft',
            'badge' => 'badge-secondary',
        ],
        'registration_open' => [
            'label' => 'Registration Open',
            'badge' => 'badge-info',
        ],
        'registration_closed' => [
            'label' => 'Reg. Closed',
            'badge' => 'badge-warning',
        ],
        'in_progress' => [
            'label' => 'In Progress',
            'badge' => 'badge-warning',
        ],
        'completed' => [
            'label' => 'Completed',
            'badge' => 'badge-success',
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'badge' => 'badge-danger',
        ],
    ],

];