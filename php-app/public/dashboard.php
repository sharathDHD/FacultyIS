<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();

$pdo = Database::connection();
$scope = DepartmentScope::forUser($user, $pdo);

// ── All dashboard statistics now go through DepartmentScope ──
// Admin sees institution-wide counts. HOD/faculty see own department only.
$stats = $scope->dashboardStats();

// Recent activity scoped to user's own department for non-admin
$recentActivity = $scope->recentActivity((int) $user['id']);

/** Display a stat value, showing "—" for DB errors (-1) */
function statVal(int $v): string { return $v >= 0 ? (string) $v : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <div class="main-content">
    <h2 style="margin-bottom:6px;">Welcome, <?= h($user['name']) ?></h2>
    <p style="color:#6b7280;margin-bottom:20px;">Role: <span class="badge"><?= h($user['role']) ?></span>
      <?php if (!$scope->isAdmin()): ?>
        <span style="margin-left:8px;color:#6b7280;">(Showing your department's data)</span>
      <?php endif; ?>
    </p>

    <div class="stat-grid">
      <div class="stat-card"><div class="value"><?= statVal($stats['faculty']) ?></div><div class="label">Faculty Members</div></div>
      <div class="stat-card"><div class="value"><?= statVal($stats['departments']) ?></div><div class="label">Departments</div></div>
      <div class="stat-card"><div class="value"><?= statVal($stats['courses']) ?></div><div class="label">Courses</div></div>
      <div class="stat-card"><div class="value"><?= statVal($stats['sections']) ?></div><div class="label">Sections</div></div>
      <div class="stat-card"><div class="value"><?= statVal($stats['documents']) ?></div><div class="label">Documents</div></div>
      <div class="stat-card"><div class="value"><?= statVal($stats['attendance_today']) ?></div><div class="label">Attendance Today</div></div>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <h3 style="margin-bottom:10px;">Quick links</h3>
      <p style="margin-bottom:14px;color:#6b7280;">
        <a href="/departments.php" class="btn-sm">Departments</a>
        <a href="/faculty.php" class="btn-sm">Manage Faculty</a>
        <a href="/courses.php" class="btn-sm">Courses &amp; Sections</a>
        <a href="/attendance.php" class="btn-sm">Attendance</a>
        <a href="/timetable.php" class="btn-sm">Timetable</a>
        <a href="/documents.php" class="btn-sm">Documents</a>
        <?php if (in_array($user['role'], ['hod', 'admin'], true)): ?>
          <a href="/reports.php" class="btn-sm">Workload Reports</a>
          <a href="/audit-logs.php" class="btn-sm">Audit Logs</a>
        <?php endif; ?>
      </p>
    </div>

    <?php if ($user['role'] === 'admin'): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-bottom:10px;">Quick Actions</h3>
        <p style="margin-bottom:0;color:#6b7280;">
          <a href="/departments.php" class="btn-sm">Manage Departments</a>
          <a href="/audit-logs.php" class="btn-sm">View Audit Logs</a>
        </p>
      </div>
    <?php endif; ?>

    <?php if (!empty($recentActivity)): ?>
      <div class="card">
        <h3 style="margin-bottom:10px;">Recent Activity</h3>
        <table class="data-table" style="font-size:13px;">
          <thead><tr><th>Action</th><th>Detail</th><th>Time</th></tr></thead>
          <tbody>
            <?php foreach ($recentActivity as $a): ?>
              <tr>
                <td><span class="badge"><?= h(str_replace('_', ' ', $a['action'])) ?></span></td>
                <td><?= h($a['detail'] ?? '—') ?></td>
                <td><?= h(substr($a['created_at'], 0, 16)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="/assets/js/dashboard.js"></script>
</body>
</html>
