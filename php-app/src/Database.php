<?php

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require __DIR__ . '/config.php';
        $db = $config['db'];

        $dsn = 'sqlite:' . $db['sqlite_path'];
        self::$instance = new PDO($dsn);

        // SQLite: enforce foreign key constraints (off by default)
        self::$instance->exec('PRAGMA foreign_keys = ON');
        // SQLite: WAL mode for better concurrent read performance
        self::$instance->exec('PRAGMA journal_mode = WAL');
        // SQLite: wait up to 5s on lock contention (matches Flask)
        self::$instance->exec('PRAGMA busy_timeout = 5000');

        self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$instance;
    }
}
