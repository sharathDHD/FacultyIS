<?php

declare(strict_types=1);

// Secure session cookie settings (legacy project had none of this).
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (($_SERVER['HTTPS'] ?? '') !== '') {
    ini_set('session.cookie_secure', '1');
}
session_set_cookie_params(['samesite' => 'Lax']);

session_start();

require __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/DepartmentScope.php';
require_once __DIR__ . '/AuthorizationPolicy.php';
require_once __DIR__ . '/Validate.php';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ── Session staleness check ────────────────────────────────
// If a user's role or password was changed by an admin, their session
// may still contain the old role. We store auth_version in the session
// and compare it against the database on each request. If they differ,
// the session is stale and we force re-authentication.
if (isset($_SESSION['user']['id'])) {
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT auth_version, role, department_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user']['id']]);
        $dbUser = $stmt->fetch();
        if ($dbUser) {
            $sessionVersion = $_SESSION['user']['auth_version'] ?? 0;
            $dbVersion = (int) $dbUser['auth_version'];
            if ($sessionVersion !== $dbVersion) {
                // Session is stale — role or password was changed.
                // Update the session with the current DB values.
                $_SESSION['user']['role'] = $dbUser['role'];
                $_SESSION['user']['department_id'] = $dbUser['department_id'];
                $_SESSION['user']['auth_version'] = $dbVersion;
                // Regenerate session ID for security
                session_regenerate_id(true);

                // Re-issue JWT with updated auth_version so Flask API
                // calls also reflect the change (architecture doc 5.1).
                $config = require __DIR__ . '/config.php';
                $_SESSION['jwt'] = Jwt::encode(
                    [
                        'sub' => (int) $_SESSION['user']['id'],
                        'role' => $dbUser['role'],
                        'user_id' => $_SESSION['user']['user_id'],
                        'department_id' => (int) $dbUser['department_id'],
                        'auth_version' => $dbVersion,
                    ],
                    $config['jwt']['secret'],
                    $config['jwt']['ttl']
                );
            }
        } else {
            // User no longer exists — destroy session
            $_SESSION = [];
            session_destroy();
        }
    } catch (Throwable $e) {
        // Don't break the request if the check fails
        error_log('Session staleness check failed: ' . $e->getMessage());
    }
}
