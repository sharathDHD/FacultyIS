# Version: v2.15
from functools import wraps

import jwt
from flask import current_app, g, jsonify, request


def _extract_token() -> str | None:
    header = request.headers.get("Authorization", "")
    if header.startswith("Bearer "):
        return header[len("Bearer "):].strip()
    return None


def jwt_required(roles: tuple[str, ...] | None = None):
    """
    Decorator that verifies the Bearer JWT on the request per architecture
    doc section 5.2 ("Flask does not maintain session state; it relies
    entirely on JWT validation for each incoming request").

    Optionally restricts access to specific roles, e.g.
    @jwt_required(roles=("hod", "admin"))

    SECURITY: After decoding the JWT, we verify that the auth_version
    claim matches the current value in the database. If a user's password
    was reset or their role was changed, auth_version is incremented in
    the DB, invalidating all previously issued JWTs. This prevents stale
    JWTs from being accepted after a credential/privilege change.
    """

    def decorator(fn):
        @wraps(fn)
        def wrapper(*args, **kwargs):
            token = _extract_token()
            if not token:
                return jsonify({"error": "missing_token"}), 401

            try:
                payload = jwt.decode(
                    token,
                    current_app.config["JWT_SECRET"],
                    algorithms=[current_app.config["JWT_ALGORITHM"]],
                )
            except jwt.ExpiredSignatureError:
                return jsonify({"error": "token_expired"}), 401
            except jwt.InvalidTokenError:
                return jsonify({"error": "invalid_token"}), 401

            if roles and payload.get("role") not in roles:
                return jsonify({"error": "forbidden", "detail": "insufficient role"}), 403

            # ── auth_version revocation check ──────────────────────
            # auth_version is mandatory in all JWTs issued by this system.
            # A missing claim means the JWT was issued by an older version
            # and must be rejected. We compare against the current database
            # value — a mismatch means credentials or role changed after
            # this JWT was issued.
            jwt_auth_version = payload.get("auth_version")
            if jwt_auth_version is None:
                return jsonify({"error": "invalid_token", "detail": "missing auth_version"}), 401

            from models import User, db
            user_id = payload.get("sub")
            if user_id is not None:
                row = db.session.query(
                    User.auth_version, User.role, User.department_id
                ).filter(User.id == user_id).first()
                # If the user no longer exists, reject the token.
                # A token for a deleted account should not remain
                # cryptographically valid until expiration.
                if row is None:
                    return jsonify({"error": "invalid_token", "detail": "user not found"}), 401
                current_version, current_role, current_dept = row
                if current_version != jwt_auth_version:
                    return jsonify({"error": "token_revoked", "detail": "credentials changed"}), 401
                # ── JWT claim consistency check ────────────────────
                # Verify that the JWT's role and department_id match the
                # current database values. This catches the case where a
                # direct DB edit changed role/department without incrementing
                # auth_version. While all application mutation paths do
                # increment auth_version, this adds defense-in-depth against
                # manual DB modifications.
                if payload.get("role") != current_role:
                    return jsonify({"error": "token_revoked", "detail": "role changed"}), 401
                if payload.get("department_id") != current_dept:
                    return jsonify({"error": "token_revoked", "detail": "department changed"}), 401

            g.jwt_payload = payload
            return fn(*args, **kwargs)

        return wrapper

    return decorator


def enforce_department_scope(department_id: int | None) -> int | None:
    """
    Enforce department scoping based on the caller's role.

    Policy:
      - admin  → can query any department (pass-through)
      - hod    → can only query their own department (override to JWT department_id)
      - faculty → can only query their own department (override to JWT department_id)

    Returns the (possibly overridden) department_id to use in queries.
    Returns None if the caller is admin and no department_id was supplied.
    Raises a 403 response if a non-admin explicitly requests a different department.
    """
    caller_role = g.jwt_payload.get("role", "")
    caller_dept = g.jwt_payload.get("department_id")

    if caller_role == "admin":
        # Admin can query any department — no override
        return department_id

    # HOD and faculty are restricted to their own department
    if department_id is not None and department_id != caller_dept:
        from flask import jsonify as _jsonify
        from flask import abort
        return _jsonify({"error": "forbidden", "detail": "can only access own department"}), 403

    # Force to caller's department
    return caller_dept
