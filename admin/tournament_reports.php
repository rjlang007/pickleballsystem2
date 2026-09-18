<?php
/**
 * Admin: Tournament Reports & Analytics
 * Path: admin/tournament_reports.php
 * Comprehensive tournament and player analytics
 */

require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../config/security.php');
require_once(__DIR__ . '/../includes/security_helpers.php');

requireAdmin();

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Tournament Statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tournaments,
        AVG((SELECT COUNT(*) FROM tournament_players WHERE tournament_id = t.id)) as avg_players,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'registration_open' THEN 1 ELSE 0 END) as draft
    FROM tournaments t
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$tournament_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Bracket type statistics
$stmt = $pdo->prepare("
    SELECT 
        bracket_type,
        COUNT(*) as count,
        AVG((SELECT COUNT(*) FROM tournament_players WHERE tournament_id = tournaments.id)) as avg_players
    FROM tournaments
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY bracket_type
");
$stmt->execute([$start_date, $end_date]);
$bracket_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top performers
$stmt = $pdo->prepare("
    SELECT 
        u.full_name AS name,
        u.id,
        COUNT(*) as tournament_count,
        SUM(CASE WHEN ts.placement = 1 THEN 1 ELSE 0 END) as wins,
        SUM(ts.points) as total_points,
        AVG(ts.placement) as avg_placement
    FROM tournament_scores ts
    JOIN users u ON ts.player_id = u.id
    JOIN tournaments t ON ts.tournament_id = t.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    GROUP BY ts.player_id, u.full_name, u.id
    ORDER BY total_points DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$top_performers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Reports</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tournament_theme.css">
    <style nonce="<?= getCspNonce() ?>">
        .reports-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .reports-header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .reports-header h1 {
            margin: 0 0 15px 0;
        }

        .date-filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .date-filter input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }

        .date-filter button {
            padding: 8px 15px;
            background: #3498db;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }

        .date-filter button:hover {
            background: #2980b9;
        }

        .report-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .report-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .stat-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .stat-box.success {
            border-left-color: #27ae60;
        }

        .stat-box.warning {
            border-left-color: #f39c12;
        }

        .stat-box.info {
            border-left-color: #3498db;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead {
            background: #f9f9f9;
            border-bottom: 2px solid #e0e0e0;
        }

        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        .rank-badge {
            display: inline-block;
            background: #3498db;
            color: #fff;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }

        .export-btn {
            float: right;
            padding: 8px 15px;
            background: #27ae60;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }

        .export-btn:hover {
            background: #229954;
        }

        @media (max-width: 600px) {
            .reports-container {
                padding: 10px;
            }

            .date-filter {
                flex-direction: column;
            }

            .date-filter input,
            .date-filter button {
                width: 100%;
            }

            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php require_once(__DIR__ . '/../includes/header.php'); ?>

    <div class="reports-container">
        <div class="reports-header">
            <h1>📊 Tournament Reports & Analytics</h1>
            <form method="GET" class="date-filter">
                <label>From:</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                <label>To:</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                <button type="submit">📅 Filter</button>
            </form>
        </div>

        <!-- Tournament Statistics -->
        <div class="report-card">
            <div class="report-title">Tournament Overview</div>
            <div class="stat-grid">
                <div class="stat-box info">
                    <div class="stat-label">Total Tournaments</div>
                    <div class="stat-value"><?php echo $tournament_stats['total_tournaments'] ?? 0; ?></div>
                </div>
                <div class="stat-box success">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $tournament_stats['completed'] ?? 0; ?></div>
                </div>
                <div class="stat-box warning">
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?php echo $tournament_stats['active'] ?? 0; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Draft</div>
                    <div class="stat-value"><?php echo $tournament_stats['draft'] ?? 0; ?></div>
                </div>
                <div class="stat-box info">
                    <div class="stat-label">Avg Players</div>
                    <div class="stat-value"><?php echo round($tournament_stats['avg_players'] ?? 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Bracket Type Distribution -->
        <div class="report-card">
            <div class="report-title">Bracket Type Distribution</div>
            <table>
                <thead>
                    <tr>
                        <th>Bracket Type</th>
                        <th>Count</th>
                        <th>Avg Players</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bracket_types as $type): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type['bracket_type']))); ?></td>
                            <td><?php echo $type['count']; ?></td>
                            <td><?php echo round($type['avg_players'], 1); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Performers -->
        <div class="report-card">
            <div class="report-title">🏆 Top Performers</div>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Player</th>
                        <th>Tournaments</th>
                        <th>Wins</th>
                        <th>Total Points</th>
                        <th>Avg Placement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_performers as $idx => $performer): ?>
                        <tr>
                            <td><span class="rank-badge"><?php echo $idx + 1; ?></span></td>
                            <td><?php echo htmlspecialchars($performer['name']); ?></td>
                            <td><?php echo $performer['tournament_count']; ?></td>
                            <td><?php echo $performer['wins']; ?></td>
                            <td><?php echo $performer['total_points']; ?></td>
                            <td><?php echo round($performer['avg_placement'], 1); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php require_once(__DIR__ . '/../includes/footer.php'); ?>
</body>
</html>
