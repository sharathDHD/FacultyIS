"""
In-process rate limiter for FacultyIS.

Uses a per-key sliding window (deque of timestamps) to enforce
requests-per-minute limits. Sufficient and correct for the
single-Gunicorn-worker deployment model.

If the deployment is later scaled to multiple workers, replace
this module with a Redis-backed implementation (INCR + EXPIRE).
"""

import logging
import time
from collections import defaultdict, deque
from functools import wraps

from flask import current_app, g, jsonify, request

logger = logging.getLogger("facultyis.ratelimit")

_hits: dict[str, deque] = defaultdict(deque)


def _check_inprocess(key: str, limit: int) -> bool:
    """Sliding-window rate limit check — one deque per key."""
    now = time.time()
    window = _hits[key]
    while window and now - window[0] > 60:
        window.popleft()
    if len(window) >= limit:
        return False
    window.append(now)
    return True


def rate_limited(fn):
    """
    Rate limiter decorator — in-process, keyed by JWT subject
    (falls back to IP address for unauthenticated requests).
    """
    @wraps(fn)
    def wrapper(*args, **kwargs):
        limit = current_app.config["RATE_LIMIT_PER_MINUTE"]
        key = getattr(g, "jwt_payload", {}).get("sub") if hasattr(g, "jwt_payload") else None
        key = str(key) if key is not None else (request.remote_addr or "unknown")

        if not _check_inprocess(key, limit):
            return jsonify({"error": "rate_limited", "retry_after_seconds": 60}), 429

        return fn(*args, **kwargs)

    return wrapper
