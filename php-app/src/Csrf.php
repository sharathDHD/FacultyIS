<?php

/**
 * Minimal per-session CSRF token — addresses a gap explicitly flagged in
 * the legacy project review (no CSRF protection existed on either form).
 */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function verify(?string $submitted): bool
    {
        return is_string($submitted)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submitted);
    }
}
