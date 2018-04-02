<?php
/**
 * Secure file download handler — serves uploaded documents.
 * Only authenticated users can download; the stored filename is never
 * exposed to the browser (only the original name is sent as the
 * Content-Disposition filename).
 */
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid document ID.');
}

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute(['id' => $id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

// ── Department authorization ────────────────────────────
// Non-admin users can only download documents in their own department.
// This matches the Flask API /api/documents/list scoping.
if ($user['role'] !== 'admin') {
    if ((int) $doc['department_id'] !== (int) $user['department_id']) {
        http_response_code(403);
        die('403 Forbidden — you can only download documents from your own department.');
    }
}

$uploadDir = getenv('UPLOAD_DIR') ?: dirname(__DIR__, 2) . '/data/uploads/';
$uploadDir = rtrim($uploadDir, '/') . '/';
$filePath = $uploadDir . $doc['filename'];
if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on disk.');
}

AuditLog::record(Auth::user()['id'], 'DOCUMENT_DOWNLOAD', "Downloaded: " . $doc['title'], (int) $doc['department_id']);

// Sanitize filename for Content-Disposition header:
// - Remove any CR/LF characters (header injection)
// - Replace characters that are problematic in filenames
// - Emit both ASCII filename and RFC 5987 filename* for Unicode support
$safeName = str_replace(["\r", "\n", '"', '\\'], '', $doc['original_name']);
// Provide ASCII-safe fallback: replace non-ASCII with underscores
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeName);

header('Content-Type: ' . $doc['mime_type']);
header("Content-Disposition: attachment; filename=\"$asciiName\"; filename*=UTF-8''" . rawurlencode($safeName));
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
