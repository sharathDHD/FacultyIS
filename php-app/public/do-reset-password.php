<?php
/**
 * Password reset POST handler — validates the email, creates a reset token,
 * and stores a hash of the token in the database.
 *
 * SECURITY: The raw token is NEVER stored in the database. Only a SHA-256
 * hash is stored. The raw token is only included in the reset link that
 * would be sent via email in production. For local dev, the link is logged
 * to the PHP error log (not displayed to the user).
 *
 * The response is always identical regardless of whether the email exists,
 * to prevent account enumeration.
 */
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reset-password.php');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    die('Invalid or missing CSRF token.');
}

// Rate limit: 10 reset requests per hour per IP
require_once __DIR__ . '/../src/RateLimit.php';
RateLimit::check('password_reset', 10, 3600);

$email = trim($_POST['email'] ?? '');
$pdo = Database::connection();

// Always show the exact same message regardless of whether the email exists.
// This prevents account enumeration.
$genericMessage = 'If that email is registered, a password-reset link will be sent to it. The link expires in 1 hour.';

$stmt = $pdo->prepare('SELECT id, name, user_id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$userRow = $stmt->fetch();

if ($userRow) {
    // Generate a cryptographically secure token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour TTL

    // Invalidate any previous unused tokens for this user
    $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = :uid AND used_at IS NULL")
        ->execute(['uid' => $userRow['id']]);

    // Insert HASH of the token — the raw token is never stored
    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token_hash, :expires)'
    );
    $stmt->execute(['uid' => $userRow['id'], 'token_hash' => $tokenHash, 'expires' => $expiresAt]);

    AuditLog::record((int) $userRow['id'], 'AUTH_PASSWORD_RESET_REQUEST', "Reset requested for email=$email");

    // In production, send the reset link via email.
    // In DEV_MODE, log the link to the PHP error log for convenience.
    // NEVER log the raw token outside of explicit development mode —
    // it is a bearer credential.
    $isDevMode = in_array(getenv('DEV_MODE'), ['1', 'true', 'yes'], true);
    if ($isDevMode) {
        $resetLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . "/set-password.php?token=$token";
        error_log("FacultyIS [DEV] password reset link for {$userRow['user_id']}: $resetLink");
    }
    // TODO: In production, send the link via email:
    //   mail($email, 'Password Reset', $resetLink, ...);
}

// Always show the same generic message — never reveal whether the email exists
$_SESSION['flash_reset'] = [
    'type' => 'success',
    'message' => $genericMessage
];

header('Location: /reset-password.php');
exit;
