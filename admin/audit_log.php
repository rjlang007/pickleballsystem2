<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$db = getDB();
$action = trim((string)($_GET['action'] ?? ''));
$result = in_array($_GET['result'] ?? '', ['success', 'failure'], true) ? $_GET['result'] : '';
$user = max(0, (int)($_GET['user_id'] ?? 0));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$where = ['1=1'];
$params = [];
if ($action !== '') { $where[] = 'a.action ILIKE :action'; $params[':action'] = '%' . $action . '%'; }
if ($result !== '') { $where[] = 'a.result = :result'; $params[':result'] = $result; }
if ($user > 0) { $where[] = 'a.user_id = :user_id'; $params[':user_id'] = $user; }
if ($from !== '') { $where[] = 'a.created_at >= :from_date'; $params[':from_date'] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.created_at < (:to_date::date + INTERVAL \'1 day\')'; $params[':to_date'] = $to; }
$whereSql = implode(' AND ', $where);
$auditSource = "(
    SELECT id, user_id, action, table_name, record_id, result, notes, created_at, 'audit_log' AS source
      FROM falcon.audit_log
    UNION ALL
    SELECT id, user_id, action, NULL AS table_name, NULL AS record_id,
           CASE WHEN severity = 'critical' THEN 'failure' ELSE 'success' END AS result,
           details AS notes, created_at, 'activity_logs' AS source
      FROM falcon.activity_logs
    UNION ALL
    SELECT id, actor_id AS user_id, action, 'tournament' AS table_name, match_id AS record_id,
           'success' AS result, details::text AS notes, created_at, 'tournament_audit_log' AS source
      FROM falcon.tournament_audit_log
)";
$count = $db->prepare("SELECT COUNT(*) FROM $auditSource a WHERE $whereSql");
$count->execute($params);
$total = (int)$count->fetchColumn();
$offset = ($page - 1) * $limit;
$stmt = $db->prepare("SELECT a.*, COALESCE(u.username, 'system') AS actor FROM $auditSource a LEFT JOIN falcon.users u ON u.id = a.user_id WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Audit Log';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header"><h1 style="margin:0 0 6px;">Audit Log</h1><p style="color:var(--muted);margin:0;">Search security, payment, booking, and staff actions.</p></div>
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin:18px 0;">
      <input class="form-input" name="action" placeholder="Action" value="<?= clean($action) ?>">
      <input class="form-input" name="user_id" type="number" min="1" placeholder="User ID" value="<?= $user ?: '' ?>">
      <select class="form-input" name="result"><option value="">Any result</option><option value="success" <?= $result === 'success' ? 'selected' : '' ?>>Success</option><option value="failure" <?= $result === 'failure' ? 'selected' : '' ?>>Failure</option></select>
      <input class="form-input" name="from" type="date" value="<?= clean($from) ?>"><input class="form-input" name="to" type="date" value="<?= clean($to) ?>">
      <button class="btn btn-primary">Filter</button><a class="btn" href="admin/audit_log.php">Clear</a>
    </form>
    <div style="overflow:auto"><table class="table"><thead><tr><th>When</th><th>Source</th><th>Actor</th><th>Action</th><th>Target</th><th>Result</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><?= clean($row['created_at']) ?></td><td><?= clean($row['source']) ?></td><td><?= clean($row['actor']) ?></td><td><?= clean($row['action']) ?></td><td><?= clean(($row['table_name'] ?: '') . ($row['record_id'] ? '#' . $row['record_id'] : '')) ?></td><td><?= clean($row['result']) ?></td><td><?= clean($row['notes'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">No audit records match these filters.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($total > $limit): ?><div style="margin-top:16px;display:flex;gap:8px;"><?php if ($page > 1): ?><a class="btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a><?php endif; ?><?php if ($offset + $limit < $total): ?><a class="btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a><?php endif; ?></div><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
