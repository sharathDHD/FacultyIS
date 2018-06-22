<?php
/**
 * Password reset request page — user enters their email, and if it matches
 * a registered account, a reset token is generated and sent via email
 * (in DEV_MODE, the link is logged to the PHP error log instead).
 * The user then visits the reset link with the token to set a new password.
 */
require __DIR__ . '/../src/bootstrap.php';

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$flash = $_SESSION['flash_reset'] ?? null;
unset($_SESSION['flash_reset']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password — Faculty Information System</title>
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
  <h2>Reset Your Password</h2>
  <p style="color:#6b7280;margin-bottom:20px;">Enter your email address and we'll send a password-reset link if the account exists.</p>

  <?php if ($flash): ?>
    <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:16px;">
      <?= h($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:420px;margin:0 auto;padding:28px;">
    <form method="post" action="/do-reset-password.php">
      <?= Csrf::field() ?>
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" required autofocus placeholder="your.email@example.edu">
      </div>
      <button class="btn-primary" type="submit">Send Reset Link</button>
    </form>
    <div class="switch-link" style="margin-top:14px;">
      <a href="/login.php">Back to Login</a>
    </div>
  </div>
</section>
</body>
</html>
