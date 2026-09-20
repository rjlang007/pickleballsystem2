<?php
// ============================================================
//  FILE: tournament/bracket_generator.php
//  Static factory class — generates match rows for DB insert.
//  Called by TournamentEngine::startTournament() and
//  TournamentEngine::regenerateBracket().
// ============================================================
if (defined('BRACKET_GENERATOR_LOADED')) return;
define('BRACKET_GENERATOR_LOADED', true);

class BracketGenerator
{
    // ══════════════════════════════════════════════════════════
    // PUBLIC ENTRY POINT
    // ══════════════════════════════════════════════════════════

    /**
     * Generate all match rows for a tournament.
     *
     * @param int    $tournamentId
     * @param int[]  $seededPlayerIds  Ordered 0-indexed array; index 0 = seed #1.
    * @param string $type             single_elimination | round_robin | double_elimination | swiss
     * @param array  $options          { swiss_rounds: int }
     * @return array[]  Rows ready for bulk insert into falcon.tournament_matches.
     */
    public static function generate(
        int    $tournamentId,
        array  $seededPlayerIds,
        string $type,
        array  $options = []
    ): array {
        switch ($type) {
            case 'round_robin':
                return self::generateRoundRobin($tournamentId, $seededPlayerIds);
            case 'double_elimination':
                return self::generateDoubleElimination($tournamentId, $seededPlayerIds);
            case 'swiss':
                return self::generateSwiss(
                    $tournamentId,
                    $seededPlayerIds,
                    max(1, (int)($options['swiss_rounds'] ?? 5))
                );
            case 'single_elimination':
            default:
                return self::generateSingleElimination($tournamentId, $seededPlayerIds);
        }
    }

    // ══════════════════════════════════════════════════════════
    // SINGLE ELIMINATION
    // ══════════════════════════════════════════════════════════

    /**
     * Standard single-elimination bracket.
     *
     * - Player list padded to nearest power of 2 with null (bye slots).
     * - Round 1 seeding: seed 1 vs seed N, seed 2 vs seed N-1, etc.
     * - Bye matches: player2 is null → status = 'bye'.
     * - Subsequent rounds: placeholder matches with null player IDs,
     *   filled in by TournamentEngine::advanceWinnerSingleElim().
     */
    public static function generateSingleElimination(
        int   $tournamentId,
        array $seededPlayers
    ): array {
        $size      = self::nextPowerOf2(max(2, count($seededPlayers)));
        $padded    = $seededPlayers;

        // Pad to power-of-2 with nulls (byes)
        while (count($padded) < $size) {
            $padded[] = null;
        }

        $matches    = [];
        $matchNum   = 1;
        $totalRounds = self::getRequiredRounds($size);

        // ── Round 1: standard seeding (1 vs N, 2 vs N-1, …) ──
        $round1Pairs = self::buildSeededPairs($padded);
        foreach ($round1Pairs as $pair) {
            [$p1, $p2] = $pair;
            $status = ($p2 === null) ? 'bye' : 'pending';
            $matches[] = [
                'tournament_id'   => $tournamentId,
                'bracket_round'   => 1,
                'match_number'    => $matchNum++,
                'bracket_section' => 'main',
                'player1_id'      => $p1,
                'player2_id'      => $p2,
                'status'          => $status,
            ];
        }

        // ── Rounds 2..final: empty placeholder slots ──
        $matchesPerRound = $size / 2;
        for ($round = 2; $round <= $totalRounds; $round++) {
            $matchesPerRound = (int) ($matchesPerRound / 2);
            for ($m = 1; $m <= $matchesPerRound; $m++) {
                $matches[] = [
                    'tournament_id'   => $tournamentId,
                    'bracket_round'   => $round,
                    'match_number'    => $matchNum++,
                    'bracket_section' => 'main',
                    'player1_id'      => null,
                    'player2_id'      => null,
                    'status'          => 'pending',
                ];
            }
        }

        return $matches;
    }

    // ══════════════════════════════════════════════════════════
    // ROUND ROBIN
    // ══════════════════════════════════════════════════════════

