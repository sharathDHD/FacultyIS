<?php
// Version: v2.12


require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/AuditLog.php';

class Auth
{
    private static ?array $configCache = null;

    /**
     * Server-side validation — this is the authoritative check. Client-side
     * JS (ported from the legacy project) is UX sugar only; nothing here
     * trusts it. Every rule below is enforced again regardless of what the
     * browser already filtered.
     */
    public static function validateRegistration(array $input): array
    {
        $errors = [];

        $name = trim($input['name'] ?? '');
        Validate::name($name, $errors);

        $userId = trim($input['user_id'] ?? '');
        Validate::userId($userId, $errors);

        $email = trim($input['email'] ?? '');
        Validate::email($email, $errors);

        $phone = trim($input['phone'] ?? '');
        Validate::phone($phone, $errors);

        Validate::gender($input['gender'] ?? '', $errors);
        Validate::dob($input['dob'] ?? '', $errors);
        Validate::password($input['password'] ?? '', $errors);

        return $errors;
    }

    public static function register(array $input): array
    {
        $errors = self::validateRegistration($input);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE user_id = :uid OR email = :email');
        $stmt->execute(['uid' => $input['user_id'], 'email' => $input['email']]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'errors' => ['user_id' => 'User ID or email already registered.']];
        }

        $hash = password_hash($input['password'], PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (user_id, name, email, phone, gender, dob, role, password_hash)
             VALUES (:user_id, :name, :email, :phone, :gender, :dob, :role, :hash)'
        );
        $stmt->execute([
            'user_id' => $input['user_id'],
            'name' => trim($input['name']),
            'email' => trim($input['email']),
            'phone' => trim($input['phone']),
            'gender' => $input['gender'],
            'dob' => $input['dob'],
            'role' => 'faculty', // self-registration is always faculty; HOD/admin promoted separately
            'hash' => $hash,
        ]);

        AuditLog::record((int) $pdo->lastInsertId(), 'AUTH_REGISTER', 'Self-registered as faculty');

        return ['ok' => true];
    }

    /**
     * Verifies credentials and, on success, starts the PHP session AND
     * issues a JWT for calling the Flask API (architecture doc 5.1).
     */
    public static function login(string $userId, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Invalid user ID or password.'];
        }

        // --- Security: regenerate session ID after privilege change (OWASP) ---
        session_regenerate_id(true);
        // Clear CSRF token so a fresh one is generated on the next form.
        unset($_SESSION['csrf_token']);

        $config = self::loadConfig();

        $token = Jwt::encode(
            ['sub' => (int) $user['id'], 'role' => $user['role'], 'user_id' => $user['user_id'], 'department_id' => (int) $user['department_id'], 'auth_version' => (int) ($user['auth_version'] ?? 0)],
            $config['jwt']['secret'],
            $config['jwt']['ttl']
        );

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'user_id' => $user['user_id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'department_id' => $user['department_id'],
            'auth_version' => (int) ($user['auth_version'] ?? 0),
        ];
        $_SESSION['jwt'] = $token;

        AuditLog::record((int) $user['id'], 'AUTH_LOGIN', 'Successful login');

        return ['ok' => true];
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user']['id'])) {
            AuditLog::record((int) $_SESSION['user']['id'], 'AUTH_LOGOUT', '');
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Delete the session cookie from the browser.
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]);
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Redirects to login if not authenticated. Call at top of protected pages.
     *
     * SECURITY: Also validates the PHP session against the current database
     * state. If auth_version has changed (role change, password reset, etc.)
     * the session is invalidated and the user is forced to re-authenticate.
     * This ensures PHP sessions are revoked with the same consistency as
     * Flask JWTs — without this check, a stale PHP session could retain
     * old privileges after an admin changes a user's role.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }

        // ── Validate session against current DB state ────────────
        // If auth_version has changed in the DB (role change, password
        // reset, account deletion), the session is stale and must be
        // invalidated. This mirrors the Flask JWT auth_version check.
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT role, department_id, auth_version FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $_SESSION['user']['id']]);
        $current = $stmt->fetch();

        if (!$current || (int) $current['auth_version'] !== (int) $_SESSION['user']['auth_version']) {
            // Session is stale — force logout and re-authentication
            self::logout();
            header('Location: /login.php');
            exit;
        }

        // Refresh role and department_id from DB in case they changed
        // without incrementing auth_version (defensive — shouldn't happen
        // if all mutation paths increment auth_version, but protects
        // against direct DB edits). Also re-issue the JWT if the
        // session's role or department_id is stale, so that subsequent
        // Flask API calls use current privileges.
        $sessionStale = (
            $_SESSION['user']['role'] !== $current['role'] ||
            (int) $_SESSION['user']['department_id'] !== (int) $current['department_id']
        );

        $_SESSION['user']['role'] = $current['role'];
        $_SESSION['user']['department_id'] = $current['department_id'];

        if ($sessionStale) {
            $config = self::loadConfig();
            $_SESSION['jwt'] = Jwt::encode(
                [
                    'sub' => (int) $_SESSION['user']['id'],
                    'role' => $current['role'],
                    'user_id' => $_SESSION['user']['user_id'],
                    'department_id' => (int) $current['department_id'],
                    'auth_version' => (int) $current['auth_version'],
                ],
                $config['jwt']['secret'],
                $config['jwt']['ttl']
            );
        }
    }

    /** Redirects to dashboard with an error if the user's role isn't allowed. */
    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::user()['role'], $roles, true)) {
            http_response_code(403);
            echo "403 Forbidden — your role does not have access to this page.";
            exit;
        }
    }

    /** Generate a unique user_id for admin-created faculty (collision-safe). */
    public static function generateUniqueUserId(string $name, PDO $pdo): string
    {
        $base = 'fac_' . strtolower(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6));
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = bin2hex(random_bytes(4)); // 8 hex chars = 4 billion possibilities
            $candidate = $base . '_' . $suffix;
            $stmt = $pdo->prepare('SELECT id FROM users WHERE user_id = :uid');
            $stmt->execute(['uid' => $candidate]);
            if (!$stmt->fetch()) {
                return $candidate;
            }
        }
        // Extremely unlikely fallback — append timestamp.
        return $base . '_' . time();
    }

    /** Cached config loader — avoids re-parsing config.php on every call. */
    private static function loadConfig(): array
    {
        if (self::$configCache === null) {
            self::$configCache = require __DIR__ . '/config.php';
        }
        return self::$configCache;
    }
}
