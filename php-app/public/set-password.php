<?php
/**
 * Set new password page — accessed via the reset token link.
 * Validates the token (by comparing SHA-256 hash), and if valid,
 * allows the user to set a new password.
 *
 * SECURITY: The token is re-validated on POST via a hidden form field
 * to prevent token-less POST attacks. The password reset is performed
 * in a database transaction to prevent partial state if any operation
 * fails.
 */
require __DIR__ . '/../src/bootstrap.php';

$token = $_GET['token'] ?? '';
$validToken = false;
$tokenError = '';
$resetRow = null;

if ($token) {
    $tokenHash = hash('sha256', $token);
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT pr.*, u.user_id, u.name FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = :token_hash AND pr.used_at IS NULL AND pr.expires_at > datetime('now')"
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $resetRow = $stmt->fetch();

    if ($resetRow) {
        $validToken = true;
    } else {
        $tokenError = 'This reset link is invalid or has expired. Please request a new one.';
    }
} else {
    $tokenError = 'No reset token provided.';
}

// Handle POST — set the new password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-validate the token from the hidden form field on POST
    $postToken = $_POST['reset_token'] ?? '';
    if (!$postToken) {
        http_response_code(400);
        die('Missing reset token.');
    }

    $postTokenHash = hash('sha256', $postToken);
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT pr.*, u.user_id, u.name FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = :token_hash AND pr.used_at IS NULL AND pr.expires_at > datetime('now')"
    );
    $stmt->execute(['token_hash' => $postTokenHash]);
    $resetRow = $stmt->fetch();

    if (!$resetRow) {
        $tokenError = 'This reset link is invalid or has expired. Please request a new one.';
        $validToken = false;
    } else {
        $validToken = true;
        $token = $postToken; // Keep token in scope for form re-render on validation error

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            die('Invalid CSRF token.');
        }

        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $tokenError = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $tokenError = 'Passwords do not match.';
        } else {
            // Perform password reset in a transaction to prevent partial state
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            try {
                $pdo->beginTransaction();

                // Update the password and increment auth_version (invalidates stale sessions + JWTs)
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, auth_version = auth_version + 1 WHERE id = :id');
                $stmt->execute(['hash' => $hash, 'id' => $resetRow['user_id']]);

                // Mark this token as used
                $stmt = $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = :id");
                $stmt->execute(['id' => $resetRow['id']]);

                // Invalidate all other outstanding reset tokens for this user
                $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = :uid AND used_at IS NULL")
                    ->execute(['uid' => $resetRow['user_id']]);

                $pdo->commit();

                AuditLog::record((int) $resetRow['user_id'], 'AUTH_PASSWORD_RESET_COMPLETE', 'Password reset via token');

                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password updated successfully. Please log in.'];
                header('Location: /login.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $tokenError = 'Password reset failed. Please try again.';
                error_log('Password reset transaction failed: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set New Password — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<nav class="navbar">
  <div class="brand">Faculty Information System</div>
  <div class="nav-actions">
    <a href="/login.php" class="btn-ghost" style="color:#fff;text-decoration:none;">Back to Login</a>
  </div>
</nav>

<section class="hero" style="margin-top:40px;">
  <h2>Set New Password</h2>

  <?php if ($tokenError): ?>
    <div class="flash error" style="margin-bottom:16px;"><?= h($tokenError) ?></div>
    <p><a href="/reset-password.php" class="btn-sm">Request a new reset link</a></p>
  <?php elseif ($validToken): ?>
    <p style="color:#6b7280;margin-bottom:20px;">for user <strong><?= h($resetRow['user_id']) ?></strong></p>
    <div class="card" style="max-width:420px;margin:0 auto;padding:28px;">
      <form method="post" action="/set-password.php">
        <?= Csrf::field() ?>
        <input type="hidden" name="reset_token" value="<?= h($token) ?>">
        <div class="field">
          <label>New Password</label>
          <input type="password" name="password" minlength="8" required autofocus>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" minlength="8" required>
        </div>
        <button class="btn-primary" type="submit">Set New Password</button>
      </form>
    </div>
  <?php endif; ?>
</section>
</body>
</html>
