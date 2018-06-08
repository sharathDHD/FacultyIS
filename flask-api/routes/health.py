from flask import Blueprint, jsonify

from models import db

health_bp = Blueprint("health", __name__, url_prefix="/api")


def _check_db() -> bool:
    """Check database connectivity — returns True if reachable."""
    try:
        db.session.execute(db.text("SELECT 1"))
        return True
    except Exception:
        return False


@health_bp.route("/health", methods=["GET"])
def health():
    """
    Full health check. Checks DB reachability.
    Returns 200 if DB is ok, 503 if down.
    """
    db_ok = _check_db()
    status = "ok" if db_ok else "degraded"
    return jsonify({
        "status": status,
        "database": db_ok,
    }), (200 if db_ok else 503)


@health_bp.route("/health/live", methods=["GET"])
def liveness():
    """
    Liveness probe — the process is alive and can serve requests.
    No dependency checks: if this fails, the container should be restarted.
    Used by orchestrators (Podman/K8s) to detect deadlocked or crashed processes.
    """
    return jsonify({"status": "alive"}), 200


@health_bp.route("/health/ready", methods=["GET"])
def readiness():
    """
    Readiness probe — the service is fully initialized and can handle traffic.
    Checks DB (required). Returns 200 only when the service can actually
    serve production requests correctly.
    """
    db_ok = _check_db()
    ready = db_ok
    return jsonify({
        "status": "ready" if ready else "not_ready",
        "database": db_ok,
    }), (200 if ready else 503)
