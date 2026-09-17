<?php
// ============================================================
//  FILE: tournament/scoring_engine.php
//  Derives placements from completed matches and writes
//  tournament_scores. Also provides manual override.
// ============================================================
if (defined('SCORING_ENGINE_LOADED')) return;
define('SCORING_ENGINE_LOADED', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tournament_config.php';

class ScoringEngine
{
    private PDO   $db;
    private array $config;

    public function __construct()
    {
        $this->db     = getDB();
        $cfg          = require __DIR__ . '/../config/tournament_config.php';
        $this->config = is_array($cfg) ? $cfg : [];
    }

    // ══════════════════════════════════════════════════════════
    // AUTO-PROCESS ALL PLACEMENTS FOR A TOURNAMENT
    // ══════════════════════════════════════════════════════════

    /**
     * Derive final standings from completed matches and upsert
     * rows into falcon.tournament_scores.
     *
     * Supports:
     *   - single_elimination  → placement by elimination order
     *   - double_elimination  → same, but losers bracket counts
     *   - round_robin         → rank by wins DESC, then total score DESC
     */
    public function processPlacements(int $tournamentId, int $adminId): void
    {
        $tournament = $this->getTournament($tournamentId);
        if (!$tournament) {
            throw new RuntimeException("Tournament {$tournamentId} not found.");
        }

        $type = $tournament['bracket_type'];
        $dist = $this->resolvePointDistribution($tournament);

        switch ($type) {
            case 'round_robin':
                $standings = $this->deriveRoundRobinStandings($tournamentId);
                break;
            case 'double_elimination':
                $standings = $this->deriveEliminationStandings($tournamentId, true);
                break;
            case 'single_elimination':
            default:
                $standings = $this->deriveEliminationStandings($tournamentId, false);
                break;
        }

        // Award participation points to everyone, then override for placed players
        $allPlayers = $this->getRegisteredPlayerIds($tournamentId);
        $participationPts = (int)($tournament['settings_decoded']['participation_points']
                            ?? $this->config['participation_points']
                            ?? 5);

        $this->db->beginTransaction();
        try {
            // Base participation upsert for all players
            foreach ($allPlayers as $pid) {
                $this->upsertScore($tournamentId, $pid, 0, $participationPts, $adminId,
                    'Participation points', false);
            }

            // Placement-based upsert (overwrites participation row)
            foreach ($standings as $placement => $pid) {
                $pts = $dist[$placement] ?? $this->config['participation_points'];
                $this->upsertScore($tournamentId, $pid, $placement, $pts, $adminId,
                    "Auto-computed placement #{$placement}");
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════
    // READ SCORES
    // ══════════════════════════════════════════════════════════

    /**
     * Return all scores for a tournament, joined with user info.
     *
     * @return array[]  Keys: player_id, placement, points,
     *                        display_name, full_name, username,
     *                        recorded_by_name, recorded_at, note
     */
    public function getTournamentScores(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ts.player_id,
                    ts.placement,
                    ts.points,
                    ts.note,
                    ts.recorded_at,
                    COALESCE(u.display_name, u.full_name, u.username) AS display_name,
                    u.full_name,
                    u.username,
                    COALESCE(rb.username, 'System') AS recorded_by_name
               FROM falcon.tournament_scores ts
               JOIN falcon.users u  ON u.id  = ts.player_id
          LEFT JOIN falcon.users rb ON rb.id = ts.recorded_by
              WHERE ts.tournament_id = :tid
              ORDER BY ts.placement ASC, ts.points DESC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return $stmt->fetchAll();
    }

    // ══════════════════════════════════════════════════════════
    // MANUAL SCORE OVERRIDE
    // ══════════════════════════════════════════════════════════

    /**
     * Admin manually sets or overrides a single player's score.
     */
    public function manuallySetScore(
        int    $tournamentId,
        int    $playerId,
        int    $placement,
        int    $points,
        int    $adminId,
        string $note = ''
    ): void {
        $this->upsertScore(
            $tournamentId, $playerId, $placement, $points, $adminId,
            $note ?: 'Manual override by admin'
        );
    }

    // ══════════════════════════════════════════════════════════
    // POINT DISTRIBUTION
    // ══════════════════════════════════════════════════════════

    /**
     * Returns the default point distribution from config.
     * Keys = placement (int), values = points (int).
     */
    public function getDefaultPointDistribution(): array
    {
        return $this->config['point_distribution'] ?? [
            1 => 100, 2 => 75, 3 => 50, 4 => 30,
            5 => 20,  6 => 15, 7 => 10, 8 => 5,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — STANDINGS DERIVATION
    // ══════════════════════════════════════════════════════════

    /**
     * Derive standings for single or double elimination.
     *
     * Algorithm:
     *   1. Winner of the final match = 1st place.
     *   2. Loser of the final match  = 2nd place.
     *   3. Players eliminated in semi-finals share 3rd.
     *   4. Quarterfinalists share 5th. And so on.
     *
     * For double elimination, the grand final winner = 1st,
     * grand final loser = 2nd; remaining by losers-bracket exit round.
     *
     * Returns: [ placement (1-indexed) => player_id, … ]
     */
    private function deriveEliminationStandings(
        int  $tournamentId,
        bool $isDouble
    ): array {
        // Load all completed matches
        $stmt = $this->db->prepare(
            "SELECT bracket_round, match_number, bracket_section,
                    player1_id, player2_id, winner_id, status
               FROM falcon.tournament_matches
              WHERE tournament_id = :tid
                AND status = 'completed'
              ORDER BY bracket_round DESC, match_number ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $matches = $stmt->fetchAll();

        if (empty($matches)) return [];

        // ── Double elimination: use grand_final section ──────
        if ($isDouble) {
            return $this->deriveDoubleElimStandings($matches);
        }

        // ── Single elimination ───────────────────────────────
        // Group matches by round descending
        $byRound = [];
        foreach ($matches as $m) {
            if ($m['bracket_section'] !== 'main' && $m['bracket_section'] !== 'winners') {
                continue;
            }
            $byRound[$m['bracket_round']][] = $m;
        }
        if (empty($byRound)) return [];

        krsort($byRound); // highest round first
        $rounds = array_keys($byRound);
        $finalRound = $rounds[0];

        $standings    = [];
        $placement    = 1;

        // Walk rounds from final down to round 1
        foreach ($byRound as $round => $roundMatches) {
            // Losers of this round get the next placement(s)
            if ($round === $finalRound) {
                // Final: winner = 1st, loser = 2nd
                $finalMatch = $roundMatches[0];
                if ($finalMatch['winner_id']) {
                    $standings[1] = (int)$finalMatch['winner_id'];
                    $loserId = ((int)$finalMatch['winner_id'] === (int)$finalMatch['player1_id'])
                        ? (int)$finalMatch['player2_id']
                        : (int)$finalMatch['player1_id'];
                    if ($loserId) $standings[2] = $loserId;
                }
                $placement = 3;
            } else {
                // Losers of this round share the next placement band
                foreach ($roundMatches as $m) {
                    if (!$m['winner_id']) continue;
                    $loserId = ((int)$m['winner_id'] === (int)$m['player1_id'])
                        ? (int)$m['player2_id']
                        : (int)$m['player1_id'];
                    if ($loserId && !in_array($loserId, $standings, true)) {
                        $standings[$placement++] = $loserId;
                    }
                }
            }
        }

        return $standings;
    }

    /**
     * Double-elim standings: find grand final, then losers bracket exits.
     */
    private function deriveDoubleElimStandings(array $matches): array
    {
        $standings = [];
        $placement = 1;

        // Grand final
        $gfMatches = array_filter($matches, fn($m) => $m['bracket_section'] === 'grand_final');
        // Highest round number in grand_final is the decisive match
        usort($gfMatches, fn($a,$b) => $b['bracket_round'] <=> $a['bracket_round']);
        $gfMatch = reset($gfMatches);
        if ($gfMatch && $gfMatch['winner_id']) {
            $standings[1] = (int)$gfMatch['winner_id'];
            $loserId = ((int)$gfMatch['winner_id'] === (int)$gfMatch['player1_id'])
                ? (int)$gfMatch['player2_id']
                : (int)$gfMatch['player1_id'];
            if ($loserId) $standings[2] = $loserId;
            $placement = 3;
        }

        // Losers bracket — eliminated by round descending
        $lMatches = array_filter($matches, fn($m) => $m['bracket_section'] === 'losers');
        $lByRound = [];
        foreach ($lMatches as $m) $lByRound[$m['bracket_round']][] = $m;
        krsort($lByRound);

        foreach ($lByRound as $roundMatches) {
            foreach ($roundMatches as $m) {
                if (!$m['winner_id']) continue;
                $loserId = ((int)$m['winner_id'] === (int)$m['player1_id'])
                    ? (int)$m['player2_id']
                    : (int)$m['player1_id'];
                if ($loserId && !in_array($loserId, $standings, true)) {
                    $standings[$placement++] = $loserId;
                }
            }
        }

        // Winners bracket early exits
        $wMatches = array_filter($matches, fn($m) => in_array($m['bracket_section'], ['winners','main'], true));
        $wByRound = [];
        foreach ($wMatches as $m) $wByRound[$m['bracket_round']][] = $m;
        krsort($wByRound);
        // Skip the final winners round (they go to grand final, already placed)
        array_shift($wByRound);

        foreach ($wByRound as $roundMatches) {
            foreach ($roundMatches as $m) {
                if (!$m['winner_id']) continue;
                $loserId = ((int)$m['winner_id'] === (int)$m['player1_id'])
                    ? (int)$m['player2_id']
                    : (int)$m['player1_id'];
                if ($loserId && !in_array($loserId, $standings, true)) {
                    $standings[$placement++] = $loserId;
                }
            }
        }

        return $standings;
    }

    /**
     * Derive round-robin standings by wins DESC, then points-for DESC.
     */
    private function deriveRoundRobinStandings(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT player1_id, player2_id, winner_id,
                    score_player1, score_player2
               FROM falcon.tournament_matches
              WHERE tournament_id = :tid
                AND status = 'completed'"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $matches = $stmt->fetchAll();

        // Aggregate wins and points-scored per player
        $stats = [];
        foreach ($matches as $m) {
            foreach ([(int)$m['player1_id'], (int)$m['player2_id']] as $pid) {
                if (!$pid) continue;
                if (!isset($stats[$pid])) {
                    $stats[$pid] = ['wins' => 0, 'points_for' => 0];
                }
            }
            if ($m['winner_id']) {
                $wid = (int)$m['winner_id'];
                if (isset($stats[$wid])) $stats[$wid]['wins']++;
            }
            if ($m['player1_id'] && $m['score_player1'] !== null) {
                $stats[(int)$m['player1_id']]['points_for'] += (int)$m['score_player1'];
            }
            if ($m['player2_id'] && $m['score_player2'] !== null) {
                $stats[(int)$m['player2_id']]['points_for'] += (int)$m['score_player2'];
            }
        }

        if (empty($stats)) return [];

        // Sort: wins DESC, then points_for DESC
        uasort($stats, function ($a, $b) {
            if ($b['wins'] !== $a['wins']) return $b['wins'] <=> $a['wins'];
            return $b['points_for'] <=> $a['points_for'];
        });

        // Convert to placement => player_id map
        $standings = [];
        $placement = 1;
        foreach ($stats as $pid => $s) {
            $standings[$placement++] = $pid;
        }
        return $standings;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * Upsert a single row into falcon.tournament_scores.
     *
     * When $overwrite = false, existing rows with a real placement (> 0)
     * are not replaced (used for participation-only writes).
     */
    private function upsertScore(
        int    $tournamentId,
        int    $playerId,
        int    $placement,
        int    $points,
        int    $adminId,
        string $note = '',
        bool   $overwrite = true
    ): void {
        if ($overwrite) {
            $this->db->prepare(
                "INSERT INTO falcon.tournament_scores
                     (tournament_id, player_id, placement, points, note, recorded_by, recorded_at)
                 VALUES (:tid, :pid, :pl, :pts, :note, :admin, NOW())
                 ON CONFLICT (tournament_id, player_id)
                 DO UPDATE SET
                     placement   = EXCLUDED.placement,
                     points      = EXCLUDED.points,
                     note        = EXCLUDED.note,
                     recorded_by = EXCLUDED.recorded_by,
                     recorded_at = NOW()"
            )->execute([
                ':tid'   => $tournamentId,
                ':pid'   => $playerId,
                ':pl'    => $placement,
                ':pts'   => $points,
                ':note'  => $note ?: null,
                ':admin' => $adminId,
            ]);
        } else {
            // Only insert if no row exists yet
            $this->db->prepare(
                "INSERT INTO falcon.tournament_scores
                     (tournament_id, player_id, placement, points, note, recorded_by, recorded_at)
                 VALUES (:tid, :pid, :pl, :pts, :note, :admin, NOW())
                 ON CONFLICT (tournament_id, player_id) DO NOTHING"
            )->execute([
                ':tid'   => $tournamentId,
                ':pid'   => $playerId,
                ':pl'    => $placement,
                ':pts'   => $points,
                ':note'  => $note ?: null,
                ':admin' => $adminId,
            ]);
        }
    }

    /** Resolve point distribution from tournament settings or fall back to config. */
    private function resolvePointDistribution(array $tournament): array
    {
        $settings = $tournament['settings_decoded'] ?? [];
        if (!empty($settings['point_distribution']) && is_array($settings['point_distribution'])) {
            // Cast keys to int
            $custom = [];
            foreach ($settings['point_distribution'] as $place => $pts) {
                $custom[(int)$place] = (int)$pts;
            }
            return $custom;
        }
        return $this->getDefaultPointDistribution();
    }

    /** Fetch a tournament row with decoded settings JSON. */
    private function getTournament(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM falcon.tournaments WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['settings_decoded'] = json_decode($row['settings'] ?? '{}', true) ?? [];
        return $row;
    }

    /** Get all non-withdrawn player IDs for a tournament. */
    private function getRegisteredPlayerIds(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT player_id FROM falcon.tournament_players
              WHERE tournament_id = :tid
                AND status NOT IN ('withdrawn')
              ORDER BY seed ASC NULLS LAST"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return array_column($stmt->fetchAll(), 'player_id');
    }
}