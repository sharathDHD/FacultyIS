#!/usr/bin/env python3
"""
FacultyIS — Initialize SQLite database.

Creates the database file, applies schema + seed data, then runs
migrations to add any columns missing from older schema versions.

Safe to re-run: schema uses IF NOT EXISTS, seed uses INSERT OR IGNORE,
migrations check for column existence before altering.

Usage:
    python init_db.py                # create + seed + migrate
    python init_db.py --schema-only  # create schema only (no seed data)
"""

import os
import sqlite3
import sys

PROJECT_ROOT = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(PROJECT_ROOT, "data", "facultyis.sqlite3")
SCHEMA_PATH = os.path.join(PROJECT_ROOT, "db", "schema.sqlite.sql")
SEED_PATH = os.path.join(PROJECT_ROOT, "db", "seed.sqlite.sql")


def _has_column(cursor: sqlite3.Cursor, table: str, column: str) -> bool:
    """Check if a column exists in a SQLite table."""
    cursor.execute(f"PRAGMA table_info({table})")
    return any(row[1] == column for row in cursor.fetchall())


def _has_table(cursor: sqlite3.Cursor, table: str) -> bool:
    """Check if a table exists in the database."""
    cursor.execute(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", (table,)
    )
    return cursor.fetchone()[0] > 0


def run_migrations(conn: sqlite3.Connection) -> list[str]:
    """
    Run schema migrations — add columns that may be missing from
    older schema versions. Returns list of applied migrations.
    """
    cursor = conn.cursor()
    applied = []

    # ── Migration 001: departments.is_active ───────────────────
    # Added for soft-delete support — older databases only had (id, name, created_at)
    if _has_table(cursor, "departments") and not _has_column(cursor, "departments", "is_active"):
        cursor.execute("ALTER TABLE departments ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1")
        # Set all existing departments to active
        cursor.execute("UPDATE departments SET is_active = 1 WHERE is_active IS NULL")
        applied.append("departments.is_active")

    # ── Migration 002: courses.is_active ───────────────────────
    if _has_table(cursor, "courses") and not _has_column(cursor, "courses", "is_active"):
        cursor.execute("ALTER TABLE courses ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1")
        cursor.execute("UPDATE courses SET is_active = 1 WHERE is_active IS NULL")
        applied.append("courses.is_active")

    # ── Migration 003: audit_log context columns ───────────────
    # These were added later for structured audit logging
    if _has_table(cursor, "audit_log"):
        for col, definition in [
            ("ip_address", "TEXT DEFAULT NULL"),
            ("user_agent", "TEXT DEFAULT NULL"),
            ("request_id", "TEXT DEFAULT NULL"),
            ("session_id_hash", "TEXT DEFAULT NULL"),
            ("source", "TEXT DEFAULT 'php'"),
        ]:
            if not _has_column(cursor, "audit_log", col):
                cursor.execute(f"ALTER TABLE audit_log ADD COLUMN {col} {definition}")
                applied.append(f"audit_log.{col}")

    # ── Migration 004: users.auth_version ────────────────────
    # Used to detect stale sessions after role/password changes.
    # When auth_version in the session doesn't match the DB, the
    # session is refreshed with current DB values.
    if _has_table(cursor, "users") and not _has_column(cursor, "users", "auth_version"):
        cursor.execute("ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0")
        applied.append("users.auth_version")

    # ── Migration 005: audit_log.department_id ───────────────
    # Records the department context of every audit event so HOD
    # can be scoped to their own department's audit records.
    if _has_table(cursor, "audit_log") and not _has_column(cursor, "audit_log", "department_id"):
        cursor.execute("ALTER TABLE audit_log ADD COLUMN department_id INTEGER DEFAULT NULL")
        # Backfill: infer department from the actor's department for existing records
        cursor.execute(
            "UPDATE audit_log SET department_id = "
            "(SELECT u.department_id FROM users u WHERE u.id = audit_log.user_id) "
            "WHERE user_id IS NOT NULL AND department_id IS NULL"
        )
        applied.append("audit_log.department_id")

    conn.commit()
    cursor.close()
    return applied


def init_db(seed: bool = True) -> list[str]:
    # Ensure data/ directory exists
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)

    # Connect (creates file if it doesn't exist)
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA foreign_keys = ON")

    # Apply schema
    with open(SCHEMA_PATH) as f:
        conn.executescript(f.read())

    # Apply seed data
    if seed and os.path.exists(SEED_PATH):
        with open(SEED_PATH) as f:
            conn.executescript(f.read())

    # Run migrations (for databases created from older schemas)
    migrations = run_migrations(conn)

    conn.close()
    return migrations


if __name__ == "__main__":
    seed = "--schema-only" not in sys.argv
    migrations = init_db(seed=seed)

    # Verify
    conn = sqlite3.connect(DB_PATH)
    tables = conn.execute(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    ).fetchall()
    conn.close()

    print(f"✓ Database: {DB_PATH}")
    print(f"✓ Tables:   {len(tables)}")
    if seed:
        print("✓ Seed data applied")
    if migrations:
        print(f"✓ Migrations: {', '.join(migrations)}")
    print("✓ Ready")
