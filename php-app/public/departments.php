<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$canManage = $user['role'] === 'admin';
$isAdmin = $user['role'] === 'admin';
$pdo = Database::connection();

// ── Centralized write-side authorization ──────────────────
$policy = AuthorizationPolicy::forUser($user, $pdo);

$errors = [];
$editing = null;

// --- Handle POST actions ---
// Authorization is enforced per-action via AuthorizationPolicy below.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Authorization via centralized policy ──
    $policy->canManageDepartment();
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    // Add department
    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        Validate::nameLoose($name, $errors);

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    // Fetch old values for diff
                    $old = $pdo->prepare('SELECT name, is_active FROM departments WHERE id = :id');
                    $old->execute(['id' => $id]);
                    $oldRow = $old->fetch();

                    $stmt = $pdo->prepare(
                        'UPDATE departments SET name=:name, is_active=:is_active WHERE id=:id'
                    );
                    $stmt->execute(['name' => $name, 'is_active' => $isActive, 'id' => $id]);
                    AuditLog::recordEntity(
                        $user['id'], 'DEPARTMENT_UPDATE', 'department', $id,
                        "Updated department id=$id name=$name",
                        ['name' => $oldRow['name'], 'is_active' => (int) $oldRow['is_active']],
                        ['name' => $name, 'is_active' => $isActive]
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO departments (name, is_active) VALUES (:name, :is_active)'
                    );
                    $stmt->execute(['name' => $name, 'is_active' => 1]);
                    $newId = (int) $pdo->lastInsertId();
                    AuditLog::recordEntity(
                        $user['id'], 'DEPARTMENT_CREATE', 'department', $newId,
                        "Created department id=$newId name=$name",
                        null,
                        ['name' => $name, 'is_active' => 1]
                    );
                }
                header('Location: /departments.php');
                exit;
            } catch (Throwable $e) {
                $errors['name'] = 'Department name already exists.';
            }
        }
        $editing = array_merge(['id' => $id], $_POST);
    }

    // Toggle is_active (enable/disable)
    if ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT is_active FROM departments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $newActive = $row['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare('UPDATE departments SET is_active = :val WHERE id = :id');
            $stmt->execute(['val' => $newActive, 'id' => $id]);
            $logAction = $newActive ? 'DEPARTMENT_ENABLE' : 'DEPARTMENT_DISABLE';
            AuditLog::recordEntity(
                $user['id'], $logAction, 'department', $id,
                "Toggled department id=$id to is_active=$newActive",
                ['is_active' => (int) $row['is_active']],
                ['is_active' => $newActive]
            );
        }
        header('Location: /departments.php');
        exit;
    }

    // Hard delete (admin only)
    if ($action === 'delete' && $isAdmin) {
        $id = (int) ($_POST['id'] ?? 0);
        // Fetch old values before deleting
        $old = $pdo->prepare('SELECT name, is_active FROM departments WHERE id = :id');
        $old->execute(['id' => $id]);
        $oldRow = $old->fetch();
        // NOTE: department_id for DEPARTMENT_DELETE is intentionally passed as $id
        // (the department being deleted). After deletion, FK ON DELETE SET NULL will
        // nullify references, but the audit record captures the department that existed.
        // This is the one case where audit department_id may point to a deleted row.
        $stmt = $pdo->prepare('DELETE FROM departments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'DEPARTMENT_DELETE', 'department', $id,
            "Deleted department id=$id",
            $oldRow ? ['name' => $oldRow['name'], 'is_active' => (int) $oldRow['is_active']] : null,
            null,
            $id
        );
        header('Location: /departments.php');
        exit;
    }
}

// --- Edit target from query string ---
if (!$editing && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM departments WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// ── Read-side department scoping via DepartmentScope ──────
$scope = DepartmentScope::forUser($user, $pdo);
$departments = $scope->departments();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Departments — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Departments</h2>

    <?php if ($canManage): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-bottom:12px;"><?= $editing ? 'Edit Department' : 'Add Department' ?></h3>
        <form method="post" action="/departments.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <div class="field">
              <label>Name</label>
              <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" required>
              <?php if (!empty($errors['name'])): ?><div class="error"><?= h($errors['name']) ?></div><?php endif; ?>
            </div>
            <?php if ($editing): ?>
              <div class="field">
                <label>Active</label>
                <label style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                  <input type="checkbox" name="is_active" value="1" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                  <span>Enabled</span>
                </label>
              </div>
            <?php endif; ?>
          </div>
          <div style="margin-top:14px;">
            <button class="btn-sm" type="submit"><?= $editing ? 'Save Changes' : 'Add Department' ?></button>
            <?php if ($editing): ?><a href="/departments.php" class="btn-sm" style="background:#6b7280;">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="toolbar">
      <input type="text" id="filter" placeholder="Search departments...">
    </div>

    <table class="data-table searchable">
      <thead>
        <tr><th>Name</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($departments as $d): ?>
          <tr style="<?= empty($d['is_active']) ? 'opacity:0.5;' : '' ?>">
            <td><?= h($d['name']) ?></td>
            <td>
              <?php if ($d['is_active']): ?>
                <span class="badge" style="background:#16a34a;color:#fff;">Active</span>
              <?php else: ?>
                <span class="badge" style="background:#9ca3af;color:#fff;">Disabled</span>
              <?php endif; ?>
            </td>
            <?php if ($canManage): ?>
              <td>
                <a href="/departments.php?edit=<?= (int) $d['id'] ?>" class="btn-sm">Edit</a>
                <form method="post" action="/departments.php" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <?php if ($d['is_active']): ?>
                    <button class="btn-sm" style="background:#9ca3af;" type="submit" onclick="return confirm('Disable this department?');">Disable</button>
                  <?php else: ?>
                    <button class="btn-sm" style="background:#16a34a;color:#fff;" type="submit">Enable</button>
                  <?php endif; ?>
                </form>
                <?php if ($isAdmin): ?>
                  <form method="post" action="/departments.php" style="display:inline;" onsubmit="return confirm('Permanently delete this department? This will also cascade-delete related courses and set NULL on users.department_id.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button class="btn-sm danger" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
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
