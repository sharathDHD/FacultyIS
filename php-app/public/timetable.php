<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$canManage = in_array($user['role'], ['hod', 'admin'], true);
$pdo = Database::connection();

// ── Centralized write-side authorization ──────────────────
$policy = AuthorizationPolicy::forUser($user, $pdo);

$errors = [];
$editing = null;

// --- Handle POST ---
// Authorization is enforced per-action via AuthorizationPolicy below.
// CSRF is verified first — every POST must carry a valid token regardless of role.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        // ── Authorization via centralized policy ──
        $policy->canDeleteTimetable($id);

        $old = $pdo->prepare('SELECT department_id, day_of_week, start_time, end_time, room FROM timetable_entries WHERE id = :id');
        $old->execute(['id' => $id]);
        $oldRow = $old->fetch();

        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = $oldRow ? (int) $oldRow['department_id'] : null;
        $pdo->prepare('DELETE FROM timetable_entries WHERE id = :id')->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'TIMETABLE_DELETE', 'timetable_entry', $id,
            "Deleted entry id=$id",
            $oldRow ? ['department_id' => (int) $oldRow['department_id'], 'day_of_week' => (int) $oldRow['day_of_week'], 'start_time' => $oldRow['start_time'], 'end_time' => $oldRow['end_time'], 'room' => $oldRow['room']] : null,
            null,
            $deletedDeptId
        );
        header('Location: /timetable.php');
        exit;
    }

    if ($action === 'save') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $courseSectionId = (int) ($_POST['course_section_id'] ?? 0) ?: null;
        $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        if ($departmentId <= 0) $errors['department_id'] = 'Select a department.';
        if ($dayOfWeek < 1 || $dayOfWeek > 6) $errors['day_of_week'] = 'Select a day.';
        if (!$startTime) $errors['start_time'] = 'Start time required.';
        if (!$endTime) $errors['end_time'] = 'End time required.';

        // ── Authorization via centralized policy ──
        // For create: authorize the target department.
        // For update: authorize both the existing object and requested new state.
        if (empty($errors)) {
            if ($id > 0) {
                $policy->canEditTimetable($id, $departmentId);
            } else {
                $policy->canCreateTimetable($departmentId);
            }
        }

        // ── Course section / department consistency check ────────
        // If a course_section_id is supplied, verify it belongs to the
        // same department as the timetable entry to prevent inconsistent records.
        if (empty($errors) && $courseSectionId) {
            $stmt = $pdo->prepare(
                'SELECT c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id'
            );
            $stmt->execute(['id' => $courseSectionId]);
            $sectionDept = $stmt->fetchColumn();
            if ($sectionDept === false) {
                $errors['course_section_id'] = 'The selected course section does not exist.';
            } elseif ((int) $sectionDept !== $departmentId) {
                $errors['course_section_id'] = 'The selected course section does not belong to this department.';
            }
        }

        if (empty($errors)) {
            if ($id > 0) {
                $old = $pdo->prepare('SELECT department_id, course_section_id, day_of_week, start_time, end_time, room FROM timetable_entries WHERE id = :id');
                $old->execute(['id' => $id]);
                $oldRow = $old->fetch();
                $stmt = $pdo->prepare(
                    'UPDATE timetable_entries SET department_id=:dept, course_section_id=:csid, day_of_week=:day,
                     start_time=:start, end_time=:end, room=:room WHERE id=:id'
                );
                $stmt->execute([
                    'dept' => $departmentId, 'csid' => $courseSectionId, 'day' => $dayOfWeek,
                    'start' => $startTime, 'end' => $endTime, 'room' => $room ?: null, 'id' => $id
                ]);
                AuditLog::recordEntity(
                    $user['id'], 'TIMETABLE_UPDATE', 'timetable_entry', $id,
                    "Updated entry id=$id",
                    $oldRow ? ['department_id' => (int) $oldRow['department_id'], 'day_of_week' => (int) $oldRow['day_of_week'], 'start_time' => $oldRow['start_time'], 'end_time' => $oldRow['end_time']] : null,
                    ['department_id' => $departmentId, 'day_of_week' => $dayOfWeek, 'start_time' => $startTime, 'end_time' => $endTime]
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO timetable_entries (department_id, course_section_id, day_of_week, start_time, end_time, room)
                     VALUES (:dept, :csid, :day, :start, :end, :room)'
                );
                $stmt->execute([
                    'dept' => $departmentId, 'csid' => $courseSectionId, 'day' => $dayOfWeek,
                    'start' => $startTime, 'end' => $endTime, 'room' => $room ?: null
                ]);
                $newId = (int) $pdo->lastInsertId();
                AuditLog::recordEntity(
                    $user['id'], 'TIMETABLE_CREATE', 'timetable_entry', $newId,
                    "Created timetable entry",
                    null,
                    ['department_id' => $departmentId, 'day_of_week' => $dayOfWeek, 'start_time' => $startTime, 'end_time' => $endTime, 'room' => $room]
                );
            }
            header('Location: /timetable.php');
            exit;
        }
        $editing = array_merge(['id' => $id], $_POST);
    }
}

