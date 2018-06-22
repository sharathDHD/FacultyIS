import csv
import io
import json

from flask import Blueprint, Response, jsonify, request, stream_with_context

from auth import enforce_department_scope, jwt_required
from models import Department, User, db
from ratelimit import rate_limited

exports_bp = Blueprint("exports", __name__, url_prefix="/api/exports")

COLUMNS = [
    ("User ID", "user_id"),
    ("Name", "name"),
    ("Email", "email"),
    ("Phone", "phone"),
    ("Department", "department"),
]


def _faculty_query(department_id: int | None):
    """Build the SQLAlchemy query for faculty export data."""
    query = (
        db.session.query(
            User.user_id,
            User.name,
            User.email,
            User.phone,
            Department.name.label("department"),
        )
        .join(Department, User.department_id == Department.id, isouter=True)
        .filter(User.role == "faculty")
    )
    if department_id:
        query = query.filter(Department.id == department_id)
    return query.order_by(Department.name, User.name)


@exports_bp.route("/faculty.csv", methods=["GET"])
@jwt_required(roles=("hod", "admin"))
@rate_limited
def export_faculty_csv():
    """
    Stream faculty data as CSV using Python stdlib csv module.

    Uses server-side cursor streaming to avoid loading all rows
    into memory at once. Each row is yielded as a CSV line
    immediately, keeping memory usage constant regardless of
    result set size.

    Authorization:
      - admin → any department (or all if no department_id)
      - hod   → own department only
    """
    department_id = request.args.get("department_id", type=int)

    # ── Department scoping ───────────────────────────────
    result = enforce_department_scope(department_id)
    if isinstance(result, tuple):
        return result  # 403 response
    department_id = result

    def generate():
        buffer = io.StringIO()
        writer = csv.writer(buffer)
        # Write header
        writer.writerow([col[0] for col in COLUMNS])
        yield buffer.getvalue()
        buffer.seek(0)
        buffer.truncate(0)

        # Stream rows from the database cursor
        rows = _faculty_query(department_id).yield_per(100)
        for r in rows:
            writer.writerow([r.user_id, r.name, r.email, r.phone, r.department])
            yield buffer.getvalue()
            buffer.seek(0)
            buffer.truncate(0)

    return Response(
        stream_with_context(generate()),
        mimetype="text/csv",
        headers={"Content-Disposition": "attachment; filename=faculty_export.csv"},
    )


@exports_bp.route("/faculty.json", methods=["GET"])
@jwt_required(roles=("hod", "admin"))
@rate_limited
def export_faculty_json():
    """
    Return faculty data as JSON for browser-side XLSX generation.

    The Python server stays lightweight — the frontend uses a
    vendored XLSX library (SheetJS) to build the spreadsheet
    client-side.

    Column metadata is included so the browser knows the desired
    header labels and column order.

    Authorization:
      - admin → any department (or all if no department_id)
      - hod   → own department only
    """
    department_id = request.args.get("department_id", type=int)

    # ── Department scoping ───────────────────────────────
    result = enforce_department_scope(department_id)
    if isinstance(result, tuple):
        return result  # 403 response
    department_id = result

    rows = _faculty_query(department_id).all()

    return jsonify({
        "columns": [col[0] for col in COLUMNS],
        "data": [
            {
                "user_id": r.user_id,
                "name": r.name,
                "email": r.email,
                "phone": r.phone,
                "department": r.department,
            }
            for r in rows
        ],
    })
