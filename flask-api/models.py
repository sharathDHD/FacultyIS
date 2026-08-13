from flask_sqlalchemy import SQLAlchemy

db = SQLAlchemy()


class Department(db.Model):
    __tablename__ = "departments"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    name = db.Column(db.String(120), nullable=False, unique=True)
    is_active = db.Column(db.Integer, nullable=False, default=1)


class User(db.Model):
    __tablename__ = "users"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    user_id = db.Column(db.String(50), nullable=False, unique=True)
    name = db.Column(db.String(120), nullable=False)
    email = db.Column(db.String(150), nullable=False, unique=True)
    phone = db.Column(db.String(20))
    gender = db.Column(db.String(10))       # CHECK constraint in DDL
    dob = db.Column(db.String(20))          # ISO 8601 date string (SQLite)
    role = db.Column(db.String(10), nullable=False, default="faculty")  # CHECK in DDL
    department_id = db.Column(db.Integer, db.ForeignKey("departments.id"))
    auth_version = db.Column(db.Integer, nullable=False, default=0)
    # password_hash intentionally not read/written by Flask — auth is a PHP
    # responsibility per the architecture doc's division of concerns.


class Course(db.Model):
    __tablename__ = "courses"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    code = db.Column(db.String(20), nullable=False, unique=True)
    name = db.Column(db.String(150), nullable=False)
    department_id = db.Column(db.Integer, db.ForeignKey("departments.id"), nullable=False)
    credits = db.Column(db.Integer, default=3)
    is_active = db.Column(db.Integer, nullable=False, default=1)


class CourseSection(db.Model):
    __tablename__ = "course_sections"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    course_id = db.Column(db.Integer, db.ForeignKey("courses.id"), nullable=False)
    faculty_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    semester = db.Column(db.String(20), nullable=False)
    year = db.Column(db.Integer, nullable=False)
    section_number = db.Column(db.String(10), default="A")
    room = db.Column(db.String(30))
    schedule_day = db.Column(db.String(20))
    schedule_time = db.Column(db.String(30))


class Attendance(db.Model):
    __tablename__ = "attendance"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    course_section_id = db.Column(db.Integer, db.ForeignKey("course_sections.id"), nullable=False)
    faculty_id = db.Column(db.Integer, db.ForeignKey("users.id"))  # nullable: ON DELETE SET NULL
    date = db.Column(db.String(20), nullable=False)  # ISO 8601 date string
    total_students = db.Column(db.Integer, default=0)
    present_count = db.Column(db.Integer, default=0)
    notes = db.Column(db.Text)


class AttendanceRecord(db.Model):
    __tablename__ = "attendance_records"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    attendance_id = db.Column(db.Integer, db.ForeignKey("attendance.id"), nullable=False)
    student_roll = db.Column(db.String(30), nullable=False)
    student_name = db.Column(db.String(120), nullable=False)
    status = db.Column(db.String(10), nullable=False, default="present")  # CHECK in DDL


class TimetableEntry(db.Model):
    __tablename__ = "timetable_entries"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    department_id = db.Column(db.Integer, db.ForeignKey("departments.id"), nullable=False)
    course_section_id = db.Column(db.Integer, db.ForeignKey("course_sections.id"))
    day_of_week = db.Column(db.Integer, nullable=False)  # 1=Mon … 5=Fri
    start_time = db.Column(db.String(20), nullable=False)  # HH:MM:SS
    end_time = db.Column(db.String(20), nullable=False)    # HH:MM:SS
    room = db.Column(db.String(30))


class Document(db.Model):
    __tablename__ = "documents"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    user_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    title = db.Column(db.String(200), nullable=False)
    description = db.Column(db.Text)
    filename = db.Column(db.String(255), nullable=False)
    original_name = db.Column(db.String(255), nullable=False)
    file_size = db.Column(db.Integer, default=0)
    mime_type = db.Column(db.String(100), nullable=False)
    category = db.Column(db.String(20), nullable=False, default="other")  # CHECK in DDL
    course_id = db.Column(db.Integer, db.ForeignKey("courses.id"))
    department_id = db.Column(db.Integer, db.ForeignKey("departments.id"))


class ReportCache(db.Model):
    __tablename__ = "report_cache"
    cache_key = db.Column(db.String(150), primary_key=True)
    payload = db.Column(db.Text, nullable=False)  # JSON stored as TEXT


class AuditLog(db.Model):
    __tablename__ = "audit_log"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    user_id = db.Column(db.Integer, db.ForeignKey("users.id"))
    action = db.Column(db.String(100), nullable=False)
    entity_type = db.Column(db.String(60))
    entity_id = db.Column(db.Integer)
    detail = db.Column(db.Text)
    old_values = db.Column(db.Text)  # JSON stored as TEXT
    new_values = db.Column(db.Text)  # JSON stored as TEXT


class PasswordReset(db.Model):
    __tablename__ = "password_resets"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    user_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    token = db.Column(db.String(64), nullable=False, unique=True)
    expires_at = db.Column(db.String(30), nullable=False)  # ISO timestamp
    used_at = db.Column(db.String(30))


# RefreshToken model removed — PHP is the auth authority;
# Flask only validates JWTs. The refresh_tokens table is unused.
