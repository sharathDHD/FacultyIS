<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireRole(['hod', 'admin']);
$user = Auth::user();
$config = require __DIR__ . '/../src/config.php';
$pdo = Database::connection();

// ── Read-side department scoping via DepartmentScope ──────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$departments = $scope->departments();

// ── Centralized effective department selection ──
// No duplicate if/else for role-based department filtering.
$requestedDeptId = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int) $_GET['department_id'] : null;
$departmentId = $scope->effectiveDepartmentId($requestedDeptId);

// --- Report generation flow ---
// 1. PHP calls Flask with the session's JWT.
// 2. Flask validates the token, runs the aggregation query, returns JSON.
// 3. PHP renders the JSON into the view.
// 4. On SERVICE failure (5xx/network), PHP falls back to the last cached copy.
//    On AUTHORIZATION failure (401/403), PHP never falls back — the cache
//    must never weaken the authorization decision.
$client = new ApiClient($config['flask_api']['base_url'], $_SESSION['jwt']);
$query = $departmentId ? ['department_id' => $departmentId] : [];
$response = $client->get('/api/reports/workload', $query);

$usingCache = false;
$reportData = null;

if ($response['ok']) {
    $reportData = $response['data'];
    // Cache the good response for fallback use.
    try {
        $cacheKey = 'workload_' . ($departmentId ?? 'all');
        $payload = json_encode($reportData);

        // SQLite: INSERT OR REPLACE for upsert
        $stmt = $pdo->prepare(
            "INSERT OR REPLACE INTO report_cache (cache_key, payload, generated_at)
             VALUES (:key, :payload, datetime('now'))"
        );
        $stmt->execute(['key' => $cacheKey, 'payload' => $payload]);
    } catch (Throwable $e) {
        error_log('report cache write failed: ' . $e->getMessage());
    }
} elseif (in_array($response['status'], [401, 403], true)) {
    // ── Authorization failure — NEVER fall back to cache. ──
    // A 401/403 means the authenticated user is not authorized for this
    // data. Serving cached data (potentially created by a different user
    // with higher privileges) would be a confidentiality breach.
    http_response_code($response['status']);
    die($response['status'] . ' Forbidden — you are not authorized to access this report.');
} else {
    // Flask unreachable/errored (5xx, network failure) — try the cache.
    $stmt = $pdo->prepare('SELECT payload FROM report_cache WHERE cache_key = :key');
    $stmt->execute(['key' => 'workload_' . ($departmentId ?? 'all')]);
    $cached = $stmt->fetch();
    if ($cached) {
        $reportData = json_decode($cached['payload'], true);
        $usingCache = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Department Workload Report</h2>

    <?php if (!$response['ok'] && !$usingCache): ?>
      <div class="flash error">
        Analytics service is temporarily unavailable and no cached report exists yet.
        (<?= h($response['error'] ?? 'HTTP ' . $response['status']) ?>)
      </div>
    <?php elseif ($usingCache): ?>
      <div class="flash error">Analytics service is unreachable — showing last cached report.</div>
    <?php endif; ?>

    <form method="get" action="/reports.php" class="toolbar">
      <select name="department_id" onchange="this.form.submit()">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="/export.php?format=csv<?= $departmentId ? '&department_id=' . $departmentId : '' ?>" class="btn-sm">Export CSV</a>
      <a href="/export.php?format=xlsx<?= $departmentId ? '&department_id=' . $departmentId : '' ?>" class="btn-sm">Export Excel</a>
    </form>

    <?php if ($reportData): ?>
      <?php foreach ($reportData['departments'] as $deptName => $facultyRows): ?>
        <div class="card" style="margin-bottom:18px;">
          <h3 style="margin-bottom:4px;"><?= h($deptName) ?></h3>
          <p style="color:#6b7280;font-size:13px;margin-bottom:12px;">
            Average sections per faculty: <strong><?= h((string) ($reportData['department_averages'][$deptName] ?? 'N/A')) ?></strong>
          </p>
          <table class="data-table">
            <thead><tr><th>Faculty</th><th>User ID</th><th>Sections</th></tr></thead>
            <tbody>
              <?php foreach ($facultyRows as $row): ?>
                <tr>
                  <td><?= h($row['faculty_name']) ?></td>
                  <td><?= h($row['faculty_user_id']) ?></td>
                  <td><?= (int) $row['section_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php elseif ($response['ok']): ?>
      <p style="color:#6b7280;">No data yet — add faculty and course sections first.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
