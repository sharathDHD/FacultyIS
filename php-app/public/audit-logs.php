<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();

// ── Centralized write-side authorization ──────────────────
$pdo = Database::connection();
$policy = AuthorizationPolicy::forUser($user, $pdo);

// Faculty cannot access audit logs
$policy->canViewAuditLogs();

$isAdmin = $user['role'] === 'admin';

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// --- Filters ---
$filterAction    = trim($_GET['action'] ?? '');
$filterEntity   = trim($_GET['entity_type'] ?? '');
$filterUser     = trim($_GET['user'] ?? '');
$filterFrom     = trim($_GET['from'] ?? '');
$filterTo       = trim($_GET['to'] ?? '');

$whereParts = ['1=1'];
$params = [];

// ── Department scoping: HOD can only see audit logs in their own department ──
if (!$isAdmin) {
    $whereParts[] = 'a.department_id = :scope_dept';
    $params['scope_dept'] = (int) $user['department_id'];
}

if ($filterAction !== '') {
    $whereParts[] = 'a.action = :faction';
    $params['faction'] = $filterAction;
}
if ($filterEntity !== '') {
    $whereParts[] = 'a.entity_type = :fentity';
    $params['fentity'] = $filterEntity;
}
if ($filterUser !== '') {
    $whereParts[] = '(u.name LIKE :fuser OR u.user_id LIKE :fuser)';
    $params['fuser'] = '%' . $filterUser . '%';
}
if ($filterFrom !== '') {
    $whereParts[] = 'a.created_at >= :ffrom';
    $params['ffrom'] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $whereParts[] = 'a.created_at <= :fto';
    $params['fto'] = $filterTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $whereParts);

// --- Handle purge (admin only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'purge') {
        // ── Authorization via centralized policy ──
        $policy->canPurgeAuditLogs();
        $days = max(1, (int) ($_POST['days'] ?? 90));
        // Enforce minimum retention: cannot delete records newer than 90 days
        $minRetention = 90;
        if ($days < $minRetention) {
            $days = $minRetention;
        }
        $confirm = trim($_POST['confirm'] ?? '');
        if ($confirm !== 'PURGE') {
            // Require explicit confirmation text
            $purgeError = 'Type PURGE to confirm deletion.';
        } else {
            // SQLite: datetime with modifier for date math
            $purgeSql = "DELETE FROM audit_log WHERE created_at < datetime('now', '-' || :days || ' days')";
            $stmt = $pdo->prepare($purgeSql);
            $stmt->execute(['days' => $days]);
            $deleted = $stmt->rowCount();
            AuditLog::record($user['id'], 'AUDIT_PURGE', "Purged $deleted logs older than $days days");
            header('Location: /audit-logs.php');
            exit;
        }
    }
}

