<?php
/**
 * Loads configuration from environment variables.
 * Defaults are set for local development (SQLite + built-in servers).
 */

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

// Project root — two levels up from src/ (src → php-app → FacultyIS)
$projectRoot = dirname(__DIR__, 2);

return [
    'db' => [
        'driver' => 'sqlite',
        // SQLite path — defaults to data/facultyis.sqlite3 in the project
        'sqlite_path' => env('SQLITE_PATH', $projectRoot . '/data/facultyis.sqlite3'),
    ],
    'jwt' => [
        // MUST match Flask's JWT_SECRET exactly.
        'secret' => env('JWT_SECRET', 'dev-insecure-please-change-me'),
        'ttl' => (int) env('JWT_TTL_SECONDS', '3600'),
    ],
    'flask_api' => [
        'base_url' => env('FLASK_API_URL', 'http://127.0.0.1:5000'),
    ],
];
