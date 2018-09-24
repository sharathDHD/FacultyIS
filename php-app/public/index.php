<?php
require __DIR__ . '/../src/bootstrap.php';

// index.php is just a router — no landing page needed.
// Logged in → dashboard. Not logged in → login form.
if (Auth::check()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
