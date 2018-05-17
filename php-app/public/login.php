<?php
require __DIR__ . '/../src/bootstrap.php';

// If already logged in, go straight to dashboard
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle POST (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 5 failed login attempts per 15 minutes per IP
    require_once __DIR__ . '/../src/RateLimit.php';
    RateLimit::check('login', 5, 900);

    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Invalid or missing CSRF token.';
    } else {
        $userId = trim($_POST['user_id'] ?? '');
        $password = $_POST['password'] ?? '';

        $result = Auth::login($userId, $password);

        if (!$result['ok']) {
            AuditLog::record(null, 'AUTH_LOGIN_FAILED', "Failed login attempt for user_id=$userId");
            $errors[] = $result['error'];
        } else {
            // Clear rate limit on successful login
            RateLimit::clear('login');
            header('Location: /dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="login-page">
  <div class="login-card">
    <div class="login-header">
      <h1>Faculty Information System</h1>
      <p>Sign in to continue</p>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/login.php">
      <?= Csrf::field() ?>
      <div class="field">
        <label>User ID</label>
        <input type="text" name="user_id" required autofocus placeholder="Enter your user ID">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required placeholder="Enter your password">
      </div>
      <button class="btn-primary" type="submit">Login</button>
    </form>

    <div class="login-links">
      <a href="/register.php">Create an account</a>
      <a href="/reset-password.php">Forgot password?</a>
    </div>
  </div>
</div>

</body>
</html>
