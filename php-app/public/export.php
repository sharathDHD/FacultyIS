<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireRole(['hod', 'admin']);
$user = Auth::user();
$config = require __DIR__ . '/../src/config.php';
$pdo = Database::connection();

// ── Centralized write-side authorization ──────────────────
$scope = DepartmentScope::forUser($user, $pdo);

$format = $_GET['format'] ?? 'csv';
$requestedDeptId = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int) $_GET['department_id'] : null;

// ── Centralized effective department selection ──
$departmentId = $scope->effectiveDepartmentId($requestedDeptId);

if (!in_array($format, ['csv', 'xlsx'], true)) {
    http_response_code(400);
    die('Invalid export format.');
}

$client = new ApiClient($config['flask_api']['base_url'], $_SESSION['jwt']);

if ($format === 'csv') {
    // CSV: server-generated, streamed directly to browser
    $path = '/api/exports/faculty.csv';
    $query = $departmentId ? ['department_id' => $departmentId] : [];
    $result = $client->download($path, $query);

    if (!$result['ok']) {
        // Distinguish authorization failures (401/403) from service failures.
        // Authorization failures must never be silently swallowed.
        if (in_array($result['status'], [401, 403], true)) {
            http_response_code($result['status']);
            die($result['status'] === 403 ? '403 Forbidden' : '401 Unauthorized');
        }
        http_response_code(502);
        die('Export service is currently unavailable. Please try again shortly.');
    }

    AuditLog::record(Auth::user()['id'], 'EXPORT_FACULTY', "format=csv department_id=" . ($departmentId ?? 'all'), $departmentId);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="faculty_export.csv"');
    echo $result['body'];
    exit;
}

// XLSX: fetch JSON from Flask, render a page that generates XLSX client-side
$path = '/api/exports/faculty.json';
$query = $departmentId ? ['department_id' => $departmentId] : [];
$result = $client->get($path, $query);

if (!$result['ok']) {
    // Distinguish authorization failures (401/403) from service failures.
    if (in_array($result['status'], [401, 403], true)) {
        http_response_code($result['status']);
        die($result['status'] === 403 ? '403 Forbidden' : '401 Unauthorized');
    }
    http_response_code(502);
    die('Export service is currently unavailable. Please try again shortly.');
}

AuditLog::record(Auth::user()['id'], 'EXPORT_FACULTY', "format=xlsx department_id=" . ($departmentId ?? 'all'), $departmentId);

$exportData = $result['data'] ?? $result;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Export — FacultyIS</title>
    <style>
        body { font-family: system-ui, sans-serif; padding: 2rem; }
        .status { color: #666; }
        .error { color: #c00; }
    </style>
</head>
<body>
    <p class="status">Generating XLSX file...</p>
    <p class="error" id="err" style="display:none"></p>

    <!-- SheetJS (xlsx.js) — vendored locally for offline/portable use -->
    <script src="/assets/js/vendor/xlsx.full.min.js"></script>
    <script>
    (function() {
        try {
            var data = <?php echo json_encode($exportData, JSON_UNESCAPED_UNICODE); ?>;
            var ws = XLSX.utils.json_to_sheet(data.data, {header: data.columns});
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Faculty");
            XLSX.writeFile(wb, "faculty_export.xlsx");
        } catch (e) {
            document.querySelector('.status').style.display = 'none';
            var err = document.getElementById('err');
            err.textContent = 'XLSX generation failed: ' + e.message;
            err.style.display = 'block';
        }
    })();
    </script>
</body>
</html>
