<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$pdo = Database::connection();

$errors = [];
$uploadDir = getenv('UPLOAD_DIR') ?: dirname(__DIR__, 2) . '/data/uploads/';
$uploadDir = rtrim($uploadDir, '/') . '/';

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$canUpload = true; // All authenticated users can upload
$canDelete = in_array($user['role'], ['hod', 'admin'], true);

// ── Centralized write-side authorization ──────────────────
$policy = AuthorizationPolicy::forUser($user, $pdo);

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && $canUpload) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'other';
        $courseId = (int) ($_POST['course_id'] ?? 0) ?: null;
        $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;

        if ($title === '') $errors['title'] = 'Title is required.';
        if (!in_array($category, ['syllabus', 'notes', 'assignment', 'exam', 'other'], true)) {
            $category = 'other';
        }

        // ── Authorization via centralized policy ──
        if ($departmentId === null || $departmentId <= 0) {
            // Default to own department if not specified
            $departmentId = (int) $user['department_id'];
        }
        $policy->canUploadDocument($departmentId);

        // If a course_id is supplied, verify it exists and belongs to the
        // document's department to prevent inconsistent records.
        if ($courseId && empty($errors)) {
            $stmt = $pdo->prepare('SELECT department_id FROM courses WHERE id = :id');
            $stmt->execute(['id' => $courseId]);
            $courseDept = $stmt->fetchColumn();
            if ($courseDept === false) {
                $errors['course'] = 'The selected course does not exist.';
            } elseif ($departmentId && (int) $courseDept !== $departmentId) {
                $errors['course'] = 'The selected course does not belong to this department.';
            }
        }

        if (empty($_FILES['file']['name'])) {
            $errors['file'] = 'Select a file to upload.';
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors['file'] = 'Upload error — please try again.';
        } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            $errors['file'] = 'File too large (max 10 MB).';
        } else {
            $allowedTypes = [
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg', 'image/png', 'text/plain'
            ];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes, true)) {
                $errors['file'] = 'File type not allowed. Accepted: PDF, Word, Excel, JPEG, PNG, TXT.';
            }
        }

        if (empty($errors)) {
            $originalName = $_FILES['file']['name'];
            $storedName = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
            $filePath = $uploadDir . $storedName;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents (user_id, title, description, filename, original_name, file_size, mime_type, category, course_id, department_id)
                     VALUES (:uid, :title, :desc, :fname, :oname, :fsize, :mtype, :cat, :cid, :did)'
                );
                $stmt->execute([
                    'uid' => $user['id'], 'title' => $title, 'desc' => $description ?: null,
                    'fname' => $storedName, 'oname' => $originalName,
                    'fsize' => $_FILES['file']['size'], 'mtype' => $mimeType,
                    'cat' => $category, 'cid' => $courseId, 'did' => $departmentId
                ]);
                AuditLog::recordEntity(
                    $user['id'], 'DOCUMENT_UPLOAD', 'document', (int) $pdo->lastInsertId(),
                    "Uploaded: $title ($originalName)",
                    null,
                    ['title' => $title, 'category' => $category, 'original_name' => $originalName, 'file_size' => $_FILES['file']['size']]
                );
                $_SESSION['flash_doc'] = 'Document uploaded successfully.';
                header('Location: /documents.php');
                exit;
            } else {
                $errors['file'] = 'Failed to save file. Check directory permissions.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            // Document doesn't exist — silently redirect (no information leak)
            header('Location: /documents.php');
            exit;
        }

        // ── Authorization via centralized policy ──
        $policy->canDeleteDocument($id);

        // Capture department_id BEFORE deletion — the row will be gone
        // when AuditLog auto-derivation tries to look it up.
        $deletedDeptId = (int) $doc['department_id'];

        // Delete from disk
        $filePath = $uploadDir . $doc['filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute(['id' => $id]);
        AuditLog::recordEntity(
            $user['id'], 'DOCUMENT_DELETE', 'document', $id,
            "Deleted: " . $doc['title'],
            ['title' => $doc['title'], 'original_name' => $doc['original_name'], 'category' => $doc['category']],
            null,
            $deletedDeptId
        );
        header('Location: /documents.php');
        exit;
    }
}

