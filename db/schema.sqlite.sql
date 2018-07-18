-- ==========================================================================
-- Faculty Information System — SQLite schema
-- ==========================================================================
-- Both PHP and Flask connect to this same database file.
-- PHP owns writes to core transactional tables (users, departments, courses,
-- course_sections, attendance, documents, audit_log). Flask reads for
-- analytics/reporting and only writes to report_cache.
--
-- SQLite compatibility notes:
--   • ENUM → VARCHAR + CHECK (SQLite has no native ENUM type)
--   • JSON → TEXT (application handles serialization)
--   • TINYINT → INTEGER (SQLite has only INTEGER/REAL/TEXT/BLOB)
--   • AUTO_INCREMENT → INTEGER PRIMARY KEY (auto ROWID alias)
--   • TIMESTAMP DEFAULT CURRENT_TIMESTAMP → supported by SQLite
--   • Foreign keys require PRAGMA foreign_keys=ON at connection time
-- ==========================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;          -- better concurrent read performance
PRAGMA busy_timeout = 5000;         -- wait up to 5s on lock contention

-- ─── 1. departments ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS departments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── 2. users ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL UNIQUE,
    phone         TEXT DEFAULT NULL,
    gender        TEXT DEFAULT NULL CHECK (gender IN ('male', 'female', 'other')),
    dob           TEXT DEFAULT NULL,               -- ISO 8601 date string
    role          TEXT NOT NULL DEFAULT 'faculty' CHECK (role IN ('faculty', 'hod', 'admin')),
    department_id INTEGER DEFAULT NULL,
    password_hash TEXT NOT NULL,
    auth_version  INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ─── 3. courses ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    code          TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL,
    department_id INTEGER NOT NULL,
    credits       INTEGER DEFAULT 3,
    is_active     INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

-- ─── 4. course_sections ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS course_sections (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id      INTEGER NOT NULL,
    faculty_id     INTEGER NOT NULL,
    semester       TEXT NOT NULL,
    year           INTEGER NOT NULL,
    section_number TEXT DEFAULT 'A',
    room           TEXT DEFAULT NULL,
    schedule_day   TEXT DEFAULT NULL,
    schedule_time  TEXT DEFAULT NULL,
    FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES users(id)   ON DELETE CASCADE
);

-- ─── 5. audit_log ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER DEFAULT NULL,
    action          TEXT NOT NULL,
    entity_type     TEXT DEFAULT NULL,
    entity_id       INTEGER DEFAULT NULL,
    department_id   INTEGER DEFAULT NULL,
    detail          TEXT DEFAULT NULL,
    old_values      TEXT DEFAULT NULL,            -- JSON stored as TEXT
    new_values      TEXT DEFAULT NULL,            -- JSON stored as TEXT
    ip_address      TEXT DEFAULT NULL,
    user_agent      TEXT DEFAULT NULL,
    request_id      TEXT DEFAULT NULL,
    session_id_hash TEXT DEFAULT NULL,
    source          TEXT DEFAULT 'php',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ─── 6. report_cache ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS report_cache (
    cache_key    TEXT PRIMARY KEY,
    payload      TEXT NOT NULL,                   -- JSON stored as TEXT
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── 7. password_resets ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── 8. attendance ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    course_section_id INTEGER NOT NULL,
    faculty_id        INTEGER DEFAULT NULL,
    date              TEXT NOT NULL,               -- ISO 8601 date string
    total_students    INTEGER DEFAULT 0,
    present_count     INTEGER DEFAULT 0,
    notes             TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id)        REFERENCES users(id)            ON DELETE SET NULL,
    UNIQUE (course_section_id, date)
);

-- ─── 9. attendance_records ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance_records (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    attendance_id INTEGER NOT NULL,
    student_roll  TEXT NOT NULL,
    student_name  TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'present' CHECK (status IN ('present', 'absent', 'late', 'excused')),
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
);

-- ─── 10. timetable_entries ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS timetable_entries (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id    INTEGER NOT NULL,
    course_section_id INTEGER DEFAULT NULL,
    day_of_week      INTEGER NOT NULL,             -- 1=Monday … 5=Friday
    start_time       TEXT NOT NULL,                -- HH:MM:SS
    end_time         TEXT NOT NULL,                -- HH:MM:SS
    room             TEXT DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id)    REFERENCES departments(id)     ON DELETE CASCADE,
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id) ON DELETE SET NULL
);

-- ─── 11. documents ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    title         TEXT NOT NULL,
    description   TEXT DEFAULT NULL,
    filename      TEXT NOT NULL,
    original_name TEXT NOT NULL,
    file_size     INTEGER NOT NULL DEFAULT 0,
    mime_type     TEXT NOT NULL,
    category      TEXT NOT NULL DEFAULT 'other' CHECK (category IN ('syllabus', 'notes', 'assignment', 'exam', 'other')),
    course_id     INTEGER DEFAULT NULL,
    department_id INTEGER DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (course_id)     REFERENCES courses(id)     ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ─── Indexes (based on query patterns from Flask routes & PHP pages) ───────

-- Single-column indexes
CREATE INDEX IF NOT EXISTS idx_audit_user_time     ON audit_log(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity        ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_action_time   ON audit_log(action, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_request_id    ON audit_log(request_id);
CREATE INDEX IF NOT EXISTS idx_attendance_section   ON attendance(course_section_id, date);
CREATE INDEX IF NOT EXISTS idx_timetable_dept_day   ON timetable_entries(department_id, day_of_week, start_time);
CREATE INDEX IF NOT EXISTS idx_documents_user_time  ON documents(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_documents_dept_time  ON documents(department_id, created_at);

-- Compound indexes (based on actual Flask route query patterns)
CREATE INDEX IF NOT EXISTS idx_sections_faculty_sem  ON course_sections(faculty_id, semester, year);
CREATE INDEX IF NOT EXISTS idx_courses_dept_active   ON courses(department_id, is_active);
CREATE INDEX IF NOT EXISTS idx_users_dept_role       ON users(department_id, role);
CREATE INDEX IF NOT EXISTS idx_documents_dept_cat    ON documents(department_id, category);
CREATE INDEX IF NOT EXISTS idx_audit_entity_time     ON audit_log(entity_type, entity_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_dept_time       ON audit_log(department_id, created_at);
