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
    ],

    // Alias used in display/label contexts
    'bracket_labels' => [
        'single_elimination' => 'Single Elimination',
        'round_robin'        => 'Round Robin',
        'double_elimination' => 'Double Elimination',
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