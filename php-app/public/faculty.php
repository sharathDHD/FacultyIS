<?php
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
$editing = null;

// --- Handle POST actions (add / update / delete) ---
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
        $old = $pdo->prepare('SELECT user_id, name, email, department_id FROM users WHERE id = :id AND role = :role');
        $old->execute(['id' => $id, 'role' => 'faculty']);
        $oldRow = $old->fetch();

        // ── Authorization via centralized policy ──
        $policy->canDeleteFaculty($id);

        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = $oldRow ? (int) $oldRow['department_id'] : null;
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role');
        $stmt->execute(['id' => $id, 'role' => 'faculty']);
        AuditLog::recordEntity(
            $user['id'], 'FACULTY_DELETE', 'user', $id,
            "Deleted faculty id=$id",
            $oldRow ? ['user_id' => $oldRow['user_id'], 'name' => $oldRow['name']] : null,
            null,
            $deletedDeptId
        );
        header('Location: /faculty.php');
        exit;
    }

    if ($action === 'update_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $newRole = $_POST['role'] ?? '';

        // ── Authorization via centralized policy ──
        $policy->canManageRole($id, $newRole);

        if (!in_array($newRole, ['faculty', 'hod', 'admin'], true)) {
            $errors['role'] = 'Invalid role.';
        } else {
            $old = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $old->execute(['id' => $id]);
            $oldRole = $old->fetchColumn();
            $stmt = $pdo->prepare('UPDATE users SET role = :role, auth_version = auth_version + 1 WHERE id = :id');
            $stmt->execute(['role' => $newRole, 'id' => $id]);
            AuditLog::recordEntity(
                $user['id'], 'FACULTY_ROLE_CHANGE', 'user', $id,
                "Changed user id=$id to role=$newRole",
                ['role' => $oldRole],
                ['role' => $newRole]
            );
        }
        header('Location: /faculty.php');
        exit;
    }

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $dob = $_POST['dob'] ?? '';
        $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
        $id = (int) ($_POST['id'] ?? 0);

        Validate::name($name, $errors);
        Validate::email($email, $errors);
        Validate::phone($phone, $errors);

        if (empty($errors)) {
            // ── Authorization via centralized policy ──
            // For create: authorize the target department.
            // For update: authorize both the existing object and requested new state.
            if ($id > 0) {
                $policy->canEditFaculty($id, $departmentId ?? 0);
            } else {
                $policy->canCreateFaculty($departmentId ?? 0);
            }
        }

        if (empty($errors)) {
            if ($id > 0) {
                // Update existing faculty
                $old = $pdo->prepare("SELECT name, email, phone, department_id FROM users WHERE id = :id AND role='faculty'");
                $old->execute(['id' => $id]);
                $oldRow = $old->fetch();
                $stmt = $pdo->prepare(
                    "UPDATE users SET name=:name, email=:email, phone=:phone, gender=:gender, dob=:dob, department_id=:dept
                     WHERE id=:id AND role='faculty'"
                );
                $stmt->execute([
                    'name' => $name, 'email' => $email, 'phone' => $phone,
                    'gender' => $gender ?: null, 'dob' => $dob ?: null,
                    'dept' => $departmentId, 'id' => $id
                ]);
                AuditLog::recordEntity(
                    $user['id'], 'FACULTY_UPDATE', 'user', $id,
                    "Updated faculty id=$id",
                    $oldRow ? ['name' => $oldRow['name'], 'email' => $oldRow['email'], 'phone' => $oldRow['phone'], 'department_id' => (int) $oldRow['department_id']] : null,
                    ['name' => $name, 'email' => $email, 'phone' => $phone, 'department_id' => $departmentId]
                );
            } else {
                // Admin-created faculty: check for duplicate email first.
                $dupCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email');
                $dupCheck->execute(['email' => $email]);
                if ($dupCheck->fetch()) {
                    $errors['email'] = 'A user with this email already exists.';
                } else {
                    // Collision-safe unique user_id.
                    $tempUserId = Auth::generateUniqueUserId($name, $pdo);
                    $tempPassword = bin2hex(random_bytes(6));
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (user_id, name, email, phone, gender, dob, role, department_id, password_hash)
                         VALUES (:uid, :name, :email, :phone, :gender, :dob, 'faculty', :dept, :hash)"
                    );
                    $stmt->execute([
                        'uid' => $tempUserId,
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'gender' => $gender ?: null,
                        'dob' => $dob ?: null,
                        'dept' => $departmentId,
                        'hash' => password_hash($tempPassword, PASSWORD_BCRYPT),
                    ]);
                    AuditLog::recordEntity(
                        $user['id'], 'FACULTY_CREATE', 'user', (int) $pdo->lastInsertId(),
                        "Created faculty $tempUserId",
                        null,
                        ['user_id' => $tempUserId, 'name' => $name, 'email' => $email, 'role' => 'faculty']
                    );
                    $_SESSION['flash_faculty'] = "Created $tempUserId — temp password: $tempPassword (share securely, do not reuse)";
                }
            }
            if (empty($errors)) {
                header('Location: /faculty.php');
                exit;
            }
        }
        $editing = array_merge(['id' => $id], $_POST);
    }
}

