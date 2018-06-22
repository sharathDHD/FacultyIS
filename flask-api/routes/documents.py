from flask import Blueprint, g, jsonify, request
from sqlalchemy import func

from auth import enforce_department_scope, jwt_required
from models import Document, Course, Department, User, db
from ratelimit import rate_limited

documents_bp = Blueprint("documents", __name__, url_prefix="/api/documents")


@documents_bp.route("/stats", methods=["GET"])
@jwt_required(roles=("hod", "admin"))
@rate_limited
def document_stats():
    """
    Document statistics — count and total size by category and department.
    Useful for the dashboard and storage planning.

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

    query = (
        db.session.query(
            Document.category,
            func.count(Document.id).label("count"),
            func.sum(Document.file_size).label("total_size"),
        )
    )

    if department_id:
        query = query.filter(Document.department_id == department_id)

    query = query.group_by(Document.category)
    rows = query.all()

    by_category = {}
    total_count = 0
    total_size = 0
    for row in rows:
        size = row.total_size or 0
        by_category[row.category] = {
            "count": row.count,
            "total_size_bytes": size,
            "total_size_mb": round(size / (1024 * 1024), 2),
        }
        total_count += row.count
        total_size += size

    return jsonify({
        "by_category": by_category,
        "total_count": total_count,
        "total_size_mb": round(total_size / (1024 * 1024), 2),
    })


@documents_bp.route("/list", methods=["GET"])
@jwt_required(roles=("hod", "admin", "faculty"))
@rate_limited
def document_list():
    """
    List documents with metadata (does NOT serve file content —
    that's handled by PHP's download-file.php for security).

    Authorization:
      - admin   → any department
      - hod     → own department only
      - faculty → own documents + own department's documents
    """
    category = request.args.get("category", type=str)
    course_id = request.args.get("course_id", type=int)
    department_id = request.args.get("department_id", type=int)
    limit = min(request.args.get("limit", 50, type=int), 200)
    offset = request.args.get("offset", 0, type=int)

    # ── Department scoping ───────────────────────────────
    caller_role = g.jwt_payload.get("role", "")
    if caller_role in ("hod", "faculty"):
        result = enforce_department_scope(department_id)
        if isinstance(result, tuple):
            return result  # 403 response
        department_id = result

    query = (
        db.session.query(
            Document.id,
            Document.title,
            Document.description,
            Document.category,
            Document.original_name,
            Document.file_size,
            Document.mime_type,
            Document.created_at,
            User.name.label("uploader_name"),
            Course.code.label("course_code"),
            Department.name.label("department_name"),
        )
        .join(User, Document.user_id == User.id)
        .outerjoin(Course, Document.course_id == Course.id)
        .outerjoin(Department, Document.department_id == Department.id)
    )

    if category:
        query = query.filter(Document.category == category)
    if course_id:
        query = query.filter(Document.course_id == course_id)
    if department_id:
        query = query.filter(Document.department_id == department_id)

    # Faculty can only see their own + department docs
    if caller_role == "faculty":
        fac_dept = g.jwt_payload.get("department_id")
        query = query.filter(
            (Document.user_id == g.jwt_payload.get("sub")) |
            (Document.department_id == fac_dept)
        )

    query = query.order_by(Document.created_at.desc())
    total = query.count()
    rows = query.offset(offset).limit(limit).all()

    return jsonify({
        "total": total,
        "offset": offset,
        "limit": limit,
        "documents": [
            {
                "id": r.id,
                "title": r.title,
                "description": r.description,
                "category": r.category,
                "original_name": r.original_name,
                "file_size": r.file_size,
                "mime_type": r.mime_type,
                "created_at": str(r.created_at),
                "uploader_name": r.uploader_name,
                "course_code": r.course_code,
                "department_name": r.department_name,
            }
            for r in rows
        ],
    })
