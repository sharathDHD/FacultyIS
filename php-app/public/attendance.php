<?php
// Version: v2.12

require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$pdo = Database::connection();

$isFaculty = $user['role'] === 'faculty';
$canManage = in_array($user['role'], ['hod', 'admin'], true);

// ── Centralized write-side authorization ──────────────────
$policy = AuthorizationPolicy::forUser($user, $pdo);

$errors = [];

// --- Handle POST — record attendance ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'record_attendance') {
        $courseSectionId = (int) ($_POST['course_section_id'] ?? 0);
        $date = trim($_POST['date'] ?? '');
        $totalStudents = (int) ($_POST['total_students'] ?? 0);
        $presentCount = (int) ($_POST['present_count'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($courseSectionId <= 0) $errors['course_section_id'] = 'Select a course section.';
        if (!$date || !strtotime($date)) $errors['date'] = 'Enter a valid date.';
        if ($totalStudents < 0) $errors['total_students'] = 'Invalid student count.';
        if ($presentCount < 0 || $presentCount > $totalStudents) $errors['present_count'] = 'Present count must be 0 to total.';

        // ── Authorization via centralized policy ──
        if (empty($errors)) {
            $policy->canRecordAttendance($courseSectionId);
        }

        if (empty($errors)) {
            // Fetch department_id from the course section for audit
            $attDeptQ = $pdo->prepare(
                'SELECT c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :section_id'
            );
            $attDeptQ->execute(['section_id' => $courseSectionId]);
            $attDeptId = $attDeptQ->fetchColumn();

            // SQLite: INSERT OR REPLACE for upsert
            $stmt = $pdo->prepare(
                "INSERT OR REPLACE INTO attendance (course_section_id, faculty_id, date, total_students, present_count, notes)
                 VALUES (:csid, :fid, :date, :total, :present, :notes)"
            );
            $stmt->execute([
                'csid' => $courseSectionId, 'fid' => $user['id'], 'date' => $date,
                'total' => $totalStudents, 'present' => $presentCount, 'notes' => $notes ?: null
            ]);
            AuditLog::recordEntity(
                $user['id'], 'ATTENDANCE_RECORD', 'attendance', null,
                "Section $courseSectionId on $date: $presentCount/$totalStudents",
                null,
                ['course_section_id' => $courseSectionId, 'date' => $date, 'present_count' => $presentCount, 'total_students' => $totalStudents],
                $attDeptId !== false ? (int) $attDeptId : null
            );
            header('Location: /attendance.php');
            exit;
        }
    }

    if ($action === 'delete_attendance' && $canManage) {
        $id = (int) ($_POST['id'] ?? 0);

        // ── Authorization via centralized policy ──
        $policy->canDeleteAttendance($id);

        $old = $pdo->prepare('SELECT a.course_section_id, a.date, c.department_id FROM attendance a JOIN course_sections cs ON cs.id = a.course_section_id JOIN courses c ON c.id = cs.course_id WHERE a.id = :id');
        $old->execute(['id' => $id]);
        $oldRow = $old->fetch();

        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = $oldRow ? (int) $oldRow['department_id'] : null;
        $pdo->prepare('DELETE FROM attendance WHERE id = :id')->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'ATTENDANCE_DELETE', 'attendance', $id,
            "Deleted attendance id=$id",
            $oldRow ? ['course_section_id' => (int) $oldRow['course_section_id'], 'date' => $oldRow['date']] : null,
            null,
            $deletedDeptId
        );
        header('Location: /attendance.php');
        exit;
    }
}

// Get course sections this user can record attendance for
// ── Read-side department scoping via DepartmentScope ──────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$mySections = $scope->sectionsForAttendance($user['role'], (int) $user['id']);

// Get attendance records
$filterSection = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
$filterDate = $_GET['date'] ?? '';
$attendanceRecords = $scope->attendance($user['role'], (int) $user['id'], $filterSection, $filterDate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Attendance Tracking</h2>

    <!-- Record attendance form -->
    <div class="card" style="margin-bottom:20px;">
      <h3 style="margin-bottom:12px;">Record Attendance</h3>
      <form method="post" action="/attendance.php">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="record_attendance">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
          <div class="field">
            <label>Course Section</label>
            <select name="course_section_id" required>
              <option value="">Select</option>
              <?php foreach ($mySections as $s): ?>
                <option value="<?= $s['id'] ?>"><?= h($s['code'] . ' — ' . $s['course_name'] . ' [' . $s['section_number'] . '] ' . $s['semester'] . ' ' . $s['year']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['course_section_id'])): ?><div class="error"><?= h($errors['course_section_id']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label>Date</label>
            <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
            <?php if (!empty($errors['date'])): ?><div class="error"><?= h($errors['date']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label>Total Students</label>
            <input type="number" name="total_students" min="0" value="0" required>
          </div>
          <div class="field">
            <label>Present Count</label>
            <input type="number" name="present_count" min="0" value="0" required>
            <?php if (!empty($errors['present_count'])): ?><div class="error"><?= h($errors['present_count']) ?></div><?php endif; ?>
          </div>
          <div class="field" style="grid-column: span 2;">
            <label>Notes</label>
            <input type="text" name="notes" placeholder="Optional notes">
          </div>
        </div>
        <div style="margin-top:14px;">
          <button class="btn-sm" type="submit">Record</button>
        </div>
      </form>
    </div>

    <!-- Filter -->
    <form method="get" action="/attendance.php" class="toolbar">
      <select name="section_id" onchange="this.form.submit()">
        <option value="">All sections</option>
        <?php foreach ($mySections as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSection === (int) $s['id'] ? 'selected' : '' ?>>
            <?= h($s['code'] . ' [' . $s['section_number'] . ']') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date" value="<?= h($filterDate) ?>" onchange="this.form.submit()">
      <?php if ($filterSection || $filterDate): ?>
        <a href="/attendance.php" class="btn-sm" style="background:#6b7280;">Clear Filter</a>
      <?php endif; ?>
    </form>

    <!-- Attendance table -->
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Course</th><th>Section</th><th>Present/Total</th><th>%</th><th>Notes</th><th>Recorded By</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php if (empty($attendanceRecords)): ?>
          <tr><td colspan="8" style="text-align:center;color:#6b7280;">No attendance records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($attendanceRecords as $a): ?>
          <tr>
            <td><?= h($a['date']) ?></td>
            <td><?= h($a['course_code'] . ' — ' . $a['course_name']) ?></td>
            <td><?= h($a['section_number'] . ' ' . $a['semester'] . ' ' . $a['year']) ?></td>
            <td><?= (int) $a['present_count'] ?> / <?= (int) $a['total_students'] ?></td>
            <td>
              <?php if ((int) $a['total_students'] > 0): ?>
                <span class="badge" style="<?= ((int)$a['present_count'] / (int)$a['total_students'] < 0.75) ? 'background:#fdecea;color:var(--danger);' : '' ?>">
                  <?= round((int) $a['present_count'] / (int) $a['total_students'] * 100, 1) ?>%
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= h($a['notes'] ?? '') ?></td>
            <td><?= h($a['recorded_by_name'] ?? '(deleted user)') ?></td>
            <?php if ($canManage): ?>
              <td>
                <form method="post" action="/attendance.php" style="display:inline;" onsubmit="return confirm('Delete this attendance record?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete_attendance">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn-sm danger" type="submit">Delete</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