$flashDoc = $_SESSION['flash_doc'] ?? null;
unset($_SESSION['flash_doc']);

// ── Dropdown data via DepartmentScope ─────────────────────
// Every read goes through a scope. No manual if/else branches.
$scope = DepartmentScope::forUser($user, $pdo);
$courses = $scope->coursesDropdown();
$departments = $scope->departments();

// Filter
$filterCat = $_GET['category'] ?? '';
$filterCourse = isset($_GET['course_id']) ? (int) $_GET['course_id'] : null;

// ── Document listing via DepartmentScope ──────────────────
// Non-admin users can only see documents in their own department.
$documents = $scope->documents($filterCat, $filterCourse);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Documents — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="app-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="main-content">
    <h2 style="margin-bottom:16px;">Documents</h2>

    <?php if ($flashDoc): ?>
      <div class="flash success"><?= h($flashDoc) ?></div>
    <?php endif; ?>

    <!-- Upload form -->
    <div class="card" style="margin-bottom:20px;">
      <h3 style="margin-bottom:12px;">Upload Document</h3>
      <form method="post" action="/documents.php" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upload">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
          <div class="field">
            <label>Title</label>
            <input type="text" name="title" required>
            <?php if (!empty($errors['title'])): ?><div class="error"><?= h($errors['title']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label>File (max 10 MB)</label>
            <input type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.txt" required>
            <?php if (!empty($errors['file'])): ?><div class="error"><?= h($errors['file']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label>Category</label>
            <select name="category">
              <option value="syllabus">Syllabus</option>
              <option value="notes">Notes</option>
              <option value="assignment">Assignment</option>
              <option value="exam">Exam</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="field">
            <label>Course (optional)</label>
            <select name="course_id">
              <option value="">— none —</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['code'] . ' — ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Department (optional)</label>
            <select name="department_id">
              <option value="">— none —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="grid-column: span 2;">
            <label>Description</label>
            <input type="text" name="description" placeholder="Optional description">
          </div>
        </div>
        <div style="margin-top:14px;">
          <button class="btn-sm" type="submit">Upload</button>
        </div>
      </form>
    </div>

    <!-- Filter -->
    <form method="get" action="/documents.php" class="toolbar">
      <select name="category" onchange="this.form.submit()">
        <option value="">All categories</option>
        <?php foreach (['syllabus' => 'Syllabus', 'notes' => 'Notes', 'assignment' => 'Assignment', 'exam' => 'Exam', 'other' => 'Other'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= $filterCat === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <select name="course_id" onchange="this.form.submit()">
        <option value="">All courses</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterCourse === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- Documents table -->
    <table class="data-table searchable">
      <thead>
        <tr><th>Title</th><th>Category</th><th>File</th><th>Size</th><th>Course</th><th>Uploaded By</th><th>Date</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($documents)): ?>
          <tr><td colspan="8" style="text-align:center;color:#6b7280;">No documents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $doc): ?>
          <tr>
            <td><strong><?= h($doc['title']) ?></strong><?php if ($doc['description']): ?><br><small style="color:#6b7280;"><?= h($doc['description']) ?></small><?php endif; ?></td>
            <td><span class="badge"><?= h($doc['category']) ?></span></td>
            <td><a href="/download-file.php?id=<?= (int) $doc['id'] ?>" class="btn-sm" style="font-size:11px;"><?= h($doc['original_name']) ?></a></td>
            <td><?= number_format((int) $doc['file_size'] / 1024, 0) ?> KB</td>
            <td><?= h($doc['course_code'] ?? '—') ?></td>
            <td><?= h($doc['uploader_name']) ?></td>
            <td><?= h(substr($doc['created_at'], 0, 10)) ?></td>
            <td>
              <?php if ($policy->isAllowedToDeleteDocument((int) $doc['id'])): ?>
                <form method="post" action="/documents.php" style="display:inline;" onsubmit="return confirm('Delete this document?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                  <button class="btn-sm danger" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
