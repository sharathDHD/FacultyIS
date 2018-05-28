<?php
require __DIR__ . '/../src/bootstrap.php';

// If already logged in, go to dashboard
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$regErrors = [];
$regOld = [];

// Handle POST (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $regErrors['csrf'] = 'Invalid or missing CSRF token.';
    } else {
        $input = [
            'name' => $_POST['name'] ?? '',
            'user_id' => $_POST['user_id'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'dob' => $_POST['dob'] ?? '',
            'password' => $_POST['password'] ?? '',
        ];

        $result = Auth::register($input);

        if (!$result['ok']) {
            $regErrors = $result['errors'];
            $regOld = $input;
        } else {
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Account created — please log in.'];
            header('Location: /login.php');
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
<title>Register — Faculty Information System</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="login-page">
  <div class="login-card" style="max-width:480px;">
    <div class="login-header">
      <h1>Create Account</h1>
      <p>Register as a faculty member</p>
    </div>

    <?php if (!empty($regErrors)): ?>
      <div class="flash error">Please fix the errors below.</div>
    <?php endif; ?>

    <form method="post" action="/register.php">
      <?= Csrf::field() ?>
      <div class="field">
        <label>Full Name</label>
        <input type="text" name="name" value="<?= h($regOld['name'] ?? '') ?>" onkeypress="return Ischar(event)" required placeholder="Your full name">
        <?php if (!empty($regErrors['name'])): ?><div class="error"><?= h($regErrors['name']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>User ID</label>
        <input type="text" name="user_id" value="<?= h($regOld['user_id'] ?? '') ?>" required placeholder="Choose a unique user ID">
        <?php if (!empty($regErrors['user_id'])): ?><div class="error"><?= h($regErrors['user_id']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" value="<?= h($regOld['email'] ?? '') ?>" required placeholder="your.email@example.edu">
        <?php if (!empty($regErrors['email'])): ?><div class="error"><?= h($regErrors['email']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Phone</label>
        <input type="text" name="phone" maxlength="10" value="<?= h($regOld['phone'] ?? '') ?>" onkeypress="return IsNum(event)" required placeholder="10-digit number">
        <?php if (!empty($regErrors['phone'])): ?><div class="error"><?= h($regErrors['phone']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Gender</label>
        <select name="gender" required>
          <option value="">Select</option>
          <option value="male" <?= ($regOld['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
          <option value="female" <?= ($regOld['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
          <option value="other" <?= ($regOld['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
        </select>
        <?php if (!empty($regErrors['gender'])): ?><div class="error"><?= h($regErrors['gender']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Date of Birth</label>
        <input type="date" name="dob" min="1950-01-01" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" value="<?= h($regOld['dob'] ?? '') ?>" required>
        <?php if (!empty($regErrors['dob'])): ?><div class="error"><?= h($regErrors['dob']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" minlength="8" required placeholder="Minimum 8 characters">
        <?php if (!empty($regErrors['password'])): ?><div class="error"><?= h($regErrors['password']) ?></div><?php endif; ?>
      </div>
      <button class="btn-primary" type="submit">Create Account</button>
    </form>

    <div class="login-links">
      <a href="/login.php">Already have an account? Login</a>
    </div>
  </div>
</div>

<script src="/assets/js/validate.js"></script>
</body>
</html>