if (!$editing && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    // ── Edit GET authorization: non-admin users can only edit entries in their own department ──
    if ($user['role'] !== 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM timetable_entries WHERE id = :id AND department_id = :dept');
        $stmt->execute(['id' => $editId, 'dept' => (int) $user['department_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM timetable_entries WHERE id = :id');
        $stmt->execute(['id' => $editId]);
    }
    $editing = $stmt->fetch() ?: null;
}

// ── Read-side department scoping via DepartmentScope ──────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$departments = $scope->departments();

// Get all course sections for the dropdown (scoped via DepartmentScope)
$allSections = $scope->sections();

// Filter by department (scoped via DepartmentScope)
// Non-admin users can only view their own department
if (!$scope->isAdmin()) {
    $filterDept = (int) ($user['department_id'] ?? 0) ?: null;
} else {
    $filterDept = isset($_GET['dept_id']) ? (int) $_GET['dept_id'] : ($user['department_id'] ?? null);
}
$timetableEntries = $scope->timetable($filterDept);

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Timetable — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Weekly Timetable</h2>

    <?php if ($canManage): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-bottom:12px;"><?= $editing ? 'Edit Entry' : 'Add Timetable Entry' ?></h3>
        <form method="post" action="/timetable.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
            <div class="field">
              <label>Department</label>
              <select name="department_id" required>
                <option value="">Select</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ($editing['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Course Section (optional)</label>
              <select name="course_section_id">
                <option value="">— none —</option>
                <?php foreach ($allSections as $s): ?>
                  <option value="<?= $s['id'] ?>" <?= ($editing['course_section_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                    <?= h($s['code'] . ' [' . $s['section_number'] . '] — ' . $s['faculty_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Day</label>
              <select name="day_of_week" required>
                <?php foreach ($dayNames as $num => $name): ?>
                  <option value="<?= $num ?>" <?= ($editing['day_of_week'] ?? '') == $num ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Start Time</label>
              <input type="time" name="start_time" value="<?= h($editing['start_time'] ?? '10:00') ?>" required>
            </div>
            <div class="field">
              <label>End Time</label>
              <input type="time" name="end_time" value="<?= h($editing['end_time'] ?? '11:30') ?>" required>
            </div>
            <div class="field">
              <label>Room</label>
              <input type="text" name="room" value="<?= h($editing['room'] ?? '') ?>" placeholder="Room 101">
            </div>
          </div>
          <div style="margin-top:14px;">
            <button class="btn-sm" type="submit"><?= $editing ? 'Save Changes' : 'Add Entry' ?></button>
            <?php if ($editing): ?><a href="/timetable.php" class="btn-sm" style="background:#6b7280;">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <!-- Department filter -->
    <form method="get" action="/timetable.php" class="toolbar">
      <select name="dept_id" onchange="this.form.submit()">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- Timetable grid view -->
    <div style="overflow-x:auto;">
      <table class="data-table" style="min-width:800px;">
        <thead>
          <tr>
            <th>Day</th><th>Time</th><th>Course</th><th>Section</th><th>Faculty</th><th>Room</th><th>Department</th>
            <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($timetableEntries)): ?>
            <tr><td colspan="8" style="text-align:center;color:#6b7280;">No timetable entries. Add entries above.</td></tr>
          <?php endif; ?>
          <?php foreach ($timetableEntries as $e): ?>
            <tr>
              <td><?= h($dayNames[$e['day_of_week']] ?? 'Day ' . $e['day_of_week']) ?></td>
              <td><?= h(substr($e['start_time'], 0, 5) . ' - ' . substr($e['end_time'], 0, 5)) ?></td>
              <td><?= h(($e['course_code'] ?? '') . ' ' . ($e['course_name'] ?? '')) ?: '—' ?></td>
              <td><?= h($e['section_number'] ?? '—') ?></td>
              <td><?= h($e['faculty_name'] ?? '—') ?></td>
              <td><?= h($e['room'] ?? '—') ?></td>
              <td><?= h($e['department_name']) ?></td>
              <?php if ($canManage): ?>
                <td>
                  <a href="/timetable.php?edit=<?= (int) $e['id'] ?>" class="btn-sm">Edit</a>
                  <form method="post" action="/timetable.php" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
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
</div>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
