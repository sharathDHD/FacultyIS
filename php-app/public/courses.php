<?php
// Version: v2.12

require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$canManage = in_array($user['role'], ['hod', 'admin'], true);
$pdo = Database::connection();

// ── Centralized write-side authorization ──────────────────
// Every mutation goes through AuthorizationPolicy.
// Pages are now thin controllers — the policy owns the security decision.
$policy = AuthorizationPolicy::forUser($user, $pdo);

$errors = [];
$editingCourse = null;
$editingSection = null;

// --- Handle POST actions ---
// Authorization is enforced per-action via AuthorizationPolicy below.
// CSRF is verified first — every POST must carry a valid token regardless of role.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    // --- Course CRUD ---
    if ($action === 'delete_course') {
        // ── Authorization via centralized policy ──
        $policy->canDeleteCourse();
        $id = (int) ($_POST['id'] ?? 0);
        $old = $pdo->prepare('SELECT code, name, is_active, department_id FROM courses WHERE id = :id');
        $old->execute(['id' => $id]);
        $oldRow = $old->fetch();
        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = $oldRow ? (int) $oldRow['department_id'] : null;
        $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'COURSE_DELETE', 'course', $id,
            "Deleted course id=$id",
            $oldRow ? ['code' => $oldRow['code'], 'name' => $oldRow['name'], 'is_active' => (int) $oldRow['is_active']] : null,
            null,
            $deletedDeptId
        );
        header('Location: /courses.php');
        exit;
    }

    if ($action === 'toggle_course_active') {
        $id = (int) ($_POST['id'] ?? 0);
        // ── Authorization via centralized policy ──
        // Toggle is a form of edit: authorize existing course ownership
        $deptFetch = $pdo->prepare('SELECT department_id FROM courses WHERE id = :id');
        $deptFetch->execute(['id' => $id]);
        $courseDeptId = $deptFetch->fetchColumn();
        $policy->canEditCourse($id, $courseDeptId !== false ? (int) $courseDeptId : 0);
        $stmt = $pdo->prepare('SELECT is_active FROM courses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $newActive = $row['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare('UPDATE courses SET is_active = :val WHERE id = :id');
            $stmt->execute(['val' => $newActive, 'id' => $id]);
            $logAction = $newActive ? 'COURSE_ENABLE' : 'COURSE_DISABLE';
            AuditLog::recordEntity(
                $user['id'], $logAction, 'course', $id,
                "Toggled course id=$id to is_active=$newActive",
                ['is_active' => (int) $row['is_active']],
                ['is_active' => $newActive]
            );
        }
        header('Location: /courses.php');
        exit;
    }

    if ($action === 'save_course') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $credits = (int) ($_POST['credits'] ?? 3) ?: 3;
        $id = (int) ($_POST['id'] ?? 0);

        // ── Authorization via centralized policy ──
        // For create: authorize the target department.
        // For update: authorize both the existing object and requested new state.
        if (empty($errors)) {
            if ($id > 0) {
                $policy->canEditCourse($id, $departmentId);
            } else {
                $policy->canCreateCourse($departmentId);
            }
        }

        if ($code === '' || !preg_match('/^[A-Za-z0-9]{2,20}$/', $code)) {
            $errors['code'] = 'Course code must be 2-20 alphanumeric chars.';
        }
        if ($name === '') {
            $errors['name'] = 'Course name is required.';
        }
        if ($departmentId <= 0) {
            $errors['department_id'] = 'Select a department.';
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    $isActive = isset($_POST['is_active']) ? 1 : 0;
                    // Fetch old values for diff
                    $old = $pdo->prepare('SELECT code, name, department_id, credits, is_active FROM courses WHERE id = :id');
                    $old->execute(['id' => $id]);
                    $oldRow = $old->fetch();
                    $stmt = $pdo->prepare(
                        'UPDATE courses SET code=:code, name=:name, department_id=:dept, credits=:credits, is_active=:is_active WHERE id=:id'
                    );
                    $stmt->execute(['code' => $code, 'name' => $name, 'dept' => $departmentId, 'credits' => $credits, 'is_active' => $isActive, 'id' => $id]);
                    AuditLog::recordEntity(
                        $user['id'], 'COURSE_UPDATE', 'course', $id,
                        "Updated course $code",
                        $oldRow ? ['code' => $oldRow['code'], 'name' => $oldRow['name'], 'department_id' => (int) $oldRow['department_id'], 'credits' => (int) $oldRow['credits'], 'is_active' => (int) $oldRow['is_active']] : null,
                        ['code' => $code, 'name' => $name, 'department_id' => $departmentId, 'credits' => $credits, 'is_active' => $isActive]
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO courses (code, name, department_id, credits) VALUES (:code, :name, :dept, :credits)'
                    );
                    $stmt->execute(['code' => $code, 'name' => $name, 'dept' => $departmentId, 'credits' => $credits]);
                    $newId = (int) $pdo->lastInsertId();
                    AuditLog::recordEntity(
                        $user['id'], 'COURSE_CREATE', 'course', $newId,
                        "Created course $code",
                        null,
                        ['code' => $code, 'name' => $name, 'department_id' => $departmentId, 'credits' => $credits, 'is_active' => 1]
                    );
                }
                header('Location: /courses.php');
                exit;
            } catch (Throwable $e) {
                $errors['code'] = 'Course code already exists.';
            }
        }
        $editingCourse = array_merge(['id' => $id], $_POST);
    }

    // --- Course Section CRUD ---
    if ($action === 'delete_section') {
        $id = (int) ($_POST['id'] ?? 0);
        // ── Authorization via centralized policy ──
        $policy->canDeleteSection($id);
        $old = $pdo->prepare('SELECT cs.course_id, cs.faculty_id, cs.semester, cs.year, c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id');
        $old->execute(['id' => $id]);
        $oldRow = $old->fetch();
        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = $oldRow ? (int) $oldRow['department_id'] : null;
        $stmt = $pdo->prepare('DELETE FROM course_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'SECTION_DELETE', 'course_section', $id,
            "Deleted section id=$id",
            $oldRow ? ['course_id' => (int) $oldRow['course_id'], 'faculty_id' => (int) $oldRow['faculty_id'], 'semester' => $oldRow['semester'], 'year' => (int) $oldRow['year']] : null,
            null,
            $deletedDeptId
        );
        header('Location: /courses.php?tab=sections');
        exit;
    }

    if ($action === 'save_section') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);
        $semester = trim($_POST['semester'] ?? '');
        $year = (int) ($_POST['year'] ?? 0);
        $sectionNum = trim($_POST['section_number'] ?? 'A');
        $room = trim($_POST['room'] ?? '');
        $schedDay = trim($_POST['schedule_day'] ?? '');
        $schedTime = trim($_POST['schedule_time'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        // ── Authorization via centralized policy ──
        // For create: authorize the course + faculty department.
        // For update: authorize both the existing object and requested new state.
        if (empty($errors)) {
            if ($id > 0) {
                $policy->canEditSection($id, $courseId, $facultyId);
            } else {
                $policy->canCreateSection($courseId, $facultyId);
            }
        }

        if ($courseId <= 0) $errors['course_id'] = 'Select a course.';
        if ($facultyId <= 0) $errors['faculty_id'] = 'Select a faculty member.';
        if (!in_array($semester, ['Odd', 'Even'], true)) $errors['semester'] = 'Select Odd or Even.';
        if ($year < 2000 || $year > 2100) $errors['year'] = 'Enter a valid year.';

        if (empty($errors)) {
            if ($id > 0) {
                $old = $pdo->prepare('SELECT course_id, faculty_id, semester, year, section_number FROM course_sections WHERE id = :id');
                $old->execute(['id' => $id]);
                $oldRow = $old->fetch();
                $stmt = $pdo->prepare(
                    'UPDATE course_sections SET course_id=:course, faculty_id=:fac, semester=:sem, year=:yr,
                     section_number=:sec, room=:room, schedule_day=:sday, schedule_time=:stime WHERE id=:id'
                );
                $stmt->execute([
                    'course' => $courseId, 'fac' => $facultyId, 'sem' => $semester, 'yr' => $year,
                    'sec' => $sectionNum, 'room' => $room ?: null, 'sday' => $schedDay ?: null,
                    'stime' => $schedTime ?: null, 'id' => $id
                ]);
                AuditLog::recordEntity(
                    $user['id'], 'SECTION_UPDATE', 'course_section', $id,
                    "Updated section id=$id",
                    $oldRow ? ['course_id' => (int) $oldRow['course_id'], 'faculty_id' => (int) $oldRow['faculty_id'], 'semester' => $oldRow['semester'], 'year' => (int) $oldRow['year']] : null,
                    ['course_id' => $courseId, 'faculty_id' => $facultyId, 'semester' => $semester, 'year' => $year]
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO course_sections (course_id, faculty_id, semester, year, section_number, room, schedule_day, schedule_time)
                     VALUES (:course, :fac, :sem, :yr, :sec, :room, :sday, :stime)'
                );
                $stmt->execute([
                    'course' => $courseId, 'fac' => $facultyId, 'sem' => $semester, 'yr' => $year,
                    'sec' => $sectionNum, 'room' => $room ?: null, 'sday' => $schedDay ?: null,
                    'stime' => $schedTime ?: null
                ]);
                $newId = (int) $pdo->lastInsertId();
                AuditLog::recordEntity(
                    $user['id'], 'SECTION_CREATE', 'course_section', $newId,
                    "Created section for course_id=$courseId",
                    null,
                    ['course_id' => $courseId, 'faculty_id' => $facultyId, 'semester' => $semester, 'year' => $year, 'section_number' => $sectionNum]
                );
            }
            header('Location: /courses.php?tab=sections');
            exit;
        }
        $editingSection = array_merge(['id' => $id], $_POST);
    }
}

