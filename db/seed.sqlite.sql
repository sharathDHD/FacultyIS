-- ==========================================================================
-- Faculty Information System — seed data (idempotent, SQLite-compatible)
-- ==========================================================================
-- Sample data for local development/testing only.
-- All passwords are 'password123' hashed with PHP's password_hash() (bcrypt).
--
-- Uses INSERT OR IGNORE so the script is safe to re-run.
-- IGNORE silently skips rows that would violate UNIQUE / PRIMARY keys.
-- ==========================================================================

-- ─── departments ───────────────────────────────────────────────────────────
INSERT OR IGNORE INTO departments (id, name, is_active) VALUES
    (1, 'Computer Science', 1),
    (2, 'Electronics', 1),
    (3, 'Mechanical', 1);

-- ─── users ─────────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO users (id, user_id, name, email, phone, gender, dob, role, department_id, password_hash) VALUES
    (1, 'admin1',  'System Admin',   'admin@example.edu',       '9000000000', 'other',  '1985-01-01', 'admin',   NULL, '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (2, 'hod_cs',  'Dr. Ramesh Rao', 'ramesh.rao@example.edu',  '9000000001', 'male',   '1975-06-15', 'hod',     1,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (3, 'fac_cs1', 'Priya Sharma',   'priya.sharma@example.edu','9000000002', 'female', '1988-03-22', 'faculty', 1,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (4, 'fac_cs2', 'Arjun Mehta',    'arjun.mehta@example.edu', '9000000003', 'male',   '1990-11-05', 'faculty', 1,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (5, 'fac_ec1', 'Sunitha Rao',    'sunitha.rao@example.edu', '9000000004', 'female', '1987-09-10', 'faculty', 2,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (6, 'hod_ec',  'Dr. Vikram Nair', 'vikram.nair@example.edu', '9000000005', 'male',   '1970-04-20', 'hod',     2,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG'),
    (7, 'fac_me1', 'Rajesh Kumar',   'rajesh.kumar@example.edu','9000000006', 'male',   '1982-07-08', 'faculty', 3,    '$2y$10$icxQeb3vcoUkOJkhQd3KEu4cj.xiGfCE8SYnryb.aa9fD5zIdA7HG');

-- ─── courses ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO courses (id, code, name, department_id, credits, is_active) VALUES
    (1, 'CS101', 'Data Structures',        1, 4, 1),
    (2, 'CS204', 'Database Systems',       1, 3, 1),
    (3, 'CS301', 'Operating Systems',      1, 4, 1),
    (4, 'CS402', 'Machine Learning',       1, 3, 1),
    (5, 'EC150', 'Digital Circuits',       2, 4, 1),
    (6, 'EC201', 'Signals and Systems',    2, 3, 1),
    (7, 'ME101', 'Engineering Mechanics',  3, 4, 1),
    (8, 'ME202', 'Thermodynamics',         3, 3, 1);

-- ─── course_sections ──────────────────────────────────────────────────────
INSERT OR IGNORE INTO course_sections (id, course_id, faculty_id, semester, year, section_number, room, schedule_day, schedule_time) VALUES
    (1, 1, 3, 'Odd',  2026, 'A', 'Room 101', 'Mon-Wed', '10:00-11:30'),
    (2, 2, 3, 'Odd',  2026, 'A', 'Room 102', 'Tue-Thu', '14:00-15:30'),
    (3, 3, 4, 'Odd',  2026, 'A', 'Room 201', 'Mon-Wed', '10:00-11:30'),
    (4, 4, 4, 'Odd',  2026, 'A', 'Lab 1',    'Fri',     '10:00-13:00'),
    (5, 1, 4, 'Even', 2026, 'B', 'Room 103', 'Tue-Thu', '10:00-11:30'),
    (6, 5, 5, 'Odd',  2026, 'A', 'Room 301', 'Mon-Wed', '10:00-11:30'),
    (7, 6, 5, 'Odd',  2026, 'A', 'Room 302', 'Tue-Thu', '14:00-15:30'),
    (8, 7, 7, 'Odd',  2026, 'A', 'Room 401', 'Mon-Wed', '10:00-11:30'),
    (9, 8, 7, 'Even', 2026, 'A', 'Room 402', 'Tue-Thu', '10:00-11:30');

-- ─── attendance ────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO attendance (course_section_id, faculty_id, date, total_students, present_count, notes) VALUES
    (1, 3, '2026-01-06', 45, 42, NULL),
    (1, 3, '2026-01-08', 45, 40, '2 on approved leave'),
    (1, 3, '2026-01-13', 45, 43, NULL),
    (2, 3, '2026-01-07', 38, 35, NULL),
    (2, 3, '2026-01-09', 38, 36, NULL),
    (6, 5, '2026-01-06', 42, 39, NULL),
    (8, 7, '2026-01-06', 50, 47, NULL);

-- ─── timetable_entries ─────────────────────────────────────────────────────
INSERT OR IGNORE INTO timetable_entries (department_id, course_section_id, day_of_week, start_time, end_time, room) VALUES
    (1, 1, 1, '10:00:00', '11:30:00', 'Room 101'),
    (1, 1, 3, '10:00:00', '11:30:00', 'Room 101'),
    (1, 2, 2, '14:00:00', '15:30:00', 'Room 102'),
    (1, 2, 4, '14:00:00', '15:30:00', 'Room 102'),
    (1, 3, 1, '10:00:00', '11:30:00', 'Room 201'),
    (1, 3, 3, '10:00:00', '11:30:00', 'Room 201'),
    (1, 4, 5, '10:00:00', '13:00:00', 'Lab 1'),
    (2, 6, 1, '10:00:00', '11:30:00', 'Room 301'),
    (2, 6, 3, '10:00:00', '11:30:00', 'Room 301'),
    (2, 7, 2, '14:00:00', '15:30:00', 'Room 302'),
    (2, 7, 4, '14:00:00', '15:30:00', 'Room 302'),
    (3, 8, 1, '10:00:00', '11:30:00', 'Room 401'),
    (3, 8, 3, '10:00:00', '11:30:00', 'Room 401');

-- ─── documents ─────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO documents (user_id, title, description, filename, original_name, file_size, mime_type, category, course_id, department_id) VALUES
    (3, 'Data Structures Syllabus',       'Course syllabus for CS101 - Odd 2026',    'cs101_syllabus.pdf', 'CS101_Syllabus_2026.pdf', 245760,  'application/pdf', 'syllabus',  1, 1),
    (3, 'DB Systems Lab Manual',          'Lab exercises for Database Systems',       'cs204_lab.pdf',      'CS204_Lab_Manual.pdf',    1572864, 'application/pdf', 'notes',     2, 1),
    (5, 'Digital Circuits Notes',         'Lecture notes for Unit 1-3',               'ec150_notes.pdf',    'EC150_Notes_U1-3.pdf',   524288,  'application/pdf', 'notes',     5, 2),
    (7, 'Engineering Mechanics Assignment 1', 'First assignment - Due Feb 15',         'me101_a1.pdf',       'ME101_Assignment1.pdf',  102400,  'application/pdf', 'assignment',7, 3);
