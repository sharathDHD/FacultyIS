from flask import Blueprint, g, jsonify, request
from sqlalchemy import func

from auth import enforce_department_scope, jwt_required
from models import Course, CourseSection, Department, User, db
from ratelimit import rate_limited

reports_bp = Blueprint("reports", __name__, url_prefix="/api/reports")


@reports_bp.route("/workload", methods=["GET"])
@jwt_required(roles=("hod", "admin"))
@rate_limited
def workload_report():
    """
    Department-wise faculty workload: number of course sections each
    faculty member is teaching, grouped by department and optionally
    filtered by semester/year.

    This is the exact example from architecture doc section 4.2
    ("Generate Department Workload Report") and 7 ("requires joins,
    averages, and grouping").

    Authorization:
      - admin → any department (or all if no department_id)
      - hod   → own department only
    """
    department_id = request.args.get("department_id", type=int)
    semester = request.args.get("semester", type=str)
    year = request.args.get("year", type=int)

    # ── Department scoping ───────────────────────────────
    result = enforce_department_scope(department_id)
    if isinstance(result, tuple):
        return result  # 403 response
    department_id = result

    query = (
        db.session.query(
            Department.name.label("department"),
            User.name.label("faculty_name"),
            User.user_id.label("faculty_user_id"),
            func.count(CourseSection.id).label("section_count"),
        )
        .join(User, User.department_id == Department.id)
        .outerjoin(CourseSection, CourseSection.faculty_id == User.id)
        .filter(User.role == "faculty")
    )

    if department_id:
        query = query.filter(Department.id == department_id)
    if semester:
        query = query.filter(
            (CourseSection.semester == semester) | (CourseSection.id.is_(None))
        )
    if year:
        query = query.filter((CourseSection.year == year) | (CourseSection.id.is_(None)))

    query = query.group_by(Department.name, User.id, User.name, User.user_id)
    query = query.order_by(Department.name, func.count(CourseSection.id).desc())

    rows = query.all()

    by_department: dict[str, list[dict]] = {}
    for row in rows:
        by_department.setdefault(row.department, []).append(
            {
                "faculty_name": row.faculty_name,
                "faculty_user_id": row.faculty_user_id,
                "section_count": row.section_count,
            }
        )

    department_averages = {
        dept: round(sum(f["section_count"] for f in faculty) / len(faculty), 2)
        for dept, faculty in by_department.items()
    }

    return jsonify(
        {
            "filters": {"department_id": department_id, "semester": semester, "year": year},
            "departments": by_department,
            "department_averages": department_averages,
        }
    )


@reports_bp.route("/course-catalog", methods=["GET"])
@jwt_required(roles=("hod", "admin", "faculty"))
@rate_limited
def course_catalog():
    """
    Institution-wide course catalog.

    All authenticated users can view the full catalog — this is
    intentionally not department-scoped because course catalog
    information is not sensitive and faculty routinely need to
    see courses across departments for advising and curriculum
    planning.
    """
    courses = (
        db.session.query(Course.code, Course.name, Department.name.label("department"))
        .join(Department, Course.department_id == Department.id)
        .order_by(Department.name, Course.code)
        .all()
    )
    return jsonify(
        [
            {"code": c.code, "name": c.name, "department": c.department}
            for c in courses
        ]
    )