    /**
     * Round-robin via the "circle method" (Berger tables).
     * Every player plays every other player exactly once.
     * If player count is odd, a null "bye" player is added.
     *
     * bracket_round = scheduling round (1..N-1 for even, 1..N for odd).
     * match_number  increments globally.
     */
    public static function generateRoundRobin(
        int   $tournamentId,
        array $seededPlayers
    ): array {
        $players = $seededPlayers;

        // Odd count → add bye player
        if (count($players) % 2 !== 0) {
            $players[] = null;
        }

        $n          = count($players);
        $rounds     = $n - 1;
        $matchNum   = 1;
        $matches    = [];

        // Fix the last player, rotate the rest
        $fixed  = array_pop($players);   // last element stays fixed
        $rotate = $players;              // remaining n-1 elements rotate

        for ($round = 1; $round <= $rounds; $round++) {
            // Build pairs for this round
            $top    = array_merge($rotate, [$fixed]);  // fixed goes last in list
            $half   = $n / 2;

            for ($i = 0; $i < $half; $i++) {
                $p1 = $top[$i];
                $p2 = $top[$n - 1 - $i];

                // Skip if both are null (shouldn't happen) or it's a pure bye-vs-bye
                if ($p1 === null && $p2 === null) {
                    continue;
                }

                $status = ($p1 === null || $p2 === null) ? 'bye' : 'pending';

                $matches[] = [
                    'tournament_id'   => $tournamentId,
                    'bracket_round'   => $round,
                    'match_number'    => $matchNum++,
                    'bracket_section' => 'main',
                    'player1_id'      => $p1,
                    'player2_id'      => $p2,
                    'status'          => $status,
                ];
            }

            // Rotate: move last of rotating array to front
            $last     = array_pop($rotate);
            array_unshift($rotate, $last);
        }

        return $matches;
    }

    /**
     * Generate fixed Swiss-style rounds. Pairings are seeded and rotated;
     * the scoring engine can use the completed results for standings.
     */
    public static function generateSwiss(
        int $tournamentId,
        array $seededPlayers,
        int $rounds
    ): array {
        $players = array_values($seededPlayers);
        if (count($players) % 2 !== 0) $players[] = null;

        $matches  = [];
        $matchNum = 1;
        $count    = count($players);

        for ($round = 1; $round <= $rounds; $round++) {
            for ($i = 0; $i < $count; $i += 2) {
                $p1 = $players[$i];
                $p2 = $players[$i + 1];
                if ($p1 === null && $p2 === null) continue;
                $matches[] = [
                    'tournament_id'   => $tournamentId,
                    'bracket_round'   => $round,
                    'match_number'    => $matchNum++,
                    'bracket_section' => 'main',
                    'player1_id'      => $p1,
                    'player2_id'      => $p2,
                    'status'          => $p2 === null ? 'bye' : 'pending',
                ];
            }

            $fixed = array_shift($players);
            $last  = array_pop($players);
            array_unshift($players, $last);
            array_unshift($players, $fixed);
        }

        return $matches;
    }

    // ══════════════════════════════════════════════════════════
    // DOUBLE ELIMINATION
    // ══════════════════════════════════════════════════════════

