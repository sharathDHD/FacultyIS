import logging
import uuid

from flask import Flask, g, has_request_context, jsonify, request
from sqlalchemy import event

from config import Config, validate_config
from models import db
from routes.attendance import attendance_bp
from routes.documents import documents_bp
from routes.exports import exports_bp
from routes.health import health_bp
from routes.reports import reports_bp
from routes.timetable import timetable_bp


def create_app(config_object: type = Config) -> Flask:
    app = Flask(__name__)
    app.config.from_object(config_object)

    # ── Startup configuration validation ────────────────────
    config_errors = validate_config()
    if config_errors:
        for err in config_errors:
            logging.critical("CONFIG ERROR: %s", err)
        raise RuntimeError(
            f"Configuration validation failed: {'; '.join(config_errors)}. "
            "Fix .env and restart. Set DEV_MODE=1 for local dev."
        )

    db.init_app(app)

    # ── SQLite: enforce foreign keys ─────────────────────────
    # SQLite doesn't enforce FK constraints by default.
    # This event listener enables them on every new connection.
    if Config.is_sqlite():
        with app.app_context():
            @event.listens_for(db.engine, "connect")
            def _set_sqlite_pragma(dbapi_conn, _conn_record):
                cursor = dbapi_conn.cursor()
                cursor.execute("PRAGMA foreign_keys=ON")
                cursor.execute("PRAGMA journal_mode=WAL")
                cursor.execute("PRAGMA busy_timeout=5000")
                cursor.close()

    # ── Structured logging ────────────────────────────────────
    log_level = app.config.get("LOG_LEVEL", "WARNING")
    logging.basicConfig(
        level=getattr(logging, log_level, logging.WARNING),
        format="%(asctime)s [%(levelname)s] %(name)s req=%(request_id)s user=%(user_id)s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    old_factory = logging.getLogRecordFactory()

    def record_factory(*args, **kwargs):
        record = old_factory(*args, **kwargs)
        if has_request_context():
            record.request_id = getattr(g, "request_id", "-")
            record.user_id = getattr(g, "jwt_payload", {}).get("sub", "-")
        else:
            record.request_id = "-"
            record.user_id = "-"
        return record

    logging.setLogRecordFactory(record_factory)

    # ── Request ID middleware ─────────────────────────────────
    @app.before_request
    def set_request_id():
        g.request_id = request.headers.get("X-Request-ID", uuid.uuid4().hex[:12])

    app.register_blueprint(health_bp)
    app.register_blueprint(reports_bp)
    app.register_blueprint(exports_bp)
    app.register_blueprint(attendance_bp)
    app.register_blueprint(timetable_bp)
    app.register_blueprint(documents_bp)

    @app.errorhandler(404)
    def not_found(_e):
        return jsonify({"error": "not_found"}), 404

    @app.errorhandler(500)
    def server_error(_e):
        return jsonify({"error": "internal_error"}), 500

    return app


# For `flask run` / gunicorn
app = create_app()

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=False)