// --- Edit targets from query string ---
// Authorization: HOD can only edit courses/sections in their own department.
// The edit form pre-populates only if the target is within scope.
if (!$editingCourse && isset($_GET['edit_course'])) {
    $editCourseId = (int) $_GET['edit_course'];
    if ($user['role'] === 'hod') {
        $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = :id AND department_id = :dept');
        $stmt->execute(['id' => $editCourseId, 'dept' => (int) $user['department_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = :id');
        $stmt->execute(['id' => $editCourseId]);
    }
    $editingCourse = $stmt->fetch() ?: null;
}
if (!$editingSection && isset($_GET['edit_section'])) {
    $editSectionId = (int) $_GET['edit_section'];
    if ($user['role'] === 'hod') {
        $stmt = $pdo->prepare(
            'SELECT cs.* FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id AND c.department_id = :dept'
        );
        $stmt->execute(['id' => $editSectionId, 'dept' => (int) $user['department_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM course_sections WHERE id = :id');
        $stmt->execute(['id' => $editSectionId]);
    }
    $editingSection = $stmt->fetch() ?: null;
}

// ── Read-side department scoping via DepartmentScope ──────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$departments = $scope->departments();
$courses = $scope->courses();
$facultyList = $scope->facultyDropdown();
$sections = $scope->sections();

$activeTab = $_GET['tab'] ?? 'courses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Courses — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Courses &amp; Sections</h2>

    <!-- Tab navigation -->
    <div class="toolbar" style="margin-bottom:20px;">
      <a href="/courses.php?tab=courses" class="btn-sm" style="<?= $activeTab === 'courses' ? 'background:var(--brand-blue-dark);' : 'background:#6b7280;' ?>">Courses</a>
      <a href="/courses.php?tab=sections" class="btn-sm" style="<?= $activeTab === 'sections' ? 'background:var(--brand-blue-dark);' : 'background:#6b7280;' ?>">Section Assignments</a>
    </div>

    <?php if ($activeTab === 'courses'): ?>
      <!-- ============ COURSES TAB ============ -->
      <?php if ($canManage): ?>
        <div class="card" style="margin-bottom:20px;">
          <h3 style="margin-bottom:12px;"><?= $editingCourse ? 'Edit Course' : 'Add Course' ?></h3>
          <form method="post" action="/courses.php?tab=courses">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_course">
            <?php if ($editingCourse): ?><input type="hidden" name="id" value="<?= (int) $editingCourse['id'] ?>"><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
              <div class="field">
                <label>Code</label>
                <input type="text" name="code" value="<?= h($editingCourse['code'] ?? '') ?>" placeholder="CS101" required>
                <?php if (!empty($errors['code'])): ?><div class="error"><?= h($errors['code']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Name</label>
                <input type="text" name="name" value="<?= h($editingCourse['name'] ?? '') ?>" required>
                <?php if (!empty($errors['name'])): ?><div class="error"><?= h($errors['name']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Department</label>
                <select name="department_id" required>
                  <option value="">Select</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($editingCourse['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['department_id'])): ?><div class="error"><?= h($errors['department_id']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Credits</label>
                <input type="number" name="credits" min="1" max="10" value="<?= h($editingCourse['credits'] ?? 3) ?>">
              </div>
              <?php if ($editingCourse): ?>
              <div class="field">
                <label>Active</label>
                <label style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                  <input type="checkbox" name="is_active" value="1" <?= ($editingCourse['is_active'] ?? 1) ? 'checked' : '' ?>>
                  <span>Enabled</span>
                </label>
              </div>
              <?php endif; ?>
            </div>
            <div style="margin-top:14px;">
              <button class="btn-sm" type="submit"><?= $editingCourse ? 'Save Changes' : 'Add Course' ?></button>
              <?php if ($editingCourse): ?><a href="/courses.php?tab=courses" class="btn-sm" style="background:#6b7280;">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="toolbar">
        <input type="text" id="filter" placeholder="Search courses...">
      </div>
      <table class="data-table searchable">
        <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Credits</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($courses as $c): ?>
            <tr style="<?= empty($c['is_active']) ? 'opacity:0.5;' : '' ?>">
              <td><?= h($c['code']) ?></td>
              <td><?= h($c['name']) ?></td>
              <td><?= h($c['department_name']) ?></td>
              <td><?= (int) $c['credits'] ?></td>
              <td>
                <?php if (!empty($c['is_active'])): ?>
                  <span class="badge" style="background:#16a34a;color:#fff;">Active</span>
                <?php else: ?>
                  <span class="badge" style="background:#9ca3af;color:#fff;">Disabled</span>
                <?php endif; ?>
              </td>
              <?php if ($canManage): ?>
                <td>
                  <a href="/courses.php?tab=courses&edit_course=<?= (int) $c['id'] ?>" class="btn-sm">Edit</a>
                  <form method="post" action="/courses.php?tab=courses" style="display:inline;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="toggle_course_active">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <?php if (!empty($c['is_active'])): ?>
                      <button class="btn-sm" style="background:#9ca3af;" type="submit" onclick="return confirm('Disable this course?');">Disable</button>
                    <?php else: ?>
                      <button class="btn-sm" style="background:#16a34a;color:#fff;" type="submit">Enable</button>
                    <?php endif; ?>
                  </form>
                  <?php if ($user['role'] === 'admin'): ?>
                    <form method="post" action="/courses.php?tab=courses" style="display:inline;" onsubmit="return confirm('Permanently delete this course and all its sections?');">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="delete_course">
                      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="btn-sm danger" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php else: ?>
      <!-- ============ SECTIONS TAB ============ -->
      <?php if ($canManage): ?>
        <div class="card" style="margin-bottom:20px;">
          <h3 style="margin-bottom:12px;"><?= $editingSection ? 'Edit Section Assignment' : 'Add Section Assignment' ?></h3>
          <form method="post" action="/courses.php?tab=sections">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_section">
            <?php if ($editingSection): ?><input type="hidden" name="id" value="<?= (int) $editingSection['id'] ?>"><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
              <div class="field">
                <label>Course</label>
                <select name="course_id" required>
                  <option value="">Select</option>
                  <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($editingSection['course_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= h($c['code'] . ' — ' . $c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['course_id'])): ?><div class="error"><?= h($errors['course_id']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Faculty</label>
                <select name="faculty_id" required>
                  <option value="">Select</option>
                  <?php foreach ($facultyList as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= ($editingSection['faculty_id'] ?? '') == $f['id'] ? 'selected' : '' ?>><?= h($f['name'] . ' (' . $f['department_name'] . ')') ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['faculty_id'])): ?><div class="error"><?= h($errors['faculty_id']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Semester</label>
                <select name="semester" required>
                  <option value="">Select</option>
                  <option value="Odd" <?= ($editingSection['semester'] ?? '') === 'Odd' ? 'selected' : '' ?>>Odd</option>
                  <option value="Even" <?= ($editingSection['semester'] ?? '') === 'Even' ? 'selected' : '' ?>>Even</option>
                </select>
                <?php if (!empty($errors['semester'])): ?><div class="error"><?= h($errors['semester']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Year</label>
                <input type="number" name="year" min="2000" max="2100" value="<?= h($editingSection['year'] ?? date('Y')) ?>" required>
                <?php if (!empty($errors['year'])): ?><div class="error"><?= h($errors['year']) ?></div><?php endif; ?>
              </div>
              <div class="field">
                <label>Section</label>
                <input type="text" name="section_number" value="<?= h($editingSection['section_number'] ?? 'A') ?>" maxlength="10">
              </div>
              <div class="field">
                <label>Room</label>
                <input type="text" name="room" value="<?= h($editingSection['room'] ?? '') ?>" placeholder="Room 101">
              </div>
              <div class="field">
                <label>Schedule Day</label>
                <input type="text" name="schedule_day" value="<?= h($editingSection['schedule_day'] ?? '') ?>" placeholder="Mon-Wed">
              </div>
              <div class="field">
                <label>Schedule Time</label>
                <input type="text" name="schedule_time" value="<?= h($editingSection['schedule_time'] ?? '') ?>" placeholder="10:00-11:30">
              </div>
            </div>
            <div style="margin-top:14px;">
              <button class="btn-sm" type="submit"><?= $editingSection ? 'Save Changes' : 'Add Section' ?></button>
              <?php if ($editingSection): ?><a href="/courses.php?tab=sections" class="btn-sm" style="background:#6b7280;">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="toolbar">
        <input type="text" id="filter" placeholder="Search sections...">
      </div>
      <table class="data-table searchable">
        <thead>
          <tr><th>Course</th><th>Section</th><th>Faculty</th><th>Semester</th><th>Year</th><th>Room</th><th>Schedule</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($sections as $s): ?>
            <tr>
              <td><?= h($s['course_code'] . ' — ' . $s['course_name']) ?></td>
              <td><?= h($s['section_number']) ?></td>
              <td><?= h($s['faculty_name']) ?></td>
              <td><?= h($s['semester']) ?></td>
              <td><?= (int) $s['year'] ?></td>
              <td><?= h($s['room'] ?? '—') ?></td>
              <td><?= h(($s['schedule_day'] ?? '') . ' ' . ($s['schedule_time'] ?? '')) ?: '—' ?></td>
              <?php if ($canManage): ?>
                <td>
                  <a href="/courses.php?tab=sections&edit_section=<?= (int) $s['id'] ?>" class="btn-sm">Edit</a>
                  <form method="post" action="/courses.php?tab=sections" style="display:inline;" onsubmit="return confirm('Delete this section assignment?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn-sm danger" type="submit">Delete</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
