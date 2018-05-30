import os


class Config:
    # Same shared secret PHP uses to sign JWTs — must match exactly.
    JWT_SECRET = os.environ.get("JWT_SECRET", "")
    JWT_ALGORITHM = "HS256"
    JWT_TTL_SECONDS = int(os.environ.get("JWT_TTL_SECONDS", "3600"))

    # SQLAlchemy connection string — SQLite file at data/facultyis.sqlite3
    _PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    _DEFAULT_SQLITE = f"sqlite:///{os.path.join(_PROJECT_ROOT, 'data', 'facultyis.sqlite3')}"
    SQLALCHEMY_DATABASE_URI = os.environ.get("DATABASE_URL", _DEFAULT_SQLITE)
    SQLALCHEMY_TRACK_MODIFICATIONS = False

    # In-process rate limit window (requests per minute per key).
    RATE_LIMIT_PER_MINUTE = int(os.environ.get("RATE_LIMIT_PER_MINUTE", "30"))

    # Logging — LOG_LEVEL controls structured log verbosity (WARNING by default;
    # set to INFO or DEBUG for diagnostics). ACCESS_LOG enables Gunicorn
    # access logging (off by default for clean console output).
    LOG_LEVEL = os.environ.get("LOG_LEVEL", "WARNING")
    ACCESS_LOG = os.environ.get("ACCESS_LOG", "false").lower() in ("true", "1", "yes")

    # DEV_MODE: allows the insecure default JWT_SECRET for local development.
    # Must be explicitly set to "1" — never inferred.
    DEV_MODE = os.environ.get("DEV_MODE", "0") in ("1", "true", "yes")

    @classmethod
    def is_sqlite(cls) -> bool:
        """Check if the configured database is SQLite."""
        return cls.SQLALCHEMY_DATABASE_URI.startswith("sqlite")


def validate_config():
    """
    Validate that required configuration values are present and safe.
    Returns a list of error messages (empty = all good).

    Policy:
      - Missing JWT_SECRET → always fatal (even in DEV_MODE)
      - Insecure default JWT_SECRET → fatal unless DEV_MODE=1
      - Short JWT_SECRET → always fatal
    """
    errors = []

    if not Config.JWT_SECRET:
        errors.append("JWT_SECRET is missing — set it in .env before starting")

    if Config.JWT_SECRET == "dev-insecure-please-change-me":
        if not Config.DEV_MODE:
            errors.append(
                "JWT_SECRET is still the dev default — "
                "set DEV_MODE=1 for local dev, or generate a strong secret for production"
            )

    if Config.JWT_SECRET and len(Config.JWT_SECRET) < 16:
        errors.append("JWT_SECRET is too short (< 16 chars) — use at least 32 characters")

    return errors
