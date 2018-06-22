from flask import Blueprint, g, jsonify, request
from sqlalchemy import func

from auth import enforce_department_scope, jwt_required
from models import Attendance, Course, CourseSection, Department, User, db
from ratelimit import rate_limited

attendance_bp = Blueprint("attendance", __name__, url_prefix="/api/attendance")


@attendance_bp.route("/summary", methods=["GET"])
@jwt_required(roles=("hod", "admin", "faculty"))
@rate_limited
def attendance_summary():
    """
    Attendance summary — average attendance rate per course section,
    optionally filtered by department, semester, year, or date range.

    Authorization:
      - admin   → any department (or all if no department_id)
      - hod     → own department only
      - faculty → own sections only (department_id is forced to own dept)
    """
    department_id = request.args.get("department_id", type=int)
    semester = request.args.get("semester", type=str)
    year = request.args.get("year", type=int)
    from_date = request.args.get("from_date", type=str)
    to_date = request.args.get("to_date", type=str)

    caller_role = g.jwt_payload.get("role", "")

    # ── Department scoping ───────────────────────────────
    if caller_role in ("hod", "faculty"):
        result = enforce_department_scope(department_id)
        if isinstance(result, tuple):
            return result  # 403 response
        department_id = result

    query = (
        db.session.query(
            Course.code.label("course_code"),
            Course.name.label("course_name"),
            CourseSection.section_number,
            CourseSection.semester,
            CourseSection.year,
            func.count(Attendance.id).label("sessions_recorded"),
            func.sum(Attendance.total_students).label("total_enrolled"),
            func.sum(Attendance.present_count).label("total_present"),
        )
        .join(CourseSection, Attendance.course_section_id == CourseSection.id)
        .join(Course, CourseSection.course_id == Course.id)
    )

    if department_id:
        query = query.filter(Course.department_id == department_id)
    if semester:
        query = query.filter(CourseSection.semester == semester)
    if year:
        query = query.filter(CourseSection.year == year)
    if from_date:
        query = query.filter(Attendance.date >= from_date)
    if to_date:
        query = query.filter(Attendance.date <= to_date)

    # Filter by faculty's own sections if role is faculty
    if caller_role == "faculty":
        query = query.filter(CourseSection.faculty_id == g.jwt_payload.get("sub"))

    query = query.group_by(
        Course.id, CourseSection.id
    ).order_by(Course.code, CourseSection.year.desc())

    rows = query.all()

    results = []
    for row in rows:
        total_enrolled = row.total_enrolled or 0
        total_present = row.total_present or 0
        avg_rate = round(total_present / total_enrolled * 100, 1) if total_enrolled > 0 else 0

        results.append({
            "course_code": row.course_code,
            "course_name": row.course_name,
            "section_number": row.section_number,
            "semester": row.semester,
            "year": row.year,
            "sessions_recorded": row.sessions_recorded,
            "avg_attendance_rate": avg_rate,
        })

    return jsonify(results)