// --- Get distinct actions and entity types for dropdowns (scoped) ---
if ($isAdmin) {
    $actionsList = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $entityTypesList = $pdo->query('SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare('SELECT DISTINCT a.action FROM audit_log a WHERE a.department_id = :dept ORDER BY a.action');
    $stmt->execute(['dept' => (int) $user['department_id']]);
    $actionsList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('SELECT DISTINCT a.entity_type FROM audit_log a WHERE a.entity_type IS NOT NULL AND a.department_id = :dept ORDER BY a.entity_type');
    $stmt->execute(['dept' => (int) $user['department_id']]);
    $entityTypesList = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// --- Count total matching rows ---
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS c FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $whereClause"
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// --- Fetch paginated rows ---
$listStmt = $pdo->prepare(
    "SELECT a.*, u.name AS user_name, u.user_id AS user_uid
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE $whereClause
     ORDER BY a.created_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$logs = $listStmt->fetchAll();

// Build query string for pagination links (without page)
$qs = http_build_query(array_filter([
    'action'      => $filterAction,
    'entity_type' => $filterEntity,
    'user'        => $filterUser,
    'from'        => $filterFrom,
    'to'          => $filterTo,
]));
$qsPrefix = $qs ? '&' . $qs : '';

/** Format JSON values for display */
function fmtJson(?string $json): string {
    if ($json === null) return '—';
    $data = json_decode($json, true);
    if (!is_array($data)) return h($json);
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = h($k) . ': ' . h((string) $v);
    }
    return implode(', ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit Logs — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Audit Logs</h2>

    <!-- Filters -->
    <div class="card" style="margin-bottom:20px;">
      <h3 style="margin-bottom:12px;">Filters</h3>
      <form method="get" action="/audit-logs.php">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
          <div class="field">
            <label>Action</label>
            <select name="action">
              <option value="">All Actions</option>
              <?php foreach ($actionsList as $act): ?>
                <option value="<?= h($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $act)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Entity Type</label>
            <select name="entity_type">
              <option value="">All Types</option>
              <?php foreach ($entityTypesList as $et): ?>
                <option value="<?= h($et) ?>" <?= $filterEntity === $et ? 'selected' : '' ?>><?= h($et) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>User</label>
            <input type="text" name="user" value="<?= h($filterUser) ?>" placeholder="Name or user ID">
          </div>
          <div class="field">
            <label>From</label>
            <input type="date" name="from" value="<?= h($filterFrom) ?>">
          </div>
          <div class="field">
            <label>To</label>
            <input type="date" name="to" value="<?= h($filterTo) ?>">
          </div>
        </div>
        <div style="margin-top:14px;">
          <button class="btn-sm" type="submit">Apply</button>
          <a href="/audit-logs.php" class="btn-sm" style="background:var(--color-text-muted);">Clear</a>
        </div>
      </form>
    </div>

    <?php if ($isAdmin): ?>
      <!-- Purge form (admin only, with retention policy) -->
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-bottom:12px;">Purge Old Logs</h3>
        <?php if (!empty($purgeError)): ?>
          <div class="flash error"><?= h($purgeError) ?></div>
        <?php endif; ?>
        <p style="color:var(--color-text-muted);font-size:13px;margin-bottom:12px;">
          Minimum retention: <strong>90 days</strong>. Records newer than 90 days cannot be deleted.
        </p>
        <form method="post" action="/audit-logs.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="purge">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <label>Delete logs older than</label>
            <input type="number" name="days" value="180" min="90" max="3650" style="width:80px;">
            <label>days</label>
            <input type="text" name="confirm" placeholder='Type PURGE to confirm' style="width:160px;" autocomplete="off">
            <button class="btn-sm danger" type="submit">Purge</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <p style="color:var(--color-text-muted);margin-bottom:10px;">
      Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> entries
      <?php if (!$isAdmin): ?>
        <span style="margin-left:12px;color:var(--color-text-muted);">(Scoped to your department — read-only)</span>
      <?php endif; ?>
    </p>

    <table class="data-table">
      <thead>
        <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Detail</th><th>Source IP</th><th>Req ID</th><th>Changes</th></tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td style="white-space:nowrap;"><?= h(substr($l['created_at'], 0, 19)) ?></td>
            <td><?= h($l['user_name'] ?? $l['user_uid'] ?? '—') ?></td>
            <td><span class="badge"><?= h(str_replace('_', ' ', $l['action'])) ?></span></td>
            <td>
              <?php if ($l['entity_type']): ?>
                <?= h($l['entity_type']) ?><?php if ($l['entity_id']): ?> #<?= (int) $l['entity_id'] ?><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= h($l['detail'] ?? '—') ?></td>
            <td style="font-size:12px;white-space:nowrap;"><?= h($l['ip_address'] ?? '—') ?></td>
            <td style="font-size:11px;font-family:monospace;white-space:nowrap;color:var(--color-text-muted);" title="Source: <?= h($l['source'] ?? 'php') ?>"><?= h($l['request_id'] ?? '—') ?></td>
            <td style="font-size:12px;max-width:250px;overflow:hidden;text-overflow:ellipsis;" title="<?= h(($l['old_values'] ?? '') . ' → ' . ($l['new_values'] ?? '')) ?>">
              <?php if ($l['old_values'] || $l['new_values']): ?>
                <?php if ($l['old_values']): ?><span style="color:var(--color-danger);text-decoration:line-through;"><?= fmtJson($l['old_values']) ?></span><?php endif; ?>
                <?php if ($l['old_values'] && $l['new_values']): ?> → <?php endif; ?>
                <?php if ($l['new_values']): ?><span style="color:var(--color-success);"><?= fmtJson($l['new_values']) ?></span><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--color-text-muted);">No audit log entries found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="toolbar" style="margin-top:16px;justify-content:center;">
        <?php if ($page > 1): ?>
          <a href="/audit-logs.php?page=<?= $page - 1 ?><?= $qsPrefix ?>" class="btn-sm">&laquo; Prev</a>
        <?php endif; ?>
        <span style="padding:0 12px;"><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="/audit-logs.php?page=<?= $page + 1 ?><?= $qsPrefix ?>" class="btn-sm">Next &raquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