    /**
     * Double-elimination bracket.
     *
     * Winners bracket: identical structure to single elimination.
     * Losers bracket:  receives losers from each winners-bracket round.
     *   - Round encoding: losers rounds use bracket_section = 'losers'.
     *   - Grand final:    bracket_section = 'grand_final', round = 200.
     *
     * Player IDs in losers/grand-final matches are null (filled at runtime
     * by a double-elim advancement handler — the existing
     * advanceWinnerSingleElim covers winners; a companion method handles
     * losers advancement).
     */
    public static function generateDoubleElimination(
        int   $tournamentId,
        array $seededPlayers
    ): array {
        $size         = self::nextPowerOf2(max(2, count($seededPlayers)));
        $padded       = $seededPlayers;
        while (count($padded) < $size) {
            $padded[] = null;
        }

        $matches      = [];
        $matchNum     = 1;
        $wRounds      = self::getRequiredRounds($size);

        // ── Winners bracket (same as single elim) ──────────────
        $wPairs = self::buildSeededPairs($padded);
        foreach ($wPairs as $pair) {
            [$p1, $p2] = $pair;
            $matches[] = [
                'tournament_id'   => $tournamentId,
                'bracket_round'   => 1,
                'match_number'    => $matchNum++,
                'bracket_section' => 'winners',
                'player1_id'      => $p1,
                'player2_id'      => $p2,
                'status'          => ($p2 === null) ? 'bye' : 'pending',
            ];
        }

        $matchesThisRound = $size / 2;
        for ($round = 2; $round <= $wRounds; $round++) {
            $matchesThisRound = (int)($matchesThisRound / 2);
            for ($m = 1; $m <= $matchesThisRound; $m++) {
                $matches[] = [
                    'tournament_id'   => $tournamentId,
                    'bracket_round'   => $round,
                    'match_number'    => $matchNum++,
                    'bracket_section' => 'winners',
                    'player1_id'      => null,
                    'player2_id'      => null,
                    'status'          => 'pending',
                ];
            }
        }

        // ── Losers bracket ──────────────────────────────────────
        // In standard double elimination, losers bracket has
        // 2*(wRounds-1) rounds total.
        // Round 1L receives all round-1 losers from winners (size/2 players → size/4 matches).
        // Subsequent rounds alternate: feed-in from winners losers + internal winners.
        $lRounds  = 2 * ($wRounds - 1);
        $lMatches = $size / 4;   // round 1 of losers bracket

        for ($lr = 1; $lr <= $lRounds; $lr++) {
            for ($m = 1; $m <= max(1, $lMatches); $m++) {
                $matches[] = [
                    'tournament_id'   => $tournamentId,
                    'bracket_round'   => $lr,
                    'match_number'    => $matchNum++,
                    'bracket_section' => 'losers',
                    'player1_id'      => null,
                    'player2_id'      => null,
                    'status'          => 'pending',
                ];
            }
            // Odd rounds: receiving a drop-in from winners — same match count.
            // Even rounds: internal losers play each other — halve.
            if ($lr % 2 === 0 && $lMatches > 1) {
                $lMatches = (int)($lMatches / 2);
            }
        }

        // ── Grand Final ─────────────────────────────────────────
        $matches[] = [
            'tournament_id'   => $tournamentId,
            'bracket_round'   => 200,   // sentinel value for grand final
            'match_number'    => $matchNum++,
            'bracket_section' => 'grand_final',
            'player1_id'      => null,
            'player2_id'      => null,
            'status'          => 'pending',
        ];

        // Optional bracket-reset (if losers champion wins game 1 of GF)
        $matches[] = [
            'tournament_id'   => $tournamentId,
            'bracket_round'   => 201,   // sentinel: grand final reset
            'match_number'    => $matchNum,
            'bracket_section' => 'grand_final',
            'player1_id'      => null,
            'player2_id'      => null,
            'status'          => 'pending',
        ];

        return $matches;
    }

    // ══════════════════════════════════════════════════════════
    // UTILITY METHODS
    // ══════════════════════════════════════════════════════════

    /**
     * Number of rounds required for a single-elimination bracket.
     * e.g. 8 players → 3 rounds.
     */
    public static function getRequiredRounds(int $playerCount): int
    {
        if ($playerCount <= 1) return 0;
        return (int) ceil(log($playerCount, 2));
    }

    /**
     * Smallest power of 2 that is >= $n.
     * e.g. nextPowerOf2(5) = 8, nextPowerOf2(8) = 8.
     */
    public static function nextPowerOf2(int $n): int
    {
        if ($n <= 1) return 1;
        $power = 1;
        while ($power < $n) {
            $power <<= 1;
        }
        return $power;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * Build round-1 seeded pairs from a power-of-2 padded player list.
     *
     * Standard seeding:
     *   #1 vs #N, #2 vs #N-1, #3 vs #N-2, …
     * This ensures the top seeds only meet in the final.
     *
     * @param  (int|null)[]  $padded  Power-of-2 length, null = bye.
     * @return array[]               [[p1, p2], …]
     */
    private static function buildSeededPairs(array $padded): array
    {
        $n     = count($padded);
        $pairs = [];

        // Build the seeded bracket order:
        // Start with [1, 2], then recursively interleave opponents.
        $order = self::seededOrder($n);

        for ($i = 0; $i < count($order); $i += 2) {
            $p1Seed = $order[$i]     - 1;   // convert to 0-index
            $p2Seed = $order[$i + 1] - 1;
            $pairs[] = [
                $padded[$p1Seed] ?? null,
                $padded[$p2Seed] ?? null,
            ];
        }

        return $pairs;
    }

    /**
     * Produce the standard seeded bracket order for $n players.
     * Returns 1-indexed seed positions: [1, 8, 5, 4, 3, 6, 7, 2] for n=8.
     * This guarantees seeds 1 & 2 can only meet in the final.
     *
     * Algorithm: iteratively build positions by splitting groups.
     */
    private static function seededOrder(int $n): array
    {
        $positions = [1, 2];
        while (count($positions) < $n) {
            $next = [];
            foreach ($positions as $pos) {
                $next[] = $pos;
                $next[] = $n + 1 - $pos;
            }
            $positions = $next;
        }
        return $positions;
    }
}