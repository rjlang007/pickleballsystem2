<?php
/**
 * Admin: Bulk Import Scores
 * Path: admin/bulk_import_scores.php
 * Import match scores and results from CSV
 */

require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../config/security.php');
require_once(__DIR__ . '/../includes/security_helpers.php');

requireAdmin();

$message = '';
$message_type = '';
$import_preview = null;
$import_stats = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    verifyCsrf();
    $file = $_FILES['csv_file']['tmp_name'];
    $filename = $_FILES['csv_file']['name'];
    
    if (!file_exists($file)) {
        $message = 'No file uploaded';
        $message_type = 'error';
    } else {
        // Parse CSV
        $rows = [];
        if (($handle = fopen($file, 'r')) !== false) {
            $header = null;
            while (($row = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = $row;
                } else {
                    $rows[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }

        if (empty($rows)) {
            $message = 'CSV file is empty';
            $message_type = 'error';
        } else {
            // Validate rows
            $errors = [];
            $valid_rows = [];
            
            foreach ($rows as $idx => $row) {
                $line = $idx + 2; // +2 for header and 1-based
                
                // Required fields: tournament_id, player1_id, player2_id, winner_id, score1, score2
                if (empty($row['tournament_id']) || empty($row['player1_id']) || empty($row['player2_id']) || 
                    empty($row['winner_id']) || !isset($row['score1']) || !isset($row['score2'])) {
                    $errors[] = "Line $line: Missing required fields";
                    continue;
                }

                // Validate numeric fields
                if (!is_numeric($row['tournament_id']) || !is_numeric($row['player1_id']) || 
                    !is_numeric($row['player2_id']) || !is_numeric($row['winner_id'])) {
                    $errors[] = "Line $line: Invalid numeric values";
                    continue;
                }

                $valid_rows[] = $row;
            }

            $import_preview = [
                'total_rows' => count($rows),
                'valid_rows' => count($valid_rows),
                'errors' => $errors,
                'preview_rows' => array_slice($valid_rows, 0, 5)
            ];

            if (!empty($_POST['confirm_import']) && empty($errors)) {
                // Execute import
                $pdo->beginTransaction();
                try {
                    $imported_count = 0;
                    $updated_leaderboard = false;

                    foreach ($valid_rows as $row) {
                        // Check if match already exists
                        $stmt = $pdo->prepare("
                            SELECT id FROM tournament_matches 
                            WHERE tournament_id = ? AND player1_id = ? AND player2_id = ?
                        ");
                        $stmt->execute([$row['tournament_id'], $row['player1_id'], $row['player2_id']]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            // Update existing match
                            $stmt = $pdo->prepare("
                                UPDATE tournament_matches 
                                SET winner_id = ?, score1 = ?, score2 = ?, status = 'completed', updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$row['winner_id'], $row['score1'], $row['score2'], $existing['id']]);
                        } else {
                            // Insert new match
                            $stmt = $pdo->prepare("
                                INSERT INTO tournament_matches 
                                (tournament_id, player1_id, player2_id, winner_id, score1, score2, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())
                            ");
                            $stmt->execute([
                                $row['tournament_id'],
                                $row['player1_id'],
                                $row['player2_id'],
                                $row['winner_id'],
                                $row['score1'],
                                $row['score2']
                            ]);
                        }
                        
                        $imported_count++;
                        $updated_leaderboard = true;
                    }

                    // Update leaderboard if matches were added
                    if ($updated_leaderboard) {
                        // Trigger leaderboard recalculation
                        // This would normally be done via trigger or API call
                    }

                    // Audit log
                    $audit_data = [
                        'action' => 'bulk_import_scores',
                        'filename' => $filename,
                        'rows_imported' => $imported_count,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_log (admin_id, action, details, created_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([$_SESSION['user_id'], 'bulk_import', json_encode($audit_data)]);

                    $pdo->commit();
                    $message = "Successfully imported $imported_count match results!";
                    $message_type = 'success';
                    $import_preview = null;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Import failed: ' . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import Scores</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style nonce="<?= getCspNonce() ?>">
        .import-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
        }

        .import-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .import-header {
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .import-header h1 {
            margin: 0 0 5px 0;
        }

        .import-header p {
            margin: 0;
            color: #666;
        }

        .message {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-wrapper input[type=file] {
            position: absolute;
            left: -9999px;
        }

        .file-input-label {
            display: block;
            padding: 15px;
            background: #f9f9f9;
            border: 2px dashed #ddd;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #3498db;
        }

        .file-input-label:hover {
            border-color: #3498db;
            background: #f0f7ff;
        }

        .file-input-label.has-file {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-section-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .csv-template {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 12px;
            color: #666;
            line-height: 1.6;
        }

        .csv-template code {
            background: #fff;
            padding: 2px 4px;
            border-radius: 2px;
            display: block;
            margin: 4px 0;
            font-family: monospace;
            overflow-x: auto;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 15px;
        }

        .preview-table thead {
            background: #f9f9f9;
            border-bottom: 2px solid #e0e0e0;
        }

        .preview-table th {
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }

        .preview-table td {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .error-list {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .error-list li {
            margin: 4px 0;
            color: #721c24;
            font-size: 13px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
        }

        .btn-import {
            background: #27ae60;
            color: #fff;
        }

        .btn-import:hover {
            background: #229954;
        }

        .btn-cancel {
            background: #95a5a6;
            color: #fff;
        }

        .btn-cancel:hover {
            background: #7f8c8d;
        }

        .btn-download {
            background: #3498db;
            color: #fff;
        }

        .btn-download:hover {
            background: #2980b9;
        }

        @media (max-width: 600px) {
            .import-container {
                padding: 10px;
            }

            .action-buttons {
                flex-direction: column;
            }

            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php require_once(__DIR__ . '/../includes/header.php'); ?>

    <div class="import-container">
        <div class="import-card">
            <div class="import-header">
                <h1>📥 Bulk Import Match Scores</h1>
                <p>Import multiple match results from a CSV file</p>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!$import_preview): ?>
                <!-- Upload Form -->
                <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
                    <div class="form-section">
                        <div class="form-section-title">CSV Template</div>
                        <div class="csv-template">
                            Required columns:
                            <code>tournament_id, player1_id, player2_id, winner_id, score1, score2</code>
                            <code>Example: 1, 5, 12, 5, 21, 15</code>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Choose CSV File</div>
                        <div class="file-input-wrapper">
                            <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
                            <label for="csvFile" class="file-input-label">
                                📂 Click to select CSV file or drag and drop
                            </label>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" class="btn-import">📤 Upload & Preview</button>
                        <a href="#" class="btn-download" onclick="downloadTemplate(event)">📋 Download Template</a>
                    </div>
                </form>
            <?php else: ?>
                <!-- Preview Form -->
                <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
                    <input type="hidden" name="csv_file" value="<?php echo htmlspecialchars($filename ?? ''); ?>">
                    
                    <div class="form-section">
                        <div class="form-section-title">Import Preview</div>
                        <div style="background: #f9f9f9; padding: 12px; border-radius: 4px; margin-bottom: 15px;">
                            <strong>Total Rows:</strong> <?php echo $import_preview['total_rows']; ?><br>
                            <strong>Valid Rows:</strong> <span style="color: #27ae60;"><?php echo $import_preview['valid_rows']; ?></span><br>
                            <strong>Errors:</strong> <span style="color: #e74c3c;"><?php echo count($import_preview['errors']); ?></span>
                        </div>

                        <?php if (!empty($import_preview['errors'])): ?>
                            <div class="error-list">
                                <strong>Errors found:</strong>
                                <ul>
                                    <?php foreach (array_slice($import_preview['errors'], 0, 10) as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($import_preview['errors']) > 10): ?>
                                        <li>... and <?php echo count($import_preview['errors']) - 10; ?> more</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($import_preview['preview_rows'])): ?>
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <th>Tournament</th>
                                        <th>Player 1</th>
                                        <th>Player 2</th>
                                        <th>Winner</th>
                                        <th>Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($import_preview['preview_rows'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['tournament_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['player1_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['player2_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['winner_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['score1']); ?> - <?php echo htmlspecialchars($row['score2']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($import_preview['errors']) && $import_preview['valid_rows'] > 0): ?>
                        <div class="action-buttons">
                            <button type="submit" name="confirm_import" value="1" class="btn-import">
                                ✅ Confirm & Import <?php echo $import_preview['valid_rows']; ?> Matches
                            </button>
                            <button type="button" class="btn-cancel" onclick="window.location.reload()">Cancel</button>
                        </div>
                    <?php else: ?>
                        <div class="action-buttons">
                            <button type="button" class="btn-cancel" onclick="window.location.reload()">Go Back</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once(__DIR__ . '/../includes/footer.php'); ?>

    <script nonce="<?= getCspNonce() ?>">
        // File input label update
        document.getElementById('csvFile').addEventListener('change', function() {
            const label = document.querySelector('.file-input-label');
            if (this.files.length > 0) {
                label.textContent = '✅ ' + this.files[0].name;
                label.classList.add('has-file');
            }
        });

        // Drag and drop
        const label = document.querySelector('.file-input-label');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            label.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        label.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            document.getElementById('csvFile').files = files;
            document.getElementById('csvFile').dispatchEvent(new Event('change'));
        }

        function downloadTemplate(e) {
            e.preventDefault();
            const csv = 'tournament_id,player1_id,player2_id,winner_id,score1,score2\n1,5,12,5,21,15\n1,3,7,7,19,21\n';
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'scores_template.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