// --- Edit target from query string ---
// Authorization: HOD can only edit faculty in their own department.
// The edit form pre-populates only if the target is within scope.
if (!$editing && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($user['role'] === 'hod') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'faculty' AND department_id = :dept");
        $stmt->execute(['id' => $editId, 'dept' => (int) $user['department_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'faculty'");
        $stmt->execute(['id' => $editId]);
    }
    $editing = $stmt->fetch() ?: null;
}

// ── Read-side department scoping via DepartmentScope ──────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$departments = $scope->departments();
$faculty = $scope->facultyList();

$flashFaculty = $_SESSION['flash_faculty'] ?? null;
unset($_SESSION['flash_faculty']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Faculty Directory</h2>

    <?php if ($flashFaculty): ?>
      <div class="flash success"><?= h($flashFaculty) ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-bottom:12px;"><?= $editing ? 'Edit Faculty' : 'Add Faculty' ?></h3>
        <form method="post" action="/faculty.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <div class="field">
              <label>Name</label>
              <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" onkeypress="return Ischar(event)" required>
              <?php if (!empty($errors['name'])): ?><div class="error"><?= h($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="field">
              <label>Email</label>
              <input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>" required>
              <?php if (!empty($errors['email'])): ?><div class="error"><?= h($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="field">
              <label>Phone</label>
              <input type="text" name="phone" maxlength="10" value="<?= h($editing['phone'] ?? '') ?>" onkeypress="return IsNum(event)" required>
              <?php if (!empty($errors['phone'])): ?><div class="error"><?= h($errors['phone']) ?></div><?php endif; ?>
            </div>
            <div class="field">
              <label>Gender</label>
              <select name="gender">
                <option value="">Select</option>
                <option value="male" <?= ($editing['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($editing['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= ($editing['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <div class="field">
              <label>Date of Birth</label>
              <input type="date" name="dob" min="1950-01-01" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" value="<?= h($editing['dob'] ?? '') ?>">
            </div>
            <div class="field">
              <label>Department</label>
              <select name="department_id">
                <option value="">—</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ($editing['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="margin-top:14px;">
            <button class="btn-sm" type="submit"><?= $editing ? 'Save Changes' : 'Add Faculty' ?></button>
            <?php if ($editing): ?><a href="/faculty.php" class="btn-sm" style="background:#6b7280;">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="toolbar">
      <input type="text" id="filter" placeholder="Search faculty...">
    </div>

    <table class="data-table searchable">
      <thead>
        <tr><th>User ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Dept</th><th>Gender</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($faculty as $f): ?>
          <tr>
            <td><?= h($f['user_id']) ?></td>
            <td><?= h($f['name']) ?></td>
            <td><?= h($f['email']) ?></td>
            <td><?= h($f['phone']) ?></td>
            <td><?= h($f['department_name'] ?? '—') ?></td>
            <td><?= h($f['gender'] ?? '—') ?></td>
            <?php if ($canManage): ?>
              <td>
                <a href="/faculty.php?edit=<?= (int) $f['id'] ?>" class="btn-sm">Edit</a>
                <?php if ($user['role'] === 'admin'): ?>
                  <form method="post" action="/faculty.php" style="display:inline;" onsubmit="return confirm('Change role for this user?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <select name="role" onchange="this.form.submit()" style="font-size:12px;padding:2px 6px;">
                      <option value="faculty" selected>faculty</option>
                      <option value="hod">hod</option>
                      <option value="admin">admin</option>
                    </select>
                  </form>
                <?php endif; ?>
                <form method="post" action="/faculty.php" style="display:inline;" onsubmit="return confirm('Delete this faculty member?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
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
<script src="/assets/js/validate.js"></script>
</body>
</html>
