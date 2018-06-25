<?php
// Version: v2.12


/**
 * Validate — Centralized input validation helpers.
 *
 * Every PHP page and Auth class should use these instead of inline
 * regex/filter_var calls. This eliminates duplicated validation patterns
 * and provides a single point of change if rules evolve.
 *
 * v2.15: Extracted from Auth::validateRegistration() and faculty.php.
 *
 * Usage:
 *   $errors = [];
 *   Validate::name($name, $errors);
 *   Validate::email($email, $errors);
 *   Validate::phone($phone, $errors);
 */
class Validate
{
    /**
     * Validate a person/department name.
     * Allows Unicode letters and spaces, 2-120 chars.
     * For department names, use nameLoose() which also allows digits, &, -.
     *
     * @param string $value
     * @param array  $errors  Errors array to append to (key = 'name')
     * @param string $field   Field name for the errors key
     */
    public static function name(string $value, array &$errors, string $field = 'name'): void
    {
        if ($value === '' || !preg_match('/^[\p{L}\s]{2,120}$/u', $value)) {
            $errors[$field] = 'Name must contain only letters and spaces (2-120 chars).';
        }
    }

    /**
     * Validate a name that may contain digits, ampersands, hyphens.
     * Used for department names: "Dept of CSE & IT".
     */
    public static function nameLoose(string $value, array &$errors, string $field = 'name'): void
    {
        if ($value === '' || !preg_match('/^[\p{L}\s0-9&\-]{2,120}$/u', $value)) {
            $errors[$field] = 'Name must be 2-120 chars (letters, spaces, numbers, & or -).';
        }
    }

    /**
     * Validate an email address.
     */
    public static function email(string $value, array &$errors, string $field = 'email'): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[$field] = 'Enter a valid email address.';
        }
    }

    /**
     * Validate a 10-digit phone number.
     */
    public static function phone(string $value, array &$errors, string $field = 'phone'): void
    {
        if (!preg_match('/^[0-9]{10}$/', $value)) {
            $errors[$field] = 'Phone must be exactly 10 digits.';
        }
    }

    /**
     * Validate a gender value.
     */
    public static function gender(string $value, array &$errors, string $field = 'gender'): void
    {
        if (!in_array($value, ['male', 'female', 'other'], true)) {
            $errors[$field] = 'Select a gender.';
        }
    }

    /**
     * Validate a date of birth — must be 18+ and not before 1950.
     */
    public static function dob(string $value, array &$errors, string $field = 'dob'): void
    {
        $time = strtotime($value);
        if (!$time || $time < strtotime('1950-01-01') || $time > strtotime('-18 years')) {
            $errors[$field] = 'Enter a valid date of birth (must be 18+).';
        }
    }

    /**
     * Validate a password — minimum 8 characters.
     */
    public static function password(string $value, array &$errors, string $field = 'password'): void
    {
        if (strlen($value) < 8) {
            $errors[$field] = 'Password must be at least 8 characters.';
        }
    }

    /**
     * Validate a user_id — 3-50 chars, alphanumeric + underscore.
     */
    public static function userId(string $value, array &$errors, string $field = 'user_id'): void
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_]{3,50}$/', $value)) {
            $errors[$field] = 'User ID must be 3-50 chars, letters/numbers/underscore only.';
        }
    }
}
