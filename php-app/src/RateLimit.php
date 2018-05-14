<?php

/**
 * Simple SQLite-based rate limiter for PHP endpoints.
 *
 * Uses the audit_log database to track attempts — no Redis needed.
 * Tracks by IP address + optional key (e.g. user_id).
 *
 * Usage:
 *   RateLimit::check('login', 5, 900);   // 5 attempts per 15 min
 *   RateLimit::check('reset', 10, 3600); // 10 attempts per hour
 */
class RateLimit
{
    /**
     * Check rate limit; abort with 429 if exceeded.
     *
     * @param string $action  Action identifier (e.g. 'login', 'password_reset')
     * @param int    $max     Maximum attempts allowed in the window
     * @param int    $window  Time window in seconds (e.g. 900 = 15 min)
     * @param string|null $key  Optional additional key (e.g. user_id)
     */
    public static function check(string $action, int $max, int $window, ?string $key = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = $key ? "$action:$ip:$key" : "$action:$ip";

        $pdo = Database::connection();

        // Ensure rate_limit_attempts table exists
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rate_limit_attempts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_id_time ON rate_limit_attempts(identifier, attempted_at)");

        // Clean up old entries (older than 2x the window, to prevent unbounded growth)
        $pdo->prepare("DELETE FROM rate_limit_attempts WHERE identifier = :id AND attempted_at < datetime('now', :interval)")
            ->execute(['id' => $identifier, 'interval' => '-' . ($window * 2) . ' seconds']);

        // Count attempts in the window
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_limit_attempts
             WHERE identifier = :id AND attempted_at >= datetime('now', :interval)"
        );
        $stmt->execute(['id' => $identifier, 'interval' => "-$window seconds"]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $max) {
            http_response_code(429);
            die("429 Too Many Requests — please wait before trying again.");
        }

        // Record this attempt
        $pdo->prepare("INSERT INTO rate_limit_attempts (identifier) VALUES (:id)")
            ->execute(['id' => $identifier]);
    }

    /**
     * Clear rate limit entries for a given key (e.g. after successful login).
     *
     * @param string $action
     * @param string|null $key
     */
    public static function clear(string $action, ?string $key = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = $key ? "$action:$ip:$key" : "$action:$ip";

        $pdo = Database::connection();
        $pdo->prepare("DELETE FROM rate_limit_attempts WHERE identifier = :id")
            ->execute(['id' => $identifier]);
    }
}
