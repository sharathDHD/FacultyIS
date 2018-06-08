from flask import Blueprint, g, jsonify, request

from auth import enforce_department_scope, jwt_required
from models import Course, CourseSection, Department, TimetableEntry, User, db
from ratelimit import rate_limited

timetable_bp = Blueprint("timetable", __name__, url_prefix="/api/timetable")


@timetable_bp.route("/weekly", methods=["GET"])
@jwt_required(roles=("hod", "admin", "faculty"))
@rate_limited
def weekly_timetable():
    """
    Weekly timetable for a department — returns entries grouped by day
    for easy rendering into a weekly grid view.

    Authorization:
      - admin  → any department
      - hod    → own department only
      - faculty → own department only
    """
    department_id = request.args.get("department_id", type=int)

    if not department_id:
        return jsonify({"error": "department_id parameter is required"}), 400

    # ── Department scoping ───────────────────────────────
    result = enforce_department_scope(department_id)
    if isinstance(result, tuple):
        return result  # 403 response
    department_id = result

    day_names = {1: "Monday", 2: "Tuesday", 3: "Wednesday", 4: "Thursday", 5: "Friday", 6: "Saturday"}

    entries = (
        db.session.query(
            TimetableEntry.id,
            TimetableEntry.day_of_week,
            TimetableEntry.start_time,
            TimetableEntry.end_time,
            TimetableEntry.room,
            Course.code.label("course_code"),
            Course.name.label("course_name"),
            CourseSection.section_number,
            User.name.label("faculty_name"),
        )
        .outerjoin(CourseSection, TimetableEntry.course_section_id == CourseSection.id)
        .outerjoin(Course, CourseSection.course_id == Course.id)
        .outerjoin(User, CourseSection.faculty_id == User.id)
        .filter(TimetableEntry.department_id == department_id)
        .order_by(TimetableEntry.day_of_week, TimetableEntry.start_time)
        .all()
    )

    by_day = {}
    for e in entries:
        day_label = day_names.get(e.day_of_week, f"Day {e.day_of_week}")
        by_day.setdefault(day_label, []).append({
            "id": e.id,
            "start_time": str(e.start_time)[:5] if e.start_time else None,
            "end_time": str(e.end_time)[:5] if e.end_time else None,
            "room": e.room,
            "course_code": e.course_code,
            "course_name": e.course_name,
            "section_number": e.section_number,
            "faculty_name": e.faculty_name,
        })

    return jsonify(by_day)
